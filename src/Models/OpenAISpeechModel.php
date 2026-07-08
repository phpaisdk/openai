<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Models;

use AiSdk\Capability;
use AiSdk\CapabilitySupport;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\OpenAI\OpenAIOptions;
use AiSdk\Requests\SpeechRequest;
use AiSdk\Responses\SpeechResponse;
use AiSdk\Results\AudioData;
use AiSdk\Support\ModelCatalog;
use AiSdk\Support\ModelRegistry;
use AiSdk\Support\Usage;
use AiSdk\Utils\Support\Url;

final class OpenAISpeechModel extends BaseModel implements SpeechModelInterface
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

    public function generate(SpeechRequest $request): SpeechResponse
    {
        $body = $this->buildBody($request);
        $url = Url::joinPath($this->options->baseUrl, '/audio/speech');
        $format = (string) ($body['response_format'] ?? 'mp3');

        $response = $this->runner($this->options->sdk)
            ->postRaw($url, $body, array_replace(['Accept' => $this->expectedMimeType($format)], $this->options->authHeaders()), $this->provider());

        $mimeType = $response->getHeaderLine('Content-Type') ?: $this->expectedMimeType($format);

        return new SpeechResponse(
            audio: new AudioData(data: (string) $response->getBody(), mimeType: $mimeType),
            usage: Usage::empty(),
            rawResponse: [],
            providerMetadata: [$this->provider() => ['model' => $this->modelId, 'format' => $format]],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBody(SpeechRequest $request): array
    {
        $body = [
            'model' => $this->modelId,
            'input' => $request->input,
            'voice' => $request->voice ?? 'alloy',
            'response_format' => $request->format ?? 'mp3',
        ];

        return array_replace($body, $request->providerOptionsFor($this->provider()));
    }

    private function expectedMimeType(string $format): string
    {
        return match ($format) {
            'wav' => 'audio/wav',
            'opus' => 'audio/opus',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'pcm' => 'audio/pcm',
            default => 'audio/mpeg',
        };
    }

    private function catalog(): ModelCatalog
    {
        return ModelCatalog::fromFile(dirname(__DIR__, 2) . '/resources/models.json');
    }
}
