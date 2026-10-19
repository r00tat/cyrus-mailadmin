<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Domains;

use Mailadmin\Application\Domain\DomainDefaults;
use Mailadmin\Application\Domain\DomainErrorCode;
use Mailadmin\Application\Domain\DomainOperationFailed;
use Mailadmin\Application\Domain\DomainSummary;
use Mailadmin\Application\Domain\DomainView;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Http\Middleware\AuthenticationMiddleware;
use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\Problem\ErrorCode;
use Mailadmin\Http\Problem\ProblemDetails;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Page;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Rendering for the five domain handlers — one place, so that five endpoints
 * cannot disagree about what a refusal looks like.
 *
 * ## The rules it exists to keep
 *
 * **No exception message reaches a client.** {@see DomainOperationFailed}
 * carries a precise English sentence naming the domain, the account count or the
 * mail store's error code; that sentence goes to the log, and the client gets
 * the stable code and a fixed text (E-16, architecture 7.1). A handler that
 * passed `$failure->getMessage()` into `detail` would leak the account count of
 * a domain to somebody who was refused for a different reason.
 *
 * **A `404` is always the same `404`.** It comes from
 * {@see ProblemDetails::notFound()} — the same factory `NotFoundHandler` and
 * `AuthorizationMiddleware` use — so "no such domain", "not your domain" and "no
 * such path" are one document. A lookalike built here would drift, and the
 * drift would be the signal that tells the three apart (U-44).
 *
 * **The `ETag` is the one the representation hashes to.** The array is rendered
 * once and hashed once, by {@see DomainView}, and this class serialises exactly
 * that array. Two encodings would be two chances for the validator never to
 * match.
 */
final readonly class DomainResponder
{
    /**
     * What a client is told, per code. Short, English, and free of anything the
     * refusal was about.
     *
     * @var array<string, string>
     */
    private const array DETAILS = [
        DomainErrorCode::NAME_TAKEN => 'A domain with this name already exists.',
        DomainErrorCode::PREFIX_TAKEN => 'No free value is left for the unique legacy prefix column.',
        DomainErrorCode::NOT_EMPTY => 'The domain still holds accounts and was not deleted.',
        DomainErrorCode::STILL_HAS_ADDRESSES => 'The domain still has rows in the address table and was not deleted. '
            . 'GET /domains/{domain}/addresses lists them; DELETE /domains/{domain}/addresses/{entry} removes one.',
        DomainErrorCode::RENAME_NOT_CONFIRMED => 'Renaming a domain has to be confirmed explicitly.',
        DomainErrorCode::RENAME_INCOMPLETE => 'The rename did not complete and the domain was left as it was.',
        DomainErrorCode::MAILSTORE_UNAVAILABLE => 'The mail store could not be reached, so nothing was renamed.',
        DomainErrorCode::PRECONDITION_REQUIRED => 'This operation requires an If-Match header.',
        DomainErrorCode::PRECONDITION_FAILED => 'The domain has changed since it was last read.',
    ];

    /**
     * Appended to the mount prefix to build a `Location`.
     *
     * Spelled out here rather than read from the compiled route table, the same
     * way `RefreshCookie` spells its own path (S2): the version prefix is U-10's
     * decision and lives in `servers[0].url`, and a second reader of that value
     * would be a second thing to keep in step for no gain. A v2 running beside
     * v1 gets its own handlers anyway.
     */
    private const string API_PREFIX = '/api/v1';

    /** @var array<int, string> */
    private const array TITLES = [
        409 => 'Conflict',
        412 => 'Precondition Failed',
        422 => 'Unprocessable Content',
        428 => 'Precondition Required',
        503 => 'Service Unavailable',
    ];

    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private ProblemResponseFactory $problems,
        private DomainDefaults $defaults,
        private LoggerInterface $logger,
        private string $basePath,
    ) {
    }

    /**
     * The actor the authentication middleware established.
     *
     * Absent means the route middleware is not wired the way `RouteMiddleware`
     * says it is — a deployment fault, not a request fault, so it is a `500` and
     * not a `401`.
     */
    public function actor(ServerRequestInterface $request): ActorContext
    {
        $actor = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_ACTOR);

        if (!$actor instanceof ActorContext) {
            throw new \LogicException(
                'A domain handler ran without a verified actor. AuthenticationMiddleware must be '
                . 'in the dispatcher’s route-middleware chain.',
            );
        }

        return $actor;
    }

    /**
     * @param Page<DomainSummary> $page
     */
    public function collection(ServerRequestInterface $request, Page $page): ResponseInterface
    {
        return $this->json($request, 200, [
            'items' => array_map(
                fn (DomainSummary $summary): array => DomainView::listItem($summary, $this->defaults),
                $page->items,
            ),
            'meta' => $page->meta(),
        ]);
    }

    /**
     * One domain, with the `ETag` `PATCH` and `DELETE` will ask for back.
     *
     * `$withLocation` is for the answer to a `PATCH` that renamed: the resource
     * the client asked for now lives somewhere else, and the header is how it
     * finds out without parsing the body. A plain `GET` does not carry it —
     * an operation only declares the headers it can produce (`openapi/README.md`,
     * 9), and a `Location` pointing at the request's own path says nothing.
     */
    public function domain(
        ServerRequestInterface $request,
        DomainSummary $summary,
        bool $withLocation = false,
    ): ResponseInterface {
        $representation = DomainView::full($summary, $this->defaults);

        $response = $this
            ->json($request, 200, $representation)
            ->withHeader('ETag', DomainView::etag($representation));

        return $withLocation
            ? $response->withHeader('Location', $this->locationOf($summary))
            : $response;
    }

    public function created(ServerRequestInterface $request, DomainSummary $summary): ResponseInterface
    {
        $representation = DomainView::full($summary, $this->defaults);

        return $this
            ->json($request, 201, [
                'domain' => $representation,
                'next_steps' => DomainView::nextSteps($summary, $this->defaults),
            ])
            ->withHeader('ETag', DomainView::etag($representation))
            ->withHeader('Location', $this->locationOf($summary));
    }

    public function noContent(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses
            ->createResponse(204)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * A refusal the use case decided on.
     */
    public function failure(ServerRequestInterface $request, DomainOperationFailed $failure): ResponseInterface
    {
        $this->logger->info('A domain operation was refused.', [
            'code' => $failure->errorCode,
            'status' => $failure->status,
            'reason' => $failure->getMessage(),
            'http_method' => $request->getMethod(),
            'http_path' => $request->getUri()->getPath(),
        ]);

        if ($failure->status === 404) {
            // The same factory a genuine 404 uses. Not a lookalike: the two
            // documents have to be identical (CLAUDE.md, U-44).
            return $this->problems->create(ProblemDetails::notFound(
                instance: $request->getUri()->getPath(),
                requestId: self::requestId($request),
            ));
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

    /**
     * A value the contract accepted and the domain model did not — a folder name
     * with an `@` in it, a domain name that is a single label.
     *
     * `422` with the model's own stable code, and an `errors` map naming the
     * field. The offending **value** is never echoed back (the `Problem` schema
     * says so): the map carries the constraint, not the input.
     */
    public function invalid(ServerRequestInterface $request, DomainError $error, string $field): ResponseInterface
    {
        $this->logger->info('A domain request carried a value the model rejected.', [
            'code' => $error->code(),
            'reason' => $error->getMessage(),
            'http_method' => $request->getMethod(),
            'http_path' => $request->getUri()->getPath(),
        ]);

        return $this->problems->create(new ProblemDetails(
            status: 422,
            code: $error->code(),
            title: 'Unprocessable Content',
            detail: 'A value in the request is not one this application can store.',
            instance: $request->getUri()->getPath(),
            requestId: self::requestId($request),
            errors: [$field => $error->code()],
        ));
    }

    /**
     * A query parameter that is not one this resource offers.
     *
     * The contract enumerates `sort`, so the reachable case is an undeclared
     * parameter arriving as a filter. It is reported rather than ignored:
     * silently dropping `?legacy_hash=1` would answer a different question from
     * the one that was asked, and nothing would say so (see
     * {@see \Mailadmin\Infrastructure\Persistence\Criteria\FilterAllowlist}).
     */
    public function badCriteria(ServerRequestInterface $request, CriteriaError $error): ResponseInterface
    {
        $this->logger->info('A domain listing was asked for with an unusable criterion.', [
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
     * A `404` for a path segment that cannot be a domain name at all.
     */
    public function notFound(ServerRequestInterface $request): ResponseInterface
    {
        return $this->problems->create(ProblemDetails::notFound(
            instance: $request->getUri()->getPath(),
            requestId: self::requestId($request),
        ));
    }

    /**
     * The `If-Match` header, or `null` when it was not sent.
     */
    public static function ifMatch(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('If-Match');

        return trim($header) === '' ? null : $header;
    }

    private function locationOf(DomainSummary $summary): string
    {
        return rtrim($this->basePath, '/') . self::API_PREFIX
            . '/domains/' . rawurlencode($summary->name()->toString());
    }

    /**
     * @param array<string, mixed> $document
     */
    private function json(ServerRequestInterface $request, int $status, array $document): ResponseInterface
    {
        $body = json_encode(
            $document,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return $this->responses
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream($body));
    }

    private static function requestId(ServerRequestInterface $request): ?string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : null;
    }
}
