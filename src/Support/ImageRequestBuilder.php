<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Support;

use AiSdk\Requests\ImageRequest;

final class ImageRequestBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function build(string $modelId, ImageRequest $request): array
    {
        $body = [
            'model' => $modelId,
            'prompt' => $request->prompt,
            'n' => $request->count,
            'response_format' => 'b64_json',
        ];

        $size = $request->size ?? ($request->aspectRatio !== null ? self::sizeForAspectRatio($request->aspectRatio) : null);
        if ($size !== null) {
            $body['size'] = $size;
        }

        return array_replace($body, $request->providerOptionsFor('openai'));
    }

    public static function sizeForAspectRatio(string $aspectRatio): ?string
    {
        return match ($aspectRatio) {
            '1:1' => '1024x1024',
            '3:2', '16:9' => '1536x1024',
            '2:3', '9:16' => '1024x1536',
            default => null,
        };
    }
}
