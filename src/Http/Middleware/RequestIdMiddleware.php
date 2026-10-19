<?php

declare(strict_types=1);

namespace Mailadmin\Http\Middleware;

use Mailadmin\Log\RequestIdProcessor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gives every request an id, exposes it as `X-Request-Id` and hands it to the
 * logger (architecture 7.1, 13).
 *
 * An id supplied by the client is accepted only if it matches a strict pattern.
 * Log correlation ids end up in log lines, and an unvalidated header would allow
 * newline injection into the log and arbitrarily long values — a request header
 * is not a trustworthy source. Anything that does not match is replaced.
 */
final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'X-Request-Id';
    public const string ATTRIBUTE = 'request_id';

    /** Allows the common formats — UUID, hex, ULID — and nothing that could break a log line. */
    private const string ACCEPTED_PATTERN = '/^[A-Za-z0-9._-]{8,64}$/';

    public function __construct(private RequestIdProcessor $logProcessor)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $this->resolve($request);

        $this->logProcessor->set($requestId);

        try {
            return $handler
                ->handle($request->withAttribute(self::ATTRIBUTE, $requestId))
                ->withHeader(self::HEADER, $requestId);
        } finally {
            // Cleared even when an exception passes through, so that the next
            // request in a long-running process cannot inherit this id.
            $this->logProcessor->set(null);
        }
    }

    private function resolve(ServerRequestInterface $request): string
    {
        $supplied = $request->getHeaderLine(self::HEADER);

        if ($supplied !== '' && preg_match(self::ACCEPTED_PATTERN, $supplied) === 1) {
            return $supplied;
        }

        return self::generate();
    }

    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
