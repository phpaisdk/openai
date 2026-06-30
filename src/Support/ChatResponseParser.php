<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Support;

use AiSdk\OpenAI\Support\Converters\MapsFinishReason;
use AiSdk\Responses\Parts\TextPart;
use AiSdk\Responses\Parts\ToolCallPart;
use AiSdk\Responses\TextModelResponse;
use AiSdk\Support\Json;
use AiSdk\Support\Usage;

/**
 * Parses an OpenAI-compatible /chat/completions response into the normalized
 * TextModelResponse with typed parts.
 */
final class ChatResponseParser
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function parse(array $payload, string $providerName): TextModelResponse
    {
        $choice = $payload['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $parts = [];

        $text = $message['content'] ?? null;
        if (is_string($text) && $text !== '') {
            $parts[] = new TextPart($text);
        }

        foreach (($message['tool_calls'] ?? []) as $call) {
            $args = $call['function']['arguments'] ?? '{}';
            $decoded = is_string($args) ? Json::decodeValue($args === '' ? '{}' : $args) : $args;

            $parts[] = new ToolCallPart(
                id: (string) ($call['id'] ?? ''),
                name: (string) ($call['function']['name'] ?? ''),
                arguments: is_array($decoded) ? $decoded : [],
            );
        }

        $usage = isset($payload['usage']) && is_array($payload['usage'])
            ? ChatUsage::fromArray($payload['usage'])
            : Usage::empty();

        return new TextModelResponse(
            parts: $parts,
            finishReason: MapsFinishReason::map(isset($choice['finish_reason']) ? (string) $choice['finish_reason'] : null),
            usage: $usage,
            rawResponse: $payload,
            providerMetadata: [$providerName => self::metadata($payload, $choice)],
        );
    }

    /**
     * @param  array<int|string, mixed>  $payload
     * @param  array<int|string, mixed>  $choice
     * @return array<string, mixed>
     */
    private static function metadata(array $payload, array $choice): array
    {
        $metadata = [];

        foreach (['id', 'object', 'created', 'model', 'system_fingerprint', 'service_tier'] as $key) {
            if (array_key_exists($key, $payload)) {
                $metadata[$key] = $payload[$key];
            }
        }

        foreach (['index', 'finish_reason'] as $key) {
            if (array_key_exists($key, $choice)) {
                $metadata["choice_{$key}"] = $choice[$key];
            }
        }

        return $metadata;
    }
}
