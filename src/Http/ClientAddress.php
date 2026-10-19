<?php

declare(strict_types=1);

namespace Mailadmin\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The address a request came from, for the sign-in rate limiter.
 *
 * `REMOTE_ADDR` and nothing else. `X-Forwarded-For` is **not** read, and that is
 * a decision rather than an omission: unless a trusted proxy overwrites the
 * header, any client can set it to whatever it likes, and a rate limiter keyed
 * on attacker-controlled input is worse than one that is merely too coarse.
 *
 * Behind a reverse proxy every request therefore appears to come from the proxy
 * and the per-address counter degenerates into a global one. The per-name
 * counter still works, and it is the one that protects an individual account.
 * Fixing this properly means naming the trusted proxies in configuration and
 * only then trusting the header — open point O-6.
 *
 * An unknown address yields an empty string, which the limiter treats as "do not
 * count by address" rather than as an address that many people share.
 */
final class ClientAddress
{
    private function __construct()
    {
    }

    public static function of(ServerRequestInterface $request): string
    {
        /** @var mixed $address */
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        if (!is_string($address)) {
            return '';
        }

        $address = trim($address);

        // Validated rather than passed through: the value ends up in a database
        // column and in a log line, and something that is not an address there
        // is either a broken SAPI or someone experimenting.
        return filter_var($address, FILTER_VALIDATE_IP) === false ? '' : $address;
    }
}
