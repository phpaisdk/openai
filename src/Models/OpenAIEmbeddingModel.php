<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Models;

use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\EmbeddingModelInterface;
use AiSdk\OpenAI\OpenAIOptions;
use AiSdk\OpenAICompatible\EmbeddingRequestBuilder;
use AiSdk\OpenAICompatible\EmbeddingResponseParser;
use AiSdk\Requests\EmbeddingRequest;
use AiSdk\Responses\EmbeddingResponse;
use AiSdk\Utils\Support\Url;

final class OpenAIEmbeddingModel extends BaseModel implements EmbeddingModelInterface
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

    public function generate(EmbeddingRequest $request): EmbeddingResponse
    {
        $body = EmbeddingRequestBuilder::build($this->modelId, $this->provider(), $request);
        $payload = $this->runner($this->options->sdk)->postJson(
            Url::joinPath($this->options->baseUrl, '/embeddings'),
            $body,
            $this->options->authHeaders(),
            $this->provider(),
        );

        return EmbeddingResponseParser::parse($payload, $this->provider(), count($request->inputs));
    }
}
