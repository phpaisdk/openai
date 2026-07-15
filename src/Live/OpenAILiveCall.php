<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Live;

use AiSdk\Live\Contracts\LiveSessionDriverInterface;
use AiSdk\Live\Contracts\ProviderLiveCallInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\LiveRequest;
use AiSdk\OpenAI\OpenAIOptions;

/** OpenAI SIP/WebRTC hosted-call lifecycle and call_id sideband control. */
final readonly class OpenAILiveCall implements ProviderLiveCallInterface
{
    public function __construct(
        private string $callId,
        private string $modelId,
        private OpenAIOptions $options,
        private LiveRequest $request,
    ) {}

    public function id(): string
    {
        return $this->callId;
    }

    public function accept(): void
    {
        $session = OpenAILiveConfiguration::session($this->modelId, $this->options, $this->request);
        OpenAILiveHttp::postJsonWithoutResponse(
            $this->options,
            '/realtime/calls/' . rawurlencode($this->callId) . '/accept',
            $session,
        );
    }

    public function connect(TransportInterface $transport): LiveSessionDriverInterface
    {
        return new OpenAILiveSessionDriver(
            modelId: $this->modelId,
            options: $this->options,
            request: $this->request,
            transport: $transport,
            callId: $this->callId,
        );
    }

    public function hangup(): void
    {
        OpenAILiveHttp::postEmpty(
            $this->options,
            '/realtime/calls/' . rawurlencode($this->callId) . '/hangup',
        );
    }
}
