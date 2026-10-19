<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Net;

use Mailadmin\Infrastructure\Mailstore\ConnectionFailed;
use Mailadmin\Infrastructure\Mailstore\ReadTimedOut;

/**
 * Opens transports.
 *
 * It exists so that the connection pool can be unit-tested without a socket.
 * That is a small reason for an interface, but the alternative — a pool that
 * calls `StreamTransport::connect()` directly — means every test of reuse,
 * eviction and logout needs a real Cyrus, and those tests then only run where
 * F5 runs. The pooling rules are exactly the kind of thing that has to be
 * asserted on every commit.
 */
interface TransportFactory
{
    /**
     * @throws ConnectionFailed
     * @throws ReadTimedOut
     */
    public function connect(Endpoint $endpoint): Transport;
}
