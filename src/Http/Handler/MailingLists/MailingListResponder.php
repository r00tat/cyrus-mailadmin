<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\MailingLists;

use Mailadmin\Application\MailingList\ListDelivery;
use Mailadmin\Application\MailingList\MailingListFailure;
use Mailadmin\Application\MailingList\MailingListView;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\MailingList\MailingList;
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

/**
 * Rendering for the five mailing list handlers, written once.
 *
 * ## The effect header
 *
 * On the legacy alias path ({@see ListDelivery::changesNeedConfigurationRun()}),
 * a create and a delete carry `X-Mailadmin-Effect: pending-configuration-run`
 * (U-59, U-143). Delivery itself does not wait: a new list's `virtual` entry
 * already routes to the pipe transport, and a deleted list's entry is gone, so
 * mail stops at once. What waits is the aliases file the configuration
 * management renders from `lists` (E-4): it gains the new list's line, or
 * keeps the deleted one, until the next run. A change waits for nothing —
 * the script reads name, tag and members from `lists` at delivery time, and
 * the aliases file holds none of them — so {@see changed()} carries no header.
 *
 * On the transport path the header is absent: the `virtual` entry routes the
 * address to the pipe transport, and a change is live once it is saved. Saying
 * otherwise would send the administrator waiting for a run that changes nothing.
 *
 * A header rather than a field in the body, because `DELETE` answers `204` and
 * has no body: a body field would have covered two of the three writes and left
 * the one with the most surprising delay silent. The value is a stable keyword,
 * not a sentence — the frontend translates it (E-16).
 */
final readonly class MailingListResponder
{
    /** Says that the answer describes a change that is not in effect yet. */
    public const string EFFECT_HEADER = 'X-Mailadmin-Effect';

    /** The one value the header currently takes. */
    public const string EFFECT_PENDING = 'pending-configuration-run';

    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private ProblemResponseFactory $problems,
        private ListDelivery $delivery,
    ) {
    }

    /**
     * The verified actor, or a loud failure.
     *
     * Not a `401`: authentication runs in front of every non-public operation,
     * so its absence means the chain is not what `RouteMiddleware` says it is.
     */
    public static function actor(ServerRequestInterface $request): ActorContext
    {
        $actor = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_ACTOR);

        if (!$actor instanceof ActorContext) {
            throw new \LogicException(
                'A mailing list handler ran without a verified actor. The authentication '
                . 'middleware must be in the dispatcher’s route-middleware chain.',
            );
        }

        return $actor;
    }

    /**
     * @param Page<MailingList> $page
     */
    public function collection(Page $page): ResponseInterface
    {
        return $this->json(200, MailingListView::collection($page));
    }

    public function single(MailingList $list): ResponseInterface
    {
        return $this->json(200, MailingListView::representation($list))
            ->withHeader('ETag', MailingListView::etag($list));
    }

    /**
     * `201` with `Location`, and the `ETag` of what was just written so that a
     * client can send `If-Match` on its next change without a round trip.
     */
    public function created(ServerRequestInterface $request, MailingList $list): ResponseInterface
    {
        $location = rtrim($request->getUri()->getPath(), '/') . '/' . rawurlencode($list->address);

        return $this->withEffect(
            $this->json(201, MailingListView::representation($list))
                ->withHeader('Location', $location)
                ->withHeader('ETag', MailingListView::etag($list)),
        );
    }

    public function changed(MailingList $list): ResponseInterface
    {
        return $this->single($list);
    }

    public function deleted(): ResponseInterface
    {
        return $this->withEffect(
            $this->responses
                ->createResponse(204)
                ->withHeader('Cache-Control', 'no-store'),
        );
    }

    public function failure(ServerRequestInterface $request, MailingListFailure $failure): ResponseInterface
    {
        return $this->problems->create(new ProblemDetails(
            status: $failure->status,
            code: $failure->errorCode,
            title: self::titleFor($failure->status),
            detail: self::detailFor($failure->status),
            instance: $request->getUri()->getPath(),
            requestId: self::requestId($request),
            errors: $failure->errors,
        ));
    }

    /**
     * A `sort`, `order` or filter the repository does not offer.
     *
     * `422` and `request.validation_failed`, which is the code
     * {@see CriteriaError} already carries: the contract enumerates every
     * accepted value (U-20), so reaching this means the request named something
     * outside the enumeration. The message is logged by nobody and sent to
     * nobody — it names the repository's internals.
     */
    public function invalidCriteria(ServerRequestInterface $request, CriteriaError $error): ResponseInterface
    {
        return $this->problems->create(new ProblemDetails(
            status: 422,
            code: ErrorCode::VALIDATION_FAILED,
            title: 'Unprocessable Entity',
            detail: 'A query parameter names a value this collection does not offer.',
            instance: $request->getUri()->getPath(),
            requestId: self::requestId($request),
            errors: ['query' => $error->errorCode()],
        ));
    }

    /** Announces the pending configuration run, but only where one is pending. */
    private function withEffect(ResponseInterface $response): ResponseInterface
    {
        return $this->delivery->changesNeedConfigurationRun()
            ? $response->withHeader(self::EFFECT_HEADER, self::EFFECT_PENDING)
            : $response;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function json(int $status, array $document): ResponseInterface
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

    private static function titleFor(int $status): string
    {
        return match ($status) {
            404 => 'Not Found',
            409 => 'Conflict',
            412 => 'Precondition Failed',
            default => 'Unprocessable Entity',
        };
    }

    private static function detailFor(int $status): string
    {
        return match ($status) {
            // Word for word what the framework's own 404 says, so that a list
            // that is absent and a list that may not be seen are one answer.
            404 => 'No resource matches this path and method.',
            409 => 'The mailing list address is already in use.',
            412 => 'The mailing list has changed since the If-Match value was issued.',
            default => 'The request does not satisfy a rule for mailing lists.',
        };
    }

    private static function requestId(ServerRequestInterface $request): ?string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : null;
    }
}
