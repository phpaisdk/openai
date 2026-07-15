<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Live;

use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Live\AudioDelta;
use AiSdk\Live\Contracts\LiveSessionDriverInterface;
use AiSdk\Live\Contracts\TransportConnectionInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\Interrupted;
use AiSdk\Live\LiveClosed;
use AiSdk\Live\LiveError;
use AiSdk\Live\LiveEvent;
use AiSdk\Live\LiveOperation;
use AiSdk\Live\LiveRequest;
use AiSdk\Live\ProviderEvent;
use AiSdk\Live\ResponseCompleted;
use AiSdk\Live\SpeechStarted;
use AiSdk\Live\SpeechStopped;
use AiSdk\Live\TextDelta;
use AiSdk\Live\ToolCallEvent;
use AiSdk\Live\TranscriptCompleted;
use AiSdk\Live\TranscriptDelta;
use AiSdk\Live\TranscriptSource;
use AiSdk\Live\TransportFrame;
use AiSdk\Live\TransportFrameType;
use AiSdk\Live\UsageEvent;
use AiSdk\Live\WebSocketEndpoint;
use AiSdk\OpenAI\OpenAIOptions;
use AiSdk\Utils\Support\Url;
use JsonException;

/**
 * OpenAI owns the GA Realtime protocol codec. The transport only moves raw
 * WebSocket frames and never needs to understand OpenAI event names.
 */
final class OpenAILiveSessionDriver implements LiveSessionDriverInterface
{
    private TransportConnectionInterface $connection;

    /** @var array<string, true> */
    private array $handledToolCalls = [];

    /** @var array<string, true> */
    private array $pendingToolCalls = [];

    /** @var array<string, true> */
    private array $submittedToolResults = [];

    private bool $toolTurnComplete = false;

    private bool $translationClosing = false;

    public function __construct(
        private readonly string $modelId,
        private readonly OpenAIOptions $options,
        private readonly LiveRequest $request,
        TransportInterface $transport,
        private readonly ?string $callId = null,
    ) {
        $this->validateRequest();

        $endpoint = new WebSocketEndpoint(
            url: $this->webSocketUrl(),
            headers: $this->options->authHeaders(),
        );

        if (! $transport->supports($endpoint)) {
            throw new InvalidArgumentException('The selected transport does not support OpenAI Live WebSocket sessions.');
        }

        $this->connection = $transport->connect($endpoint);
        $this->sendEvent([
            'type' => 'session.update',
            'session' => $this->sessionConfigurationForUpdate(),
        ]);
    }

    public function sendAudio(string $bytes): void
    {
        $this->sendEvent([
            'type' => $this->request->operation === LiveOperation::Translate
                ? 'session.input_audio_buffer.append'
                : 'input_audio_buffer.append',
            'audio' => base64_encode($bytes),
        ]);
    }

    public function sendText(string $text): void
    {
        $this->requireVoice('sendText');

        $this->sendEvent([
            'type' => 'conversation.item.create',
            'item' => [
                'type' => 'message',
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => $text,
                ]],
            ],
        ]);
    }

    public function commitAudio(): void
    {
        if ($this->request->operation === LiveOperation::Translate) {
            throw new InvalidArgumentException('OpenAI translation sessions consume audio continuously and do not support commitAudio().');
        }

        $this->sendEvent(['type' => 'input_audio_buffer.commit']);
    }

    public function clearAudio(): void
    {
        if ($this->request->operation === LiveOperation::Translate) {
            throw new InvalidArgumentException('OpenAI translation sessions do not support clearAudio().');
        }

        $this->sendEvent(['type' => 'input_audio_buffer.clear']);
    }

    public function requestResponse(): void
    {
        $this->requireVoice('requestResponse');
        $this->sendEvent(['type' => 'response.create']);
    }

    public function cancelResponse(): void
    {
        $this->requireVoice('cancelResponse');
        $this->sendEvent(['type' => 'response.cancel']);
    }

    public function sendToolResult(string $callId, mixed $result): void
    {
        $this->requireVoice('sendToolResult');
        $this->sendEvent([
            'type' => 'conversation.item.create',
            'item' => [
                'type' => 'function_call_output',
                'call_id' => $callId,
                'output' => $this->toolOutput($result),
            ],
        ]);
        $this->submittedToolResults[$callId] = true;
        $this->continueAfterToolResults();
    }

    public function events(): iterable
    {
        while (($frame = $this->connection->receive()) !== null) {
            foreach ($this->decodeFrame($frame) as $event) {
                yield $event;
            }
        }
    }

    public function close(): void
    {
        if ($this->connection->isClosed()) {
            return;
        }

        if ($this->request->operation === LiveOperation::Translate) {
            if (! $this->translationClosing) {
                $this->translationClosing = true;
                $this->sendEvent(['type' => 'session.close']);
            }

            return;
        }

        $this->connection->close();
    }

    /** @return list<LiveEvent> */
    private function decodeFrame(TransportFrame $frame): array
    {
        if ($frame->type !== TransportFrameType::Text) {
            return [new ProviderEvent('transport.binary', ['bytes' => base64_encode($frame->payload)])];
        }

        try {
            $payload = json_decode($frame->payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [new ProviderEvent('transport.invalid_json', [
                'payload' => $frame->payload,
                'message' => $exception->getMessage(),
            ])];
        }

        if (! is_array($payload)) {
            return [new ProviderEvent('transport.invalid_event', ['payload' => $payload])];
        }

        $type = is_string($payload['type'] ?? null) ? $payload['type'] : 'unknown';

        if (in_array($type, ['response.output_audio.delta', 'response.audio.delta', 'session.output_audio.delta'], true)) {
            $delta = $payload['delta'] ?? null;
            $bytes = is_string($delta) ? base64_decode($delta, true) : false;

            return $bytes === false
                ? [new ProviderEvent($type, $payload)]
                : [new AudioDelta($bytes)];
        }

        if (in_array($type, ['response.output_text.delta', 'response.text.delta'], true)) {
            return is_string($payload['delta'] ?? null)
                ? [new TextDelta($payload['delta'])]
                : [new ProviderEvent($type, $payload)];
        }

        if (in_array($type, [
            'conversation.item.input_audio_transcription.delta',
            'response.output_audio_transcript.delta',
            'response.audio_transcript.delta',
            'session.output_transcript.delta',
            'session.input_transcript.delta',
        ], true)) {
            return is_string($payload['delta'] ?? null)
                ? [new TranscriptDelta(
                    $payload['delta'],
                    is_string($payload['item_id'] ?? null) ? $payload['item_id'] : null,
                    in_array($type, [
                        'conversation.item.input_audio_transcription.delta',
                        'session.input_transcript.delta',
                    ], true) ? TranscriptSource::Input : TranscriptSource::Output,
                )]
                : [new ProviderEvent($type, $payload)];
        }

        if (in_array($type, [
            'conversation.item.input_audio_transcription.completed',
            'response.output_audio_transcript.done',
            'response.audio_transcript.done',
            'session.output_transcript.done',
            'session.input_transcript.done',
        ], true)) {
            $transcript = $payload['transcript'] ?? $payload['text'] ?? null;

            return is_string($transcript)
                ? [new TranscriptCompleted(
                    $transcript,
                    is_string($payload['item_id'] ?? null) ? $payload['item_id'] : null,
                    in_array($type, [
                        'conversation.item.input_audio_transcription.completed',
                        'session.input_transcript.done',
                    ], true) ? TranscriptSource::Input : TranscriptSource::Output,
                )]
                : [new ProviderEvent($type, $payload)];
        }

        if (in_array($type, ['input_audio_buffer.speech_started', 'session.input_audio_buffer.speech_started'], true)) {
            return [new SpeechStarted(is_int($payload['audio_start_ms'] ?? null) ? $payload['audio_start_ms'] : null)];
        }

        if (in_array($type, ['input_audio_buffer.speech_stopped', 'session.input_audio_buffer.speech_stopped'], true)) {
            return [new SpeechStopped(is_int($payload['audio_end_ms'] ?? null) ? $payload['audio_end_ms'] : null)];
        }

        if (in_array($type, ['response.cancelled', 'output_audio_buffer.cleared'], true)) {
            $responseId = $payload['response_id'] ?? $payload['response']['id'] ?? null;

            return [new Interrupted(is_string($responseId) ? $responseId : null)];
        }

        if ($type === 'error') {
            $error = is_array($payload['error'] ?? null) ? $payload['error'] : $payload;
            $message = $error['message'] ?? 'OpenAI Realtime returned an error.';
            $code = $error['code'] ?? null;

            return [new LiveError(
                is_string($message) ? $message : 'OpenAI Realtime returned an error.',
                is_string($code) ? $code : null,
                $payload,
            )];
        }

        if ($type === 'response.function_call_arguments.done') {
            return $this->handleToolCall(
                callId: $payload['call_id'] ?? null,
                name: $payload['name'] ?? null,
                arguments: $payload['arguments'] ?? null,
            );
        }

        if ($type === 'response.output_item.done' && is_array($payload['item'] ?? null)) {
            $item = $payload['item'];
            if (($item['type'] ?? null) === 'function_call') {
                return $this->handleToolCall(
                    callId: $item['call_id'] ?? null,
                    name: $item['name'] ?? null,
                    arguments: $item['arguments'] ?? null,
                );
            }
        }

        if ($type === 'response.done') {
            $events = $this->toolCallsFromResponseDone($payload);
            $response = is_array($payload['response'] ?? null) ? $payload['response'] : [];
            $responseId = is_string($response['id'] ?? null) ? $response['id'] : null;
            $status = $response['status'] ?? null;

            if ($status === 'cancelled') {
                $events[] = new Interrupted($responseId);
            }

            $usage = is_array($response['usage'] ?? null) ? $this->numericUsage($response['usage']) : [];
            if ($usage !== []) {
                $events[] = new UsageEvent($usage);
            }

            $events[] = new ResponseCompleted($responseId, $response);
            $this->toolTurnComplete = $this->pendingToolCalls !== [];
            $this->continueAfterToolResults();

            return $events;
        }

        if ($type === 'session.closed') {
            if (! $this->connection->isClosed()) {
                $this->connection->close();
            }

            return [new LiveClosed(
                is_int($payload['code'] ?? null) ? $payload['code'] : null,
                is_string($payload['reason'] ?? null) ? $payload['reason'] : null,
            )];
        }

        return [new ProviderEvent($type, $payload)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<LiveEvent>
     */
    private function toolCallsFromResponseDone(array $payload): array
    {
        $output = $payload['response']['output'] ?? null;
        if (! is_array($output)) {
            return [];
        }

        $events = [];
        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'function_call') {
                continue;
            }

            array_push($events, ...$this->handleToolCall(
                callId: $item['call_id'] ?? null,
                name: $item['name'] ?? null,
                arguments: $item['arguments'] ?? null,
            ));
        }

        return $events;
    }

    /**
     * @return list<LiveEvent>
     */
    private function handleToolCall(mixed $callId, mixed $name, mixed $arguments): array
    {
        if (! is_string($callId) || $callId === '') {
            return [];
        }

        $this->pendingToolCalls[$callId] = true;
        if (isset($this->handledToolCalls[$callId])) {
            return [];
        }

        $this->handledToolCalls[$callId] = true;
        $name = is_string($name) ? $name : '';
        $decoded = is_string($arguments) ? json_decode($arguments, true) : $arguments;
        $decoded = is_array($decoded) ? $decoded : [];

        return [new ToolCallEvent($callId, $name, $decoded)];
    }

    /**
     * OpenAI can return multiple function calls in one response. Function
     * outputs may be submitted as they become available, but the next model
     * response must be requested once, after response.done has declared the
     * complete call set and every call has an output.
     */
    private function continueAfterToolResults(): void
    {
        if (! $this->toolTurnComplete || $this->pendingToolCalls === []) {
            return;
        }

        foreach ($this->pendingToolCalls as $callId => $_) {
            if (! isset($this->submittedToolResults[$callId])) {
                return;
            }
        }

        $this->sendEvent(['type' => 'response.create']);
        $this->pendingToolCalls = [];
        $this->submittedToolResults = [];
        $this->toolTurnComplete = false;
    }

    /** @return array<string, mixed> */
    private function sessionConfigurationForUpdate(): array
    {
        $session = OpenAILiveConfiguration::session($this->modelId, $this->options, $this->request);

        // A call_id connection attaches to an already-created WebRTC or SIP
        // session. The model is fixed at creation/accept time and is not
        // selected again on the sideband WebSocket.
        if ($this->callId !== null) {
            unset($session['model']);
        }

        return $session;
    }

    private function webSocketUrl(): string
    {
        $base = preg_replace('#^http://#', 'ws://', $this->options->baseUrl);
        $base = preg_replace('#^https://#', 'wss://', $base ?? $this->options->baseUrl) ?? $this->options->baseUrl;
        if ($this->callId !== null) {
            return Url::joinPath($base, '/realtime') . '?call_id=' . rawurlencode($this->callId);
        }

        $path = $this->request->operation === LiveOperation::Translate
            ? '/realtime/translations'
            : '/realtime';

        return Url::joinPath($base, $path) . '?model=' . rawurlencode($this->modelId);
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array<string, int|float>
     */
    private function numericUsage(array $usage, string $prefix = ''): array
    {
        $normalized = [];

        foreach ($usage as $name => $value) {
            $key = $prefix === '' ? (string) $name : $prefix . '.' . $name;
            if (is_int($value) || is_float($value)) {
                $normalized[$key] = $value;
            } elseif (is_array($value)) {
                $normalized = array_replace($normalized, $this->numericUsage($value, $key));
            }
        }

        return $normalized;
    }

    /** @param array<string, mixed> $event */
    private function sendEvent(array $event): void
    {
        $this->connection->send(TransportFrame::text(json_encode($event, JSON_THROW_ON_ERROR)));
    }

    private function toolOutput(mixed $result): string
    {
        return is_string($result) ? $result : json_encode($result, JSON_THROW_ON_ERROR);
    }

    private function requireVoice(string $method): void
    {
        if ($this->request->operation !== LiveOperation::Voice) {
            throw new InvalidArgumentException("OpenAI {$this->request->operation->value} sessions do not support {$method}().");
        }
    }

    private function validateRequest(): void
    {
        if ($this->request->operation !== LiveOperation::Voice && $this->request->tools !== []) {
            throw new InvalidArgumentException('OpenAI Live tools are supported only by voice-agent sessions.');
        }

        if ($this->request->operation !== LiveOperation::Voice && isset($this->request->options['instructions'])) {
            throw new InvalidArgumentException("OpenAI {$this->request->operation->value} sessions do not support instructions().");
        }
    }
}
