<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Net;

/**
 * The production factory: a real socket, with TLS verified.
 *
 * Deliberately empty of decisions — everything that could be got wrong lives in
 * {@see StreamTransport} and {@see Endpoint}, where it is a literal rather than
 * a parameter.
 */
final class StreamTransportFactory implements TransportFactory
{
    public function connect(Endpoint $endpoint): Transport
    {
        return StreamTransport::connect($endpoint);
    }
}
