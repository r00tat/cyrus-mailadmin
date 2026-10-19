<?php

declare(strict_types=1);

namespace Mailadmin\Http;

use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\Problem\ProblemDetails;

/**
 * The answer to a request that failed before the middleware chain existed — a
 * missing mandatory environment variable, an unwritable cache directory, a
 * duplicate container definition.
 *
 * It cannot use the PSR-7 stack or the logger, because the container is the thing
 * that failed. It nevertheless has to behave like every other error response:
 * `problem+json`, a stable code, the security headers from architecture 5.7, and
 * a request id the operator can search for. A startup failure is the response most
 * likely to be rendered in a browser, so it is the last one that may go out
 * without a Content-Security-Policy.
 */
final class StartupFailureResponse
{
    private function __construct()
    {
    }

    public static function problem(string $requestId): ProblemDetails
    {
        return ProblemDetails::internalServerError(requestId: $requestId);
    }

    /**
     * @return array<string, string>
     */
    public static function headers(string $requestId): array
    {
        return SecurityHeaders::HEADERS + [
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => SecurityHeaders::CACHE_CONTROL,
            RequestIdMiddleware::HEADER => $requestId,
        ];
    }

    /**
     * Writes status, headers and body straight to the SAPI. The caller generates
     * the id first and logs it, so that the log entry exists even if writing the
     * response fails.
     */
    public static function emit(string $requestId): void
    {
        $problem = self::problem($requestId);

        if (!headers_sent()) {
            http_response_code($problem->status);

            foreach (self::headers($requestId) as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $problem->toJson();
    }
}
