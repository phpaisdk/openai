<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Tests\Fakes;

use AiSdk\Live\Contracts\TransportConnectionInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\TransportEndpoint;
use AiSdk\Live\TransportFrame;

final class FakeTransport implements TransportInterface
{
    public ?TransportEndpoint $endpoint = null;

    public FakeTransportConnection $connection;

    public function __construct()
    {
        $this->connection = new FakeTransportConnection();
    }

    public function supports(TransportEndpoint $endpoint): bool
    {
        return true;
    }

    public function connect(TransportEndpoint $endpoint): TransportConnectionInterface
    {
        $this->endpoint = $endpoint;

        return $this->connection;
    }
}

final class FakeTransportConnection implements TransportConnectionInterface
{
    /** @var list<TransportFrame> */
    public array $sent = [];

    /** @var list<TransportFrame> */
    private array $incoming = [];

    public bool $closed = false;

    public bool $sendingFinished = false;

    public function send(TransportFrame $frame): void
    {
        $this->sent[] = $frame;
    }

    public function receive(): ?TransportFrame
    {
        return array_shift($this->incoming);
    }

    public function finishSending(): void
    {
        $this->sendingFinished = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function enqueue(TransportFrame ...$frames): void
    {
        array_push($this->incoming, ...$frames);
    }
}
