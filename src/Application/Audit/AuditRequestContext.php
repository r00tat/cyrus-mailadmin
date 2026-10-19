<?php

declare(strict_types=1);

namespace Mailadmin\Application\Audit;

/**
 * Where the request came from, for as long as it is being handled.
 *
 * Architecture 13 wants the client address and the request id in every entry;
 * neither is knowable in a use case, and neither should be. Threading two more
 * parameters through every use case signature would put HTTP into the
 * application layer and would be forgotten somewhere; a container-scoped holder
 * that one middleware fills is the same shape S2's {@see \Mailadmin\Log\RequestIdProcessor}
 * already has, for the same reason (U-64).
 *
 * ## It is mutable, and that is bounded
 *
 * PHP-FPM handles one request per process, so "request-scoped" and
 * "process-scoped" are the same thing here. {@see \Mailadmin\Http\Middleware\AuditMiddleware}
 * sets it on the way in and clears it on the way out — including when an
 * exception passes through, so that a second request in a long-running process
 * cannot inherit the first one's address.
 *
 * ## Unset is a legitimate state
 *
 * A command-line run has no request, and so has no id and no address. Both are
 * then the empty string, which the columns default to. An entry from
 * `bin/migrate.php` says so by having neither, rather than by carrying a made-up
 * value.
 */
final class AuditRequestContext
{
    private string $requestId = '';

    private string $clientIp = '';

    public function bind(string $requestId, string $clientIp): void
    {
        $this->requestId = self::sane($requestId, 64);
        $this->clientIp = self::sane($clientIp, 45);
    }

    public function clear(): void
    {
        $this->requestId = '';
        $this->clientIp = '';
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function clientIp(): string
    {
        return $this->clientIp;
    }

    /**
     * Both values end up in a database column, and the request id may originate
     * in a client header. It is validated where it is accepted
     * ({@see \Mailadmin\Http\Middleware\RequestIdMiddleware}); this is the second
     * lock, because a holder that can be filled from anywhere should not assume
     * it was.
     */
    private static function sane(string $value, int $maxLength): string
    {
        $clean = trim(str_replace(["\r", "\n", "\0"], '', $value));

        return strlen($clean) <= $maxLength ? $clean : substr($clean, 0, $maxLength);
    }
}
