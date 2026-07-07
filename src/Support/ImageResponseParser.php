<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Support;

use AiSdk\Responses\ImageResponse;
use AiSdk\Results\ImageData;
use AiSdk\Support\Usage;

final class ImageResponseParser
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function parse(array $payload, string $providerName): ImageResponse
    {
        $images = [];

        foreach (($payload['data'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $images[] = new ImageData(
                base64: isset($item['b64_json']) ? (string) $item['b64_json'] : null,
                mimeType: isset($item['mime_type']) ? (string) $item['mime_type'] : 'image/png',
                width: isset($item['width']) ? (int) $item['width'] : null,
                height: isset($item['height']) ? (int) $item['height'] : null,
                url: isset($item['url']) ? (string) $item['url'] : null,
            );
        }

        return new ImageResponse(
            images: $images,
            usage: self::usage($payload),
            rawResponse: $payload,
            providerMetadata: [$providerName => self::metadata($payload)],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function usage(array $payload): Usage
    {
        $usage = $payload['usage'] ?? null;
        if (! is_array($usage)) {
            return Usage::empty();
        }

        return new Usage(
            inputTokens: (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0),
            totalTokens: isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function metadata(array $payload): array
    {
        $metadata = [];

        foreach (['id', 'created', 'model', 'quality', 'size', 'output_format', 'background'] as $key) {
            if (array_key_exists($key, $payload)) {
                $metadata[$key] = $payload[$key];
            }
        }

        return $metadata;
    }
}
