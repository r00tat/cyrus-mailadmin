<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Guard;

use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\Problem\ProblemDetails;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Infrastructure\Persistence\ErrorCode;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Refuses service while the schema is behind (F3 open point 4, architecture
 * 10.2).
 *
 * ## `503`, not `500`
 *
 * The service is not broken; it is not finished being deployed. `503` says
 * "come back", carries `Retry-After` and is the same answer F2 gives for a stale
 * build (`migration.build_stale`) — the two are the same kind of fault and an
 * operator should not have to tell them apart by status code. A `500` would
 * suggest a defect and would be cached and alerted on differently.
 *
 * ## `/health` still answers
 *
 * It has to. The endpoint whose job is to say what is wrong must not be the one
 * that stops answering when something is — that is how a deployment check
 * reports "connection refused" instead of "run the migrator". Paths listed in
 * `$exemptSuffixes` skip the guard; the health handler is expected to report the
 * schema state as part of its body (A6 owns the readiness detail).
 *
 * ## Where it belongs in the chain
 *
 * Inside `ErrorHandler` and `SecurityHeaders`, outside the dispatcher — the
 * refusal should carry the security headers and the request id, and no handler
 * should run. Wiring it is one entry in `config/di/core.php`, which belongs to
 * F1/F2; F3 ships the middleware and the test, not the edit.
 */
final readonly class SchemaVersionMiddleware implements MiddlewareInterface
{
    /** Seconds. Long enough for a migration, short enough for a load balancer to retry. */
    public const int RETRY_AFTER = 30;

    /**
     * @param list<string> $exemptSuffixes path endings that are served regardless
     */
    public function __construct(
        private SchemaGuard $guard,
        private ProblemResponseFactory $problems,
        private LoggerInterface $logger = new NullLogger(),
        private array $exemptSuffixes = ['/health'],
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if ($this->isExempt($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        try {
            $this->guard->verify();
        } catch (SchemaOutdated $outdated) {
            return $this->refuse($request, $outdated, ErrorCode::SCHEMA_OUTDATED);
        } catch (PersistenceError $unavailable) {
            // The database is not reachable at all. Same shape of answer, a
            // different code, and still nothing about the DSN in the body.
            return $this->refuse($request, $unavailable, 'request.service_unavailable');
        }

        return $handler->handle($request);
    }

    private function isExempt(string $path): bool
    {
        $normalised = rtrim($path, '/');

        foreach ($this->exemptSuffixes as $suffix) {
            if ($normalised === $suffix || str_ends_with($normalised, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function refuse(
        ServerRequestInterface $request,
        PersistenceError $error,
        string $code,
    ): ResponseInterface {
        // The message names migration numbers and commands, never a credential.
        // It goes to the log; the client gets the code and the request id.
        $this->logger->critical('Refusing to serve: the database schema is not ready.', [
            'reason' => $error->getMessage(),
            'error_code' => $error->errorCode(),
            'http_path' => $request->getUri()->getPath(),
        ]);

        /** @var mixed $requestId */
        $requestId = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return $this->problems
            ->create(new ProblemDetails(
                status: 503,
                code: $code,
                title: 'Service Unavailable',
                detail: 'The service is not correctly deployed and is not answering requests.',
                instance: $request->getUri()->getPath(),
                requestId: is_string($requestId) ? $requestId : null,
            ))
            ->withHeader('Retry-After', (string) self::RETRY_AFTER);
    }
}
