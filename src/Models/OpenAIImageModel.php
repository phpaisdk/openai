<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Models;

use AiSdk\Capability;
use AiSdk\CapabilitySupport;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\OpenAI\OpenAIOptions;
use AiSdk\OpenAI\Support\ImageRequestBuilder;
use AiSdk\OpenAI\Support\ImageResponseParser;
use AiSdk\Requests\ImageRequest;
use AiSdk\Responses\ImageResponse;
use AiSdk\Support\ModelCatalog;
use AiSdk\Support\ModelRegistry;
use AiSdk\Utils\Support\Url;

final class OpenAIImageModel extends BaseModel implements ImageModelInterface
{
    public function __construct(
        private readonly string $modelId,
        private readonly OpenAIOptions $options,
        private readonly ?ModelRegistry $registry = null,
    ) {}

    public function provider(): string
    {
        return OpenAIOptions::PROVIDER_NAME;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    /**
     * @return array<int, Capability>
     */
    public function capabilities(): array
    {
        $definition = $this->registry?->resolve($this->provider(), $this->modelId);
        if ($definition !== null) {
            return $this->configuredCapabilities($definition->capabilities);
        }

        return $this->configuredCapabilities($this->catalog()->capabilities($this->modelId));
    }

    public function capability(Capability $capability): CapabilitySupport
    {
        $configured = $this->configuredCapability($capability);
        if ($configured !== null) {
            return $configured;
        }

        $registered = $this->registry?->capability($this->provider(), $this->modelId, $capability);
        if ($registered !== null) {
            return $registered;
        }

        return $this->catalog()->capability($this->modelId, $capability);
    }

    public function generate(ImageRequest $request): ImageResponse
    {
        if ($request->seed !== null) {
            throw new InvalidArgumentException('OpenAI image generation does not support the portable seed() option. Use providerOptions(\'openai\', ...) if this model or endpoint adds provider-specific seed support.');
        }

        $options = $request->providerOptionsFor($this->provider());
        if ($request->aspectRatio !== null
            && $request->size === null
            && ! array_key_exists('size', $options)
            && ImageRequestBuilder::sizeForAspectRatio($request->aspectRatio) === null) {
            throw new InvalidArgumentException("OpenAI image generation does not support aspect ratio [{$request->aspectRatio}]. Use size() or providerOptions('openai', ...).");
        }

        $body = ImageRequestBuilder::build($this->modelId, $request);
        $url = Url::joinPath($this->options->baseUrl, '/images/generations');

        $payload = $this->runner($this->options->sdk)
            ->postJson($url, $body, $this->options->authHeaders(), $this->provider());

        return ImageResponseParser::parse($payload, $this->provider());
    }

    private function catalog(): ModelCatalog
    {
        return ModelCatalog::fromFile(dirname(__DIR__, 2) . '/resources/models.json');
    }
}
