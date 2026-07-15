<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Tests\Fakes;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FakeHttpClient implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    private array $queuedResponses = [];

    private int $requestCount = 0;

    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $status,
        private readonly string $body,
        private readonly string $contentType = 'application/json',
        private readonly array $headers = [],
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;
        $this->requests[] = $request;

        if ($this->requestCount++ > 0 && $this->queuedResponses !== []) {
            return array_shift($this->queuedResponses);
        }

        return new Response(
            $this->status,
            array_replace(['Content-Type' => $this->contentType], $this->headers),
            $this->body,
        );
    }

    /** @param array<string, string> $headers */
    public function enqueueResponse(int $status, string $body, string $contentType = 'application/json', array $headers = []): void
    {
        $this->queuedResponses[] = new Response(
            $status,
            array_replace(['Content-Type' => $contentType], $headers),
            $body,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function sentBody(): array
    {
        $decoded = json_decode((string) $this->lastRequest?->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
