<?php

declare(strict_types=1);

use AiSdk\Contracts\LiveProviderInterface;
use AiSdk\Generate;
use AiSdk\Live;
use AiSdk\Live\AudioDelta;
use AiSdk\Live\LiveClosed;
use AiSdk\Live\LiveError;
use AiSdk\Live\ResponseCompleted;
use AiSdk\Live\SpeechStarted;
use AiSdk\Live\TextDelta;
use AiSdk\Live\ToolCallEvent;
use AiSdk\Live\ToolResultEvent;
use AiSdk\Live\TranscriptCompleted;
use AiSdk\Live\TranscriptDelta;
use AiSdk\Live\TranscriptSource;
use AiSdk\Live\TransportFrame;
use AiSdk\Live\UsageEvent;
use AiSdk\Live\WebSocketEndpoint;
use AiSdk\OpenAI;
use AiSdk\OpenAI\Tests\Fakes\FakeHttpClient;
use AiSdk\OpenAI\Tests\Fakes\FakeTransport;
use AiSdk\Schema;
use AiSdk\Support\Sdk;
use AiSdk\Tool;
use Nyholm\Psr7\Factory\Psr17Factory;

afterEach(function () {
    Generate::reset();
    OpenAI::reset();
});

function configureOpenAILiveWith(FakeHttpClient $client): void
{
    $factory = new Psr17Factory();
    Generate::configure(new Sdk($client, $factory, $factory));
}

it('connects an OpenAI GA voice session and encodes client events', function () {
    $provider = OpenAI::create(['apiKey' => 'sk-live']);
    $transport = new FakeTransport();

    $session = Live::voice()
        ->model(OpenAI::model('gpt-realtime-2.1'))
        ->instructions('Be concise.')
        ->voice('marin')
        ->turnDetection('semantic_vad')
        ->connect($transport);

    expect($provider)->toBeInstanceOf(LiveProviderInterface::class)
        ->and($transport->endpoint)
        ->toBeInstanceOf(WebSocketEndpoint::class)
        ->url->toBe('wss://api.openai.com/v1/realtime?model=gpt-realtime-2.1')
        ->headers->toMatchArray(['Authorization' => 'Bearer sk-live']);

    $update = json_decode($transport->connection->sent[0]->payload, true);
    expect($update)
        ->type->toBe('session.update')
        ->session->type->toBe('realtime')
        ->session->audio->output->voice->toBe('marin')
        ->session->audio->input->turn_detection->type->toBe('semantic_vad');

    $session->sendAudio("\x01\x02");
    $session->sendText('Hello');
    $session->commitAudio();
    $session->requestResponse();
    $session->cancelResponse();

    $sent = array_map(
        static fn($frame): array => json_decode($frame->payload, true),
        array_slice($transport->connection->sent, 1),
    );

    expect(array_column($sent, 'type'))->toBe([
        'input_audio_buffer.append',
        'conversation.item.create',
        'input_audio_buffer.commit',
        'response.create',
        'response.cancel',
    ])->and($sent[0]['audio'])->toBe(base64_encode("\x01\x02"));
});

it('normalizes OpenAI audio text and transcript deltas and preserves lifecycle events', function () {
    OpenAI::create(['apiKey' => 'sk-live']);
    $transport = new FakeTransport();
    $session = Live::voice()->model(OpenAI::model('gpt-realtime-2.1'))->connect($transport);

    $transport->connection->enqueue(
        TransportFrame::text(json_encode(['type' => 'response.output_audio.delta', 'delta' => base64_encode('audio')])),
        TransportFrame::text(json_encode(['type' => 'response.output_text.delta', 'delta' => 'Hello'])),
        TransportFrame::text(json_encode([
            'type' => 'response.output_audio_transcript.delta',
            'item_id' => 'item_output',
            'delta' => 'Hi',
        ])),
        TransportFrame::text(json_encode(['type' => 'input_audio_buffer.speech_started', 'item_id' => 'item_1'])),
    );

    $events = iterator_to_array($session->events());

    expect($events[0])->toBeInstanceOf(AudioDelta::class)
        ->and($events[0]->bytes)->toBe('audio')
        ->and($events[1])->toBeInstanceOf(TextDelta::class)
        ->and($events[2])->toBeInstanceOf(TranscriptDelta::class)
        ->and($events[2]->itemId)->toBe('item_output')
        ->and($events[2]->source)->toBe(TranscriptSource::Output)
        ->and($events[3])->toBeInstanceOf(SpeechStarted::class);
});

it('executes registered OpenAI Live tools and emits unknown calls for manual handling', function () {
    OpenAI::create(['apiKey' => 'sk-live']);
    $transport = new FakeTransport();
    $weather = Tool::make('weather', 'Get weather')
        ->input(Schema::string(name: 'city')->required())
        ->run(fn(string $city): string => "Sunny in {$city}");
    $session = Live::voice()
        ->model(OpenAI::model('gpt-realtime-2.1'))
        ->tools([$weather])
        ->connect($transport);

    $transport->connection->enqueue(
        TransportFrame::text(json_encode([
            'type' => 'response.function_call_arguments.done',
            'call_id' => 'call_weather',
            'name' => 'weather',
            'arguments' => '{"city":"Lahore"}',
        ])),
        TransportFrame::text(json_encode([
            'type' => 'response.function_call_arguments.done',
            'call_id' => 'call_manual',
            'name' => 'human_approval',
            'arguments' => '{"approved":true}',
        ])),
        TransportFrame::text(json_encode([
            'type' => 'response.done',
            'response' => [
                'id' => 'resp_tools',
                'status' => 'completed',
                'output' => [
                    ['type' => 'function_call', 'call_id' => 'call_weather', 'name' => 'weather', 'arguments' => '{"city":"Lahore"}'],
                    ['type' => 'function_call', 'call_id' => 'call_manual', 'name' => 'human_approval', 'arguments' => '{"approved":true}'],
                ],
            ],
        ])),
    );

    $events = iterator_to_array($session->events());
    $session->sendToolResult('call_manual', ['approved' => true]);
    $sent = array_map(
        static fn($frame): array => json_decode($frame->payload, true),
        array_slice($transport->connection->sent, 1),
    );

    expect($sent[0]['type'])->toBe('conversation.item.create')
        ->and($sent[0]['item']['call_id'])->toBe('call_weather')
        ->and($sent[0]['item']['output'])->toBe('Sunny in Lahore')
        ->and($sent[1]['item']['call_id'])->toBe('call_manual')
        ->and($sent[2]['type'])->toBe('response.create')
        ->and($events)->toHaveCount(4)
        ->and($events[0])->toBeInstanceOf(ToolCallEvent::class)
        ->and($events[0]->callId)->toBe('call_weather')
        ->and($events[1])->toBeInstanceOf(ToolResultEvent::class)
        ->and($events[1]->automatic)->toBeTrue()
        ->and($events[2])->toBeInstanceOf(ToolCallEvent::class)
        ->and($events[2]->callId)->toBe('call_manual')
        ->and($events[3])->toBeInstanceOf(ResponseCompleted::class);
});

it('continues once after all parallel OpenAI Live tool results', function () {
    OpenAI::create(['apiKey' => 'sk-live']);
    $transport = new FakeTransport();
    $first = Tool::make('first', 'First tool')->run(fn(): string => 'one');
    $second = Tool::make('second', 'Second tool')->run(fn(): string => 'two');
    $session = Live::voice()
        ->model(OpenAI::model('gpt-realtime-2.1'))
        ->tools([$first, $second])
        ->connect($transport);

    $transport->connection->enqueue(
        TransportFrame::text(json_encode([
            'type' => 'response.function_call_arguments.done',
            'call_id' => 'call_first',
            'name' => 'first',
            'arguments' => '{}',
        ])),
        TransportFrame::text(json_encode([
            'type' => 'response.function_call_arguments.done',
            'call_id' => 'call_second',
            'name' => 'second',
            'arguments' => '{}',
        ])),
        TransportFrame::text(json_encode([
            'type' => 'response.done',
            'response' => [
                'id' => 'resp_parallel',
                'status' => 'completed',
                'output' => [
                    ['type' => 'function_call', 'call_id' => 'call_first', 'name' => 'first', 'arguments' => '{}'],
                    ['type' => 'function_call', 'call_id' => 'call_second', 'name' => 'second', 'arguments' => '{}'],
                ],
            ],
        ])),
    );

    iterator_to_array($session->events());
    $sent = array_map(
        static fn($frame): array => json_decode($frame->payload, true),
        array_slice($transport->connection->sent, 1),
    );
    $types = array_column($sent, 'type');

    expect($types)->toBe([
        'conversation.item.create',
        'conversation.item.create',
        'response.create',
    ])->and(array_count_values($types)['response.create'])->toBe(1);
});

it('prepares current OpenAI transcription and translation sessions', function () {
    OpenAI::create(['apiKey' => 'sk-live']);

    $transcriptionTransport = new FakeTransport();
    $transcription = Live::transcribe()
        ->model(OpenAI::model('gpt-realtime-whisper'))
        ->language('en')
        ->providerOptions('openai', [
            'audio' => ['input' => ['transcription' => ['delay' => 'low']]],
        ])
        ->connect($transcriptionTransport);
    $transcriptionUpdate = json_decode($transcriptionTransport->connection->sent[0]->payload, true);

    expect($transcriptionTransport->endpoint?->url)->toBe('wss://api.openai.com/v1/realtime?model=gpt-realtime-whisper')
        ->and($transcriptionUpdate['session']['type'])->toBe('transcription')
        ->and($transcriptionUpdate['session']['audio']['input']['transcription'])->toMatchArray([
            'model' => 'gpt-realtime-whisper',
            'language' => 'en',
            'delay' => 'low',
        ]);

    $transcription->sendAudio('pcm');
    expect(json_decode($transcriptionTransport->connection->sent[1]->payload, true)['audio'])
        ->toBe(base64_encode('pcm'));

    $translationTransport = new FakeTransport();
    $translation = Live::translate()
        ->model(OpenAI::model('gpt-realtime-translate'))
        ->from('en')
        ->to('es')
        ->connect($translationTransport);
    $translationUpdate = json_decode($translationTransport->connection->sent[0]->payload, true);

    expect($translationTransport->endpoint?->url)->toBe('wss://api.openai.com/v1/realtime/translations?model=gpt-realtime-translate')
        ->and($translationUpdate['session']['audio']['output']['language'])->toBe('es')
        ->and($translationUpdate['session']['audio']['input']['transcription']['language'])->toBe('en');

    $translation->sendAudio('pcm');
    $translation->close();
    expect(json_decode($translationTransport->connection->sent[1]->payload, true)['type'])
        ->toBe('session.input_audio_buffer.append')
        ->and(json_decode($translationTransport->connection->sent[2]->payload, true)['type'])
        ->toBe('session.close')
        ->and($translationTransport->connection->closed)->toBeFalse();
});

it('normalizes OpenAI completion usage errors and closure', function () {
    OpenAI::create(['apiKey' => 'sk-live']);
    $transport = new FakeTransport();
    $session = Live::voice()->model(OpenAI::model('gpt-realtime-2.1'))->connect($transport);

    $transport->connection->enqueue(
        TransportFrame::text(json_encode([
            'type' => 'conversation.item.input_audio_transcription.completed',
            'item_id' => 'item_1',
            'transcript' => 'Hello world',
        ])),
        TransportFrame::text(json_encode([
            'type' => 'response.done',
            'response' => [
                'id' => 'resp_1',
                'status' => 'completed',
                'usage' => ['total_tokens' => 12, 'input_token_details' => ['cached_tokens' => 3]],
            ],
        ])),
        TransportFrame::text(json_encode([
            'type' => 'error',
            'error' => ['code' => 'invalid_event', 'message' => 'Bad event'],
        ])),
        TransportFrame::text(json_encode(['type' => 'session.closed', 'reason' => 'completed'])),
    );

    $events = iterator_to_array($session->events());

    expect($events[0])->toBeInstanceOf(TranscriptCompleted::class)
        ->and($events[0]->text)->toBe('Hello world')
        ->and($events[0]->itemId)->toBe('item_1')
        ->and($events[0]->source)->toBe(TranscriptSource::Input)
        ->and($events[1])->toBeInstanceOf(UsageEvent::class)
        ->and($events[1]->usage)->toMatchArray([
            'total_tokens' => 12,
            'input_token_details.cached_tokens' => 3,
        ])
        ->and($events[2])->toBeInstanceOf(ResponseCompleted::class)
        ->and($events[2]->responseId)->toBe('resp_1')
        ->and($events[3])->toBeInstanceOf(LiveError::class)
        ->and($events[3]->code)->toBe('invalid_event')
        ->and($events[4])->toBeInstanceOf(LiveClosed::class)
        ->and($transport->connection->closed)->toBeTrue();
});

it('creates OpenAI voice and transcription client secrets with endpoint-specific schemas', function () {
    $voiceClient = new FakeHttpClient(200, json_encode([
        'value' => 'ek_voice',
        'expires_at' => 1_800_000_000,
        'session' => ['id' => 'sess_voice'],
    ]));
    configureOpenAILiveWith($voiceClient);
    OpenAI::create(['apiKey' => 'sk-live']);

    $voiceSecret = Live::voice()
        ->model(OpenAI::model('gpt-realtime-2.1'))
        ->voice('marin')
        ->providerOptions('openai', ['expires_after' => ['anchor' => 'created_at', 'seconds' => 600]])
        ->clientSecret();

    expect($voiceSecret->value)->toBe('ek_voice')
        ->and($voiceSecret->sessionId)->toBe('sess_voice')
        ->and((string) $voiceClient->lastRequest?->getUri())->toBe('https://api.openai.com/v1/realtime/client_secrets')
        ->and($voiceClient->sentBody()['session']['type'])->toBe('realtime')
        ->and($voiceClient->sentBody()['session']['audio']['output']['voice'])->toBe('marin')
        ->and($voiceClient->sentBody()['expires_after']['seconds'])->toBe(600);

    $transcriptionClient = new FakeHttpClient(200, json_encode([
        'id' => 'sess_transcription',
        'client_secret' => ['value' => 'ek_transcription', 'expires_at' => 1_800_000_100],
    ]));
    configureOpenAILiveWith($transcriptionClient);
    OpenAI::create(['apiKey' => 'sk-live']);

    $transcriptionSecret = Live::transcribe()
        ->model(OpenAI::model('gpt-realtime-whisper'))
        ->language('en')
        ->audioFormat('g711_ulaw')
        ->clientSecret();
    $body = $transcriptionClient->sentBody();

    expect($transcriptionSecret->value)->toBe('ek_transcription')
        ->and($transcriptionSecret->sessionId)->toBe('sess_transcription')
        ->and((string) $transcriptionClient->lastRequest?->getUri())->toBe('https://api.openai.com/v1/realtime/transcription_sessions')
        ->and($body['input_audio_format'])->toBe('g711_ulaw')
        ->and($body['input_audio_transcription'])->toBe([
            'model' => 'gpt-realtime-whisper',
            'language' => 'en',
        ])
        ->and($body)->not->toHaveKey('type')
        ->and($body)->not->toHaveKey('audio');
});

it('creates OpenAI WebRTC calls and exposes their call id', function () {
    $client = new FakeHttpClient(
        201,
        'answer-sdp',
        'application/sdp',
        ['Location' => '/v1/realtime/calls/rtc_123'],
    );
    configureOpenAILiveWith($client);
    OpenAI::create(['apiKey' => 'sk-live']);

    $answer = Live::voice()
        ->model(OpenAI::model('gpt-realtime-2.1'))
        ->voice('marin')
        ->webRtc('offer-sdp');

    expect($answer->sdp)->toBe('answer-sdp')
        ->and($answer->callId)->toBe('rtc_123')
        ->and((string) $client->lastRequest?->getUri())->toBe('https://api.openai.com/v1/realtime/calls')
        ->and($client->lastRequest?->getHeaderLine('Content-Type'))->toStartWith('multipart/form-data; boundary=')
        ->and((string) $client->lastRequest?->getBody())->toContain(
            'name="sdp"',
            'offer-sdp',
            'name="session"',
            'gpt-realtime-2.1',
            'marin',
        );
});

it('creates dedicated OpenAI transcription sessions over the unified WebRTC endpoint', function () {
    $client = new FakeHttpClient(
        201,
        'transcription-answer-sdp',
        'application/sdp',
        ['Location' => '/v1/realtime/calls/rtc_transcription'],
    );
    configureOpenAILiveWith($client);
    OpenAI::create(['apiKey' => 'sk-live']);

    $answer = Live::transcribe()
        ->model(OpenAI::model('gpt-realtime-whisper'))
        ->language('en')
        ->webRtc('transcription-offer-sdp');

    expect($answer->callId)->toBe('rtc_transcription')
        ->and((string) $client->lastRequest?->getUri())->toBe('https://api.openai.com/v1/realtime/calls')
        ->and((string) $client->lastRequest?->getBody())->toContain(
            'transcription-offer-sdp',
            '"type":"transcription"',
            '"model":"gpt-realtime-whisper"',
            '"language":"en"',
        );
});

it('uses OpenAI translation credentials for the dedicated WebRTC calls endpoint', function () {
    $client = new FakeHttpClient(200, json_encode([
        'value' => 'ek_translation',
        'expires_at' => 1_800_000_000,
        'session' => ['id' => 'sess_translation'],
    ]));
    $client->enqueueResponse(
        201,
        'translation-answer-sdp',
        'application/sdp',
        ['Location' => '/v1/realtime/calls/rtc_translation'],
    );
    configureOpenAILiveWith($client);
    OpenAI::create(['apiKey' => 'sk-live']);

    $answer = Live::translate()
        ->model(OpenAI::model('gpt-realtime-translate'))
        ->from('en')
        ->to('es')
        ->webRtc('translation-offer-sdp');

    expect($answer->callId)->toBe('rtc_translation')
        ->and((string) $client->requests[0]->getUri())->toBe('https://api.openai.com/v1/realtime/translations/client_secrets')
        ->and((string) $client->requests[1]->getUri())->toBe('https://api.openai.com/v1/realtime/translations/calls')
        ->and($client->requests[1]->getHeaderLine('Authorization'))->toBe('Bearer ek_translation')
        ->and($client->requests[1]->getHeaderLine('Content-Type'))->toBe('application/sdp')
        ->and((string) $client->requests[1]->getBody())->toBe('translation-offer-sdp');
});

it('accepts controls and hangs up OpenAI hosted calls', function () {
    $client = new FakeHttpClient(200, '{}');
    configureOpenAILiveWith($client);
    OpenAI::create(['apiKey' => 'sk-live']);
    $transport = new FakeTransport();

    $call = Live::voice()
        ->model(OpenAI::model('gpt-realtime-2.1'))
        ->instructions('Answer phone support questions.')
        ->call('rtc_sip_123');
    $call->accept();
    $control = $call->connect($transport);
    $control->requestResponse();
    $call->hangup();

    $accept = json_decode((string) $client->requests[0]->getBody(), true);
    $sidebandUpdate = json_decode($transport->connection->sent[0]->payload, true);

    expect($call->id())->toBe('rtc_sip_123')
        ->and((string) $client->requests[0]->getUri())->toBe('https://api.openai.com/v1/realtime/calls/rtc_sip_123/accept')
        ->and($accept['model'])->toBe('gpt-realtime-2.1')
        ->and((string) $client->requests[1]->getUri())->toBe('https://api.openai.com/v1/realtime/calls/rtc_sip_123/hangup')
        ->and($transport->endpoint?->url)->toBe('wss://api.openai.com/v1/realtime?call_id=rtc_sip_123')
        ->and($sidebandUpdate['session'])->not->toHaveKey('model')
        ->and(json_decode($transport->connection->sent[1]->payload, true)['type'])->toBe('response.create');
});

it('verifies signed OpenAI incoming-call webhooks from the raw body', function () {
    $payload = json_encode([
        'object' => 'event',
        'type' => 'realtime.call.incoming',
        'data' => ['call_id' => 'rtc_webhook'],
    ], JSON_THROW_ON_ERROR);
    $secretBytes = 'openai-webhook-secret';
    $secret = 'whsec_' . base64_encode($secretBytes);
    $timestamp = (string) time();
    $messageId = 'wh_test';
    $signature = base64_encode(hash_hmac(
        'sha256',
        $messageId . '.' . $timestamp . '.' . $payload,
        $secretBytes,
        true,
    ));

    $event = OpenAI::verifyWebhook($payload, [
        'Webhook-Id' => $messageId,
        'Webhook-Timestamp' => $timestamp,
        'Webhook-Signature' => 'v1,' . $signature,
    ], $secret);

    expect($event['type'])->toBe('realtime.call.incoming')
        ->and($event['data']['call_id'])->toBe('rtc_webhook');
});
