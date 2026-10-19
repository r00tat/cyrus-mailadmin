<?php

declare(strict_types=1);

namespace Mailadmin\Log;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Puts the current request id into every log record (architecture 7.1, 13).
 *
 * The id is set by {@see \Mailadmin\Http\Middleware\RequestIdMiddleware} at the
 * outer edge of the middleware chain and cleared again when the response leaves,
 * so a long-running worker cannot attribute one request's log lines to another.
 *
 * This is a mutable singleton on purpose: it is the one piece of per-request
 * state the logger needs, and threading it through every constructor would mean
 * every class that logs takes an argument it does not otherwise use.
 */
final class RequestIdProcessor implements ProcessorInterface
{
    private ?string $requestId = null;

    public function set(?string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function get(): ?string
    {
        return $this->requestId;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if ($this->requestId === null) {
            return $record;
        }

        $record->extra['request_id'] = $this->requestId;

        return $record;
    }
}
