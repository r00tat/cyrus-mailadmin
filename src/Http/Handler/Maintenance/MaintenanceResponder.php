<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Maintenance;

use Mailadmin\Application\Maintenance\RepairEntry;
use Mailadmin\Application\Maintenance\RepairErrorCode;
use Mailadmin\Application\Maintenance\RepairOperationFailed;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Http\Middleware\AuthenticationMiddleware;
use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\Problem\ErrorCode;
use Mailadmin\Http\Problem\ProblemDetails;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders the answers of the maintenance endpoints (A6), the way
 * `DomainResponder` does for A1: JSON with `Cache-Control: no-store`, refusals
 * as problem documents with a stable code and a short English detail, and a
 * `404` that is byte for byte the generic one.
 */
final readonly class MaintenanceResponder
{
    /** @var array<string, string> */
    private const array DETAILS = [
        RepairErrorCode::ALREADY_RESOLVED => 'The repair entry is already resolved.',
        RepairErrorCode::NOT_RETRYABLE => 'The application cannot finish this repair entry; handle it by hand and dismiss it.',
        RepairErrorCode::RETRY_FAILED => 'The retry failed again. The entry stays open.',
        RepairErrorCode::STATE_CHANGED => 'The state no longer matches the repair entry, so nothing was done.',
    ];

    /** @var array<int, string> */
    private const array TITLES = [
        409 => 'Conflict',
        503 => 'Service Unavailable',
    ];

    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private ProblemResponseFactory $problems,
        private LoggerInterface $logger,
    ) {
    }

    public function actor(ServerRequestInterface $request): ActorContext
    {
        $actor = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_ACTOR);

        if (!$actor instanceof ActorContext) {
            throw new \LogicException(
                'A maintenance handler ran without a verified actor. AuthenticationMiddleware must be '
                . 'in the dispatcher’s route-middleware chain.',
            );
        }

        return $actor;
    }

    public function entry(RepairEntry $entry): ResponseInterface
    {
        return $this->json(200, $entry->toRepresentation());
    }

    /**
     * @param array<string, mixed> $document
     */
    public function json(int $status, array $document): ResponseInterface
    {
        $body = json_encode(
            $document,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $this->responses
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream($body));
    }

    public function failure(ServerRequestInterface $request, RepairOperationFailed $failure): ResponseInterface
    {
        $this->logger->info('A repair-queue operation was refused.', [
            'code' => $failure->errorCode,
            'status' => $failure->status,
            'reason' => $failure->getMessage(),
            'http_method' => $request->getMethod(),
            'http_path' => $request->getUri()->getPath(),
        ]);

        if ($failure->status === 404) {
            return $this->notFound($request);
        }

        return $this->problems->create(new ProblemDetails(
            status: $failure->status,
            code: $failure->errorCode,
            title: self::TITLES[$failure->status] ?? 'Error',
            detail: self::DETAILS[$failure->errorCode] ?? 'The request could not be carried out.',
            instance: $request->getUri()->getPath(),
            requestId: self::requestId($request),
        ));
    }

    public function notFound(ServerRequestInterface $request): ResponseInterface
    {
        return $this->problems->create(ProblemDetails::notFound(
            instance: $request->getUri()->getPath(),
            requestId: self::requestId($request),
        ));
    }

    /**
     * A dependency the operation cannot do without is down. The code is the
     * cross-cutting one; which dependency and why goes to the log.
     */
    public function unavailable(ServerRequestInterface $request, string $why): ResponseInterface
    {
        $this->logger->warning('A maintenance operation could not reach a dependency.', [
            'reason' => $why,
            'http_path' => $request->getUri()->getPath(),
        ]);

        return $this->problems->create(new ProblemDetails(
            status: 503,
            code: 'request.service_unavailable',
            title: 'Service Unavailable',
            detail: 'A system this operation depends on could not be reached.',
            instance: $request->getUri()->getPath(),
            requestId: self::requestId($request),
        ));
    }

    public function badCriteria(ServerRequestInterface $request, CriteriaError $error): ResponseInterface
    {
        $this->logger->info('The repair queue was asked for with an unusable criterion.', [
            'reason' => $error->getMessage(),
            'http_path' => $request->getUri()->getPath(),
        ]);

        return $this->problems->create(new ProblemDetails(
            status: 422,
            code: ErrorCode::VALIDATION_FAILED,
            title: 'Unprocessable Content',
            detail: 'The listing was asked to sort or filter by something this resource does not offer.',
            instance: $request->getUri()->getPath(),
            requestId: self::requestId($request),
            errors: ['query' => 'unknown_field'],
        ));
    }

    /**
     * The `{repair}` path segment as an id, or `null` when it cannot be one.
     * The contract's pattern already rules out anything but digits.
     */
    public static function repairId(ServerRequestInterface $request): ?int
    {
        $value = $request->getAttribute('repair');

        if (!is_string($value) || !ctype_digit($value) || strlen($value) > 18 || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    private static function requestId(ServerRequestInterface $request): ?string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : null;
    }
}
