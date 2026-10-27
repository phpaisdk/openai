<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Models;

use AiSdk\Capability;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\OpenAI\OpenAIOptions;
use AiSdk\OpenAICompatible\ChatRequestBuilder;
use AiSdk\OpenAICompatible\ChatRequestProfile;
use AiSdk\OpenAICompatible\ChatResponseParser;
use AiSdk\OpenAICompatible\ChatStreamParser;
use AiSdk\Requests\TextModelRequest;
use AiSdk\Responses\TextModelResponse;
use AiSdk\Utils\Support\Url;
use Generator;

final class OpenAITextModel extends BaseModel implements TextModelInterface
{
    private const array ADAPTER_CAPABILITIES = [
        Capability::TextGeneration,
        Capability::Streaming,
        Capability::ToolCalling,
        Capability::StructuredOutput,
        Capability::Reasoning,
        Capability::TextInput,
        Capability::ImageInput,
        Capability::AudioInput,
        Capability::FileInput,
    ];

    public function __construct(
        private readonly string $modelId,
        private readonly OpenAIOptions $options,
    ) {}

    protected function supportsStoredFiles(): bool
    {
        return true;
    }

    public function provider(): string
    {
        return OpenAIOptions::PROVIDER_NAME;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function generate(TextModelRequest $request): TextModelResponse
    {
        $this->ensureTextRequestSupported($request, self::ADAPTER_CAPABILITIES);

        if ($this->usesResponses($request)) {
            $body = \AiSdk\OpenAICompatible\ResponsesRequestBuilder::build($this->modelId, $this->provider(), $request, stream: false, profile: new \AiSdk\OpenAICompatible\ResponsesRequestProfile(supportsPromptCacheKey: true));
            $url = Url::joinPath($this->options->baseUrl, '/responses');

            return \AiSdk\OpenAICompatible\ResponsesResponseParser::parse($this->runner($this->options->sdk)->postJson($url, $body, $this->options->authHeaders(), $this->provider()), $this->provider());
        }

        $body = ChatRequestBuilder::build(
            $this->modelId,
            $this->provider(),
            $request,
            stream: false,
            profile: ChatRequestProfile::openAI($this->modelId),
        );
        $url = Url::joinPath($this->options->baseUrl, '/chat/completions');

        $payload = $this->runner($this->options->sdk)
            ->postJson($url, $body, $this->options->authHeaders(), $this->provider());

        return ChatResponseParser::parse($payload, $this->provider());
    }

    public function stream(TextModelRequest $request): Generator
    {
        $this->ensureTextRequestSupported($request, self::ADAPTER_CAPABILITIES, streaming: true);

        if ($this->usesResponses($request)) {
            $body = \AiSdk\OpenAICompatible\ResponsesRequestBuilder::build($this->modelId, $this->provider(), $request, stream: true, profile: new \AiSdk\OpenAICompatible\ResponsesRequestProfile(supportsPromptCacheKey: true));
            $url = Url::joinPath($this->options->baseUrl, '/responses');
            yield from \AiSdk\OpenAICompatible\ResponsesStreamParser::parse($this->runner($this->options->sdk)->postStream($url, $body, $this->options->authHeaders(), $this->provider()), $this->provider());

            return;
        }

        $body = ChatRequestBuilder::build(
            $this->modelId,
            $this->provider(),
            $request,
            stream: true,
            profile: ChatRequestProfile::openAI($this->modelId),
        );
        $url = Url::joinPath($this->options->baseUrl, '/chat/completions');

        $events = $this->runner($this->options->sdk)
            ->postStream($url, $body, $this->options->authHeaders(), $this->provider());

        yield from ChatStreamParser::parse($events, $this->provider());
    }

    private function usesResponses(TextModelRequest $request): bool
    {
        $api = $request->providerOptionsFor($this->provider())['api'] ?? null;
        if ($api !== null && ! in_array($api, ['responses', 'chat'], true)) {
            throw new \InvalidArgumentException('api must be responses or chat.');
        }
        if ($api !== null) {
            return $api === 'responses';
        }
        foreach ($request->messages as $message) {
            foreach ($message->content as $content) {
                if (($content->type === \AiSdk\Content::TYPE_FILE && $content->source() === \AiSdk\ContentSource::Url) || $content->fileReference() !== null) {
                    return true;
                }
            }
        }

        return false;
    }
}
