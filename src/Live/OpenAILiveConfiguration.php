<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Live;

use AiSdk\Live\LiveOperation;
use AiSdk\Live\LiveRequest;
use AiSdk\OpenAI\OpenAIOptions;
use AiSdk\Tool;

/** Builds current GA Realtime session payloads shared by every connection path. */
final class OpenAILiveConfiguration
{
    /** @return array<string, mixed> */
    public static function session(string $modelId, OpenAIOptions $options, LiveRequest $request): array
    {
        $session = match ($request->operation) {
            LiveOperation::Voice => self::voice($modelId, $request),
            LiveOperation::Transcribe => self::transcription($modelId, $request),
            LiveOperation::Translate => self::translation($modelId, $request),
        };

        $provider = $request->providerOptions[$options::PROVIDER_NAME] ?? [];
        $direct = array_diff_key($provider, array_flip([
            'headers',
            'query',
            'raw',
            'session',
            'client_secret',
            'expires_after',
        ]));
        $session = array_replace_recursive($session, $direct);

        if (is_array($provider['session'] ?? null)) {
            $session = array_replace_recursive($session, $provider['session']);
        }
        if (is_array($provider['raw'] ?? null)) {
            $session = array_replace_recursive($session, $provider['raw']);
        }

        return $session;
    }

    /** @return array<string, mixed> */
    public static function clientSecretBody(string $modelId, OpenAIOptions $options, LiveRequest $request): array
    {
        $provider = $request->providerOptions[$options::PROVIDER_NAME] ?? [];
        $body = ['session' => self::session($modelId, $options, $request)];

        if (is_array($provider['client_secret'] ?? null)) {
            $body = array_replace_recursive($body, $provider['client_secret']);
        }
        if (is_array($provider['expires_after'] ?? null)) {
            $body['expires_after'] = $provider['expires_after'];
        }

        return $body;
    }

    /**
     * The dedicated transcription credential REST resource retains its flat
     * request schema even though GA WebSocket/WebRTC session.update is nested.
     *
     * @return array<string, mixed>
     */
    public static function transcriptionClientSecretBody(
        string $modelId,
        OpenAIOptions $options,
        LiveRequest $request,
    ): array {
        $transcription = ['model' => $modelId];
        if (is_string($request->options['language'] ?? null)) {
            $transcription['language'] = $request->options['language'];
        }

        $body = [
            'input_audio_format' => self::legacyAudioFormat($request->options['input_audio_format'] ?? 'pcm16'),
            'input_audio_transcription' => $transcription,
        ];
        $provider = $request->providerOptions[$options::PROVIDER_NAME] ?? [];

        if (is_array($provider['client_secret'] ?? null)) {
            $body = array_replace_recursive($body, $provider['client_secret']);
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private static function voice(string $modelId, LiveRequest $request): array
    {
        $session = [
            'type' => 'realtime',
            'model' => $modelId,
            'output_modalities' => ['audio'],
            'audio' => [
                'input' => ['format' => self::audioFormat($request->options['input_audio_format'] ?? 'pcm16')],
                'output' => ['format' => self::audioFormat($request->options['output_audio_format'] ?? 'pcm16')],
            ],
        ];

        if (is_string($request->options['instructions'] ?? null)) {
            $session['instructions'] = $request->options['instructions'];
        }
        if (is_string($request->options['voice'] ?? null)) {
            $session['audio']['output']['voice'] = $request->options['voice'];
        }
        if (array_key_exists('turn_detection', $request->options)) {
            $session['audio']['input']['turn_detection'] = self::turnDetection($request->options['turn_detection']);
        }
        if ($request->tools !== []) {
            $session['tools'] = array_map(self::toolDefinition(...), $request->tools);
            $session['tool_choice'] = 'auto';
        }

        return $session;
    }

    /** @return array<string, mixed> */
    private static function transcription(string $modelId, LiveRequest $request): array
    {
        $transcription = ['model' => $modelId];
        if (is_string($request->options['language'] ?? null)) {
            $transcription['language'] = $request->options['language'];
        }

        return [
            'type' => 'transcription',
            'audio' => [
                'input' => [
                    'format' => self::audioFormat($request->options['input_audio_format'] ?? 'pcm16'),
                    'transcription' => $transcription,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function translation(string $modelId, LiveRequest $request): array
    {
        $session = [
            'model' => $modelId,
            'audio' => [
                'input' => ['format' => self::audioFormat($request->options['input_audio_format'] ?? 'pcm16')],
                'output' => ['format' => self::audioFormat($request->options['output_audio_format'] ?? 'pcm16')],
            ],
        ];

        if (is_string($request->options['to'] ?? null)) {
            $session['audio']['output']['language'] = $request->options['to'];
        }

        // OpenAI detects source language automatically. Supplying `from()`
        // enables the optional source transcript and gives Whisper a language hint.
        if (is_string($request->options['from'] ?? null)) {
            $session['audio']['input']['transcription'] = [
                'model' => 'gpt-realtime-whisper',
                'language' => $request->options['from'],
            ];
        }

        return $session;
    }

    /** @return array<string, mixed> */
    private static function audioFormat(mixed $format): array
    {
        if (! is_string($format)) {
            return ['type' => 'audio/pcm', 'rate' => 24000];
        }

        return match (strtolower($format)) {
            'pcm', 'pcm16', 'audio/pcm' => ['type' => 'audio/pcm', 'rate' => 24000],
            'pcmu', 'mulaw', 'g711_ulaw', 'audio/pcmu' => ['type' => 'audio/pcmu'],
            'pcma', 'alaw', 'g711_alaw', 'audio/pcma' => ['type' => 'audio/pcma'],
            default => ['type' => $format],
        };
    }

    private static function legacyAudioFormat(mixed $format): string
    {
        if (! is_string($format)) {
            return 'pcm16';
        }

        return match (strtolower($format)) {
            'pcm', 'pcm16', 'audio/pcm' => 'pcm16',
            'pcmu', 'mulaw', 'g711_ulaw', 'audio/pcmu' => 'g711_ulaw',
            'pcma', 'alaw', 'g711_alaw', 'audio/pcma' => 'g711_alaw',
            default => $format,
        };
    }

    private static function turnDetection(mixed $value): mixed
    {
        if (is_array($value) || $value === null) {
            return $value;
        }

        if (is_string($value) && in_array(strtolower($value), ['none', 'disabled'], true)) {
            return null;
        }

        return is_string($value) ? ['type' => $value] : $value;
    }

    /** @return array<string, mixed> */
    private static function toolDefinition(Tool $tool): array
    {
        return [
            'type' => 'function',
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->inputSchemaForProvider(),
        ];
    }
}
