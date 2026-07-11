<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Models;

use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\OpenAI\OpenAIOptions;
use AiSdk\OpenAICompatible\SpeechRequestBuilder;
use AiSdk\OpenAICompatible\SpeechResponseParser;
use AiSdk\Requests\SpeechRequest;
use AiSdk\Responses\SpeechResponse;
use AiSdk\Utils\Support\Url;

final class OpenAISpeechModel extends BaseModel implements SpeechModelInterface
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

    public function generate(SpeechRequest $request): SpeechResponse
    {
        $body = SpeechRequestBuilder::build($this->modelId, $this->provider(), $request);
        $url = Url::joinPath($this->options->baseUrl, '/audio/speech');
        $format = (string) ($body['response_format'] ?? 'mp3');
        $fallbackMimeType = SpeechRequestBuilder::expectedMimeType($format);

        $response = $this->runner($this->options->sdk)
            ->postRaw($url, $body, array_replace(['Accept' => $fallbackMimeType], $this->options->authHeaders()), $this->provider());

        return SpeechResponseParser::parse(
            $response,
            $this->provider(),
            $fallbackMimeType,
            ['model' => $this->modelId, 'format' => $format],
        );
    }

}
