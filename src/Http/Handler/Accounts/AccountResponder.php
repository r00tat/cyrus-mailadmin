<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Accounts;

use Mailadmin\Application\Account\AccountCreated;
use Mailadmin\Application\Account\AccountErrorCode;
use Mailadmin\Application\Account\AccountOperationFailed;
use Mailadmin\Application\Account\AccountRecord;
use Mailadmin\Application\Account\AccountView;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Password\InvalidPassword;
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
 * Rendering for the eight account handlers — the rules of A1's
 * `DomainResponder`, for accounts.
 *
 * **No exception message reaches a client.** {@see AccountOperationFailed}
 * names the account, the domain, the counts; that goes to the log, the client
 * gets the stable code and a fixed text (E-16).
 *
 * **A `404` is always the same `404`**, from {@see ProblemDetails::notFound()}
 * (U-44).
 *
 * **A password is never echoed.** {@see invalidPassword()} carries the code of
 * the rule that failed and nothing of the input.
 */
final readonly class AccountResponder
{
    /** @var array<string, string> */
    private const array DETAILS = [
        AccountErrorCode::LIMIT_REACHED => 'The domain holds as many accounts as it may.',
        AccountErrorCode::MAILBOX_EXISTS => 'The mail store already has a mailbox under this name.',
        AccountErrorCode::MAILBOX_MISSING => 'The account has no mailbox in the mail store.',
        AccountErrorCode::MAILSTORE_UNAVAILABLE => 'The mail store could not carry out the step; nothing was changed.',
        AccountErrorCode::NAME_TAKEN => 'This address or user name is already taken.',
        AccountErrorCode::QUOTA_EXCEEDS_DOMAIN => 'The quota would exceed the allowance of the domain.',
        AccountErrorCode::SETUP_INCOMPLETE => 'The account could not be completed and was not created.',
        AccountErrorCode::USERNAME_TOO_LONG => 'The user name this account would get is too long to store.',
        AccountErrorCode::IDENTITY_MISMATCH => 'The stored user name of this account does not match its domain; it can only be read.',
        AccountErrorCode::PRECONDITION_REQUIRED => 'This operation requires an If-Match header.',
        AccountErrorCode::PRECONDITION_FAILED => 'The account has changed since it was last read.',
    ];

    /** Spelled out for the reason `DomainResponder::API_PREFIX` gives. */
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
        private LoggerInterface $logger,
        private string $basePath,
    ) {
    }

    public function actor(ServerRequestInterface $request): ActorContext
    {
        $actor = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_ACTOR);

        if (!$actor instanceof ActorContext) {
            throw new \LogicException(
                'An account handler ran without a verified actor. AuthenticationMiddleware must be '
                . 'in the dispatcher’s route-middleware chain.',
            );
        }

        return $actor;
    }

    /**
     * @param Page<AccountRecord> $page
     */
    public function collection(Page $page): ResponseInterface
    {
        return $this->json(200, [
            'items' => array_map(static fn (AccountRecord $record): array => AccountView::full($record), $page->items),
            'meta' => $page->meta(),
        ]);
    }

    public function account(AccountRecord $record): ResponseInterface
    {
        return $this
            ->json(200, AccountView::full($record))
            ->withHeader('ETag', AccountView::etag($record));
    }

    public function created(AccountCreated $created): ResponseInterface
    {
        return $this
            ->json(201, [
                'account' => AccountView::full($created->account),
                'incomplete_steps' => $created->incompleteSteps,
            ])
            ->withHeader('ETag', AccountView::etag($created->account))
            ->withHeader('Location', $this->locationOf($created->account));
    }

    public function quota(Quota $quota): ResponseInterface
    {
        return $this->json(200, AccountView::quota($quota));
    }

    public function noContent(): ResponseInterface
    {
        return $this->responses
            ->createResponse(204)
            ->withHeader('Cache-Control', 'no-store');
    }

    public function failure(ServerRequestInterface $request, AccountOperationFailed $failure): ResponseInterface
    {
        $this->logger->info('An account operation was refused.', [
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

    /**
     * A value the contract accepted and the model did not — a local part with
     * characters a mailbox name cannot carry, for instance. The value is never
     * echoed.
     */
    public function invalid(ServerRequestInterface $request, DomainError $error, string $field): ResponseInterface
    {
        $this->logger->info('An account request carried a value the model rejected.', [
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
     * A password S1's rules refused. Not logged with its message: the message
     * of a personal-data refusal names the term that matched.
     */
    public function invalidPassword(ServerRequestInterface $request, InvalidPassword $error): ResponseInterface
    {
        return $this->problems->create(new ProblemDetails(
            status: 422,
            code: $error->code(),
            title: 'Unprocessable Content',
            detail: 'The password does not meet the rules for a mail account.',
            instance: $request->getUri()->getPath(),
            requestId: self::requestId($request),
            errors: ['body.password' => $error->code()],
        ));
    }

    public function badCriteria(ServerRequestInterface $request, CriteriaError $error): ResponseInterface
    {
        $this->logger->info('An account listing was asked for with an unusable criterion.', [
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

    public function notFound(ServerRequestInterface $request): ResponseInterface
    {
        return $this->problems->create(ProblemDetails::notFound(
            instance: $request->getUri()->getPath(),
            requestId: self::requestId($request),
        ));
    }

    public static function ifMatch(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('If-Match');

        return trim($header) === '' ? null : $header;
    }

    private function locationOf(AccountRecord $record): string
    {
        return rtrim($this->basePath, '/') . self::API_PREFIX . '/accounts/' . rawurlencode($record->storedUsername);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function json(int $status, array $document): ResponseInterface
    {
        $body = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

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
