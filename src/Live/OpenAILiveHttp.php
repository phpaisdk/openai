<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Live;

use AiSdk\Generate;
use AiSdk\Live\WebRtcAnswer;
use AiSdk\OpenAI\OpenAIOptions;
use AiSdk\Utils\Http\HttpRunner;
use AiSdk\Utils\Support\Url;
use Psr\Http\Message\ResponseInterface;

/** REST signalling and hosted-call requests for OpenAI Realtime. */
final class OpenAILiveHttp
{
    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function postJson(OpenAIOptions $options, string $path, array $body): array
    {
        return self::runner($options)->postJson(
            Url::joinPath($options->baseUrl, $path),
            $body,
            $options->authHeaders(),
            OpenAIOptions::PROVIDER_NAME,
        );
    }

    /** @param array<string, mixed> $body */
    public static function postJsonWithoutResponse(OpenAIOptions $options, string $path, array $body): void
    {
        self::runner($options)->postRaw(
            Url::joinPath($options->baseUrl, $path),
            $body,
            $options->authHeaders(),
            OpenAIOptions::PROVIDER_NAME,
        );
    }

    public static function postEmpty(OpenAIOptions $options, string $path): void
    {
        $sdk = $options->sdk ?? Generate::sdk();
        $request = $sdk->requestFactory
            ->createRequest('POST', Url::joinPath($options->baseUrl, $path));

        foreach ($options->authHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        HttpRunner::fromSdk($sdk)->sendRequest($request, OpenAIOptions::PROVIDER_NAME);
    }

    /**
     * Creates a WebRTC call with a standard server API key and the unified
     * multipart interface documented for voice and transcription sessions.
     *
     * @param array<string, mixed> $session
     */
    public static function createCall(
        OpenAIOptions $options,
        string $offerSdp,
        array $session,
        string $path = '/realtime/calls',
    ): WebRtcAnswer {
        $boundary = 'aisdk-' . bin2hex(random_bytes(16));
        $body = self::multipartPart($boundary, 'sdp', $offerSdp, 'application/sdp')
            . self::multipartPart(
                $boundary,
                'session',
                json_encode($session, JSON_THROW_ON_ERROR),
                'application/json',
            )
            . '--' . $boundary . "--\r\n";

        $sdk = $options->sdk ?? Generate::sdk();
        $request = $sdk->requestFactory
            ->createRequest('POST', Url::joinPath($options->baseUrl, $path))
            ->withHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary)
            ->withHeader('Accept', 'application/sdp')
            ->withBody($sdk->streamFactory->createStream($body));

        foreach ($options->authHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = HttpRunner::fromSdk($sdk)->sendRequest($request, OpenAIOptions::PROVIDER_NAME);

        return self::answer($response);
    }

    /**
     * Translation's documented WebRTC flow posts SDP with the short-lived
     * translation client secret whose session configuration is already bound.
     */
    public static function createTokenBoundCall(
        OpenAIOptions $options,
        string $offerSdp,
        string $clientSecret,
        string $path,
    ): WebRtcAnswer {
        $sdk = $options->sdk ?? Generate::sdk();
        $request = $sdk->requestFactory
            ->createRequest('POST', Url::joinPath($options->baseUrl, $path))
            ->withHeader('Authorization', 'Bearer ' . $clientSecret)
            ->withHeader('Content-Type', 'application/sdp')
            ->withHeader('Accept', 'application/sdp')
            ->withBody($sdk->streamFactory->createStream($offerSdp));

        foreach ($options->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = HttpRunner::fromSdk($sdk)->sendRequest($request, OpenAIOptions::PROVIDER_NAME);

        return self::answer($response);
    }

    private static function runner(OpenAIOptions $options): HttpRunner
    {
        return HttpRunner::fromSdk($options->sdk ?? Generate::sdk());
    }

    private static function multipartPart(string $boundary, string $name, string $value, string $contentType): string
    {
        return '--' . $boundary . "\r\n"
            . "Content-Disposition: form-data; name=\"{$name}\"\r\n"
            . 'Content-Type: ' . $contentType . "\r\n\r\n"
            . $value . "\r\n";
    }

    private static function answer(ResponseInterface $response): WebRtcAnswer
    {
        $location = $response->getHeaderLine('Location');
        $path = parse_url($location, PHP_URL_PATH);
        $callId = is_string($path) && $path !== '' ? basename($path) : null;

        return new WebRtcAnswer(
            sdp: (string) $response->getBody(),
            callId: $callId !== '' ? $callId : null,
            raw: ['headers' => $response->getHeaders()],
        );
    }
}
