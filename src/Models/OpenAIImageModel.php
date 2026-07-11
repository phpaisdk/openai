<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Models;

use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\OpenAI\OpenAIOptions;
use AiSdk\OpenAICompatible\ImageRequestBuilder;
use AiSdk\OpenAICompatible\ImageResponseParser;
use AiSdk\Requests\ImageRequest;
use AiSdk\Responses\ImageResponse;
use AiSdk\Utils\Support\Url;

final class OpenAIImageModel extends BaseModel implements ImageModelInterface
{
    public function __construct(
        private readonly string $modelId,
        private readonly OpenAIOptions $options,
    ) {}

    public function provider(): string
    {
        return OpenAIOptions::PROVIDER_NAME;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function generate(ImageRequest $request): ImageResponse
    {
        if ($request->seed !== null) {
            throw new InvalidArgumentException('OpenAI image generation does not support the portable seed() option. Use providerOptions(\'openai\', ...) if this model or endpoint adds provider-specific seed support.');
        }

        $body = ImageRequestBuilder::build(
            $this->modelId,
            $this->provider(),
            $request,
            ['includeResponseFormat' => ! str_starts_with($this->modelId, 'gpt-image')],
        );
        $url = Url::joinPath($this->options->baseUrl, '/images/generations');

        $payload = $this->runner($this->options->sdk)
            ->postJson($url, $body, $this->options->authHeaders(), $this->provider());

        return ImageResponseParser::parse($payload, $this->provider());
    }

}
