<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Models;

use AiSdk\Contracts\BaseModel;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Live\ClientSecret;
use AiSdk\Live\Contracts\LiveConnectionModelInterface;
use AiSdk\Live\Contracts\LiveSessionDriverInterface;
use AiSdk\Live\Contracts\ProviderLiveCallInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\LiveOperation;
use AiSdk\Live\LiveRequest;
use AiSdk\Live\WebRtcAnswer;
use AiSdk\OpenAI\Live\OpenAILiveCall;
use AiSdk\OpenAI\Live\OpenAILiveConfiguration;
use AiSdk\OpenAI\Live\OpenAILiveHttp;
use AiSdk\OpenAI\Live\OpenAILiveSessionDriver;
use AiSdk\OpenAI\OpenAIOptions;

final class OpenAILiveModel extends BaseModel implements LiveConnectionModelInterface
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

    public function createLiveSession(LiveRequest $request, TransportInterface $transport): LiveSessionDriverInterface
    {
        return new OpenAILiveSessionDriver(
            modelId: $this->modelId,
            options: $this->options,
            request: $request,
            transport: $transport,
        );
    }

    public function clientSecret(LiveRequest $request): ClientSecret
    {
        $path = match ($request->operation) {
            LiveOperation::Voice => '/realtime/client_secrets',
            LiveOperation::Transcribe => '/realtime/transcription_sessions',
            LiveOperation::Translate => '/realtime/translations/client_secrets',
        };
        $body = $request->operation === LiveOperation::Transcribe
            ? OpenAILiveConfiguration::transcriptionClientSecretBody($this->modelId, $this->options, $request)
            : OpenAILiveConfiguration::clientSecretBody($this->modelId, $this->options, $request);
        $payload = OpenAILiveHttp::postJson($this->options, $path, $body);

        $secret = $payload['value'] ?? $payload['client_secret'] ?? null;
        $expiresAt = $payload['expires_at'] ?? null;
        if (is_array($secret)) {
            $expiresAt = $secret['expires_at'] ?? $expiresAt;
            $secret = $secret['value'] ?? null;
        }

        if (! is_string($secret) || $secret === '') {
            throw new InvalidArgumentException('OpenAI did not return a usable Realtime client secret.');
        }

        $session = is_array($payload['session'] ?? null) ? $payload['session'] : $payload;

        return new ClientSecret(
            value: $secret,
            expiresAt: is_int($expiresAt) ? $expiresAt : null,
            sessionId: is_string($session['id'] ?? null) ? $session['id'] : null,
            raw: $payload,
        );
    }

    public function webRtc(LiveRequest $request, string $offerSdp): WebRtcAnswer
    {
        if ($request->operation === LiveOperation::Translate) {
            $secret = $this->clientSecret($request);

            return OpenAILiveHttp::createTokenBoundCall(
                $this->options,
                $offerSdp,
                $secret->value,
                '/realtime/translations/calls',
            );
        }

        return OpenAILiveHttp::createCall(
            $this->options,
            $offerSdp,
            OpenAILiveConfiguration::session($this->modelId, $this->options, $request),
        );
    }

    public function call(LiveRequest $request, string $callId): ProviderLiveCallInterface
    {
        if ($request->operation !== LiveOperation::Voice) {
            throw new InvalidArgumentException('OpenAI provider-hosted call control is available only for voice sessions.');
        }

        return new OpenAILiveCall($callId, $this->modelId, $this->options, $request);
    }
}
