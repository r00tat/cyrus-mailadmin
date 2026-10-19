<?php

declare(strict_types=1);

namespace Mailadmin\Http\Middleware;

use Mailadmin\Http\Problem\ProblemDetails;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Converts anything thrown below it into a generic `problem+json` with `500`.
 *
 * The division of labour is strict:
 *
 *  - **The client** learns the status, a stable code, an English fallback text
 *    and the request id. Nothing else. Not the exception class, not its message,
 *    not a file name, not a stack frame. An error page that names
 *    `Mailadmin\Infrastructure\Persistence\...` tells an attacker how the
 *    application is built.
 *  - **The log** gets the full picture, correlated by request id.
 *
 * A note for anyone throwing exceptions further down: the message *is* logged.
 * Never put a password, hash, token or connection secret into an exception
 * message — the masking processor keys on context field names and cannot see
 * inside a message string.
 */
final readonly class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ProblemResponseFactory $problems,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Throwable $exception) {
            $requestId = $this->requestId($request);
            $previous = $exception->getPrevious();

            $this->logger->error('Unhandled exception while serving a request.', [
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_code' => $exception->getCode(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
                'exception_trace' => $exception->getTraceAsString(),
                'previous_class' => $previous !== null ? $previous::class : null,
                'previous_message' => $previous?->getMessage(),
                'http_method' => $request->getMethod(),
                'http_path' => $request->getUri()->getPath(),
            ]);

            return $this->problems->create(ProblemDetails::internalServerError(
                instance: $request->getUri()->getPath(),
                requestId: $requestId,
            ));
        }
    }

    private function requestId(ServerRequestInterface $request): ?string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : null;
    }
}
