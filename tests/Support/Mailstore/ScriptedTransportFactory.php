<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support\Mailstore;

use Mailadmin\Infrastructure\Mailstore\ConnectionFailed;
use Mailadmin\Infrastructure\Mailstore\Net\Endpoint;
use Mailadmin\Infrastructure\Mailstore\Net\Transport;
use Mailadmin\Infrastructure\Mailstore\Net\TransportFactory;

/**
 * Hands out prepared transports in order, and counts how often it was asked.
 *
 * The count is the point: it is what distinguishes "the pool reused the session"
 * from "the pool opened a second one and the test passed anyway".
 */
final class ScriptedTransportFactory implements TransportFactory
{
    /** @var list<ScriptedTransport> */
    private array $prepared;

    private int $handedOut = 0;

    public function __construct(ScriptedTransport ...$prepared)
    {
        $this->prepared = array_values($prepared);
    }

    public function connect(Endpoint $endpoint): Transport
    {
        $transport = $this->prepared[$this->handedOut] ?? null;

        if ($transport === null) {
            throw ConnectionFailed::socket(
                $endpoint->toString(),
                sprintf('the test prepared only %d transports', count($this->prepared)),
            );
        }

        ++$this->handedOut;

        return $transport;
    }

    public function handedOut(): int
    {
        return $this->handedOut;
    }
}
