<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Addresses;

use Mailadmin\Application\Account\AccountErrorCode;
use Mailadmin\Application\Address\AddressErrorCode;
use Mailadmin\Application\Address\AddressListing;
use Mailadmin\Application\Address\AddressOperationFailed;
use Mailadmin\Application\Address\AddressView;
use Mailadmin\Application\Address\AliasState;
use Mailadmin\Application\Address\CatchallState;
use Mailadmin\Application\Address\ForwardState;
use Mailadmin\Application\Address\VacationState;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\Virtual\VirtualEntry;
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
 * Rendering for the fifteen A3 handlers — the rules of A1's `DomainResponder`
 * and A2's `AccountResponder`.
 *
 * **No exception message reaches a client**: the code and a fixed text do
 * (E-16). **A `404` is always the same `404`**, from
 * {@see ProblemDetails::notFound()} (U-44). **A refused value is not echoed**:
 * a destination or an address in a `422` is named by its field, not repeated.
 */
final readonly class AddressResponder
{
    /** @var array<string, string> */
    private const array DETAILS = [
        AddressErrorCode::NOT_EDITABLE => 'The rows under this address cannot be changed as one; they are listed one by one.',
        AddressErrorCode::RESERVED => 'This address is one a mail domain is expected to keep; removing or disabling it needs confirm_reserved.',
        AddressErrorCode::TAKEN => 'This address is already in use.',
        AddressErrorCode::UNSTORABLE => 'The address cannot be stored in this form.',
        AddressErrorCode::FORWARD_COPY_REQUIRED => 'This account always keeps a copy; a forward without one cannot be honoured.',
        AddressErrorCode::VACATION_FOREIGN_SCRIPT => 'The active Sieve script was not written by mailadmin; it is replaced only with take_over.',
        AddressErrorCode::VACATION_MAILSTORE_UNAVAILABLE => 'The Sieve service could not carry out the step.',
        AddressErrorCode::VACATION_NOT_SUPPORTED => 'The Sieve service does not offer vacation notices.',
        AddressErrorCode::VACATION_REJECTED => 'The Sieve service refused the vacation notice.',
        AddressErrorCode::PRECONDITION_FAILED => 'The resource has changed since it was last read.',
        AccountErrorCode::IDENTITY_MISMATCH => 'The stored user name of this account does not match its domain; it can only be read.',
    ];

    /** Spelled out for the reason `DomainResponder::API_PREFIX` gives. */
    private const string API_PREFIX = '/api/v1';

    /** @var array<int, string> */
    private const array TITLES = [
        409 => 'Conflict',
        412 => 'Precondition Failed',
        422 => 'Unprocessable Content',
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
                'An address handler ran without a verified actor. AuthenticationMiddleware must be '
                . 'in the dispatcher’s route-middleware chain.',
            );
        }

        return $actor;
    }

    public function listing(AddressListing $listing): ResponseInterface
    {
        return $this->json(200, [
            'items' => array_map(
                static fn (VirtualEntry $entry): array => AddressView::entry($entry, $listing->rows),
                $listing->page->items,
            ),
            'meta' => $listing->page->meta(),
        ]);
    }

    public function alias(AliasState $state): ResponseInterface
    {
        return $this->withEtag(200, AddressView::alias($state));
    }

    public function aliasCreated(AliasState $state): ResponseInterface
    {
        return $this
            ->withEtag(201, AddressView::alias($state))
            ->withHeader(
                'Location',
                rtrim($this->basePath, '/') . self::API_PREFIX . '/aliases/' . rawurlencode($state->address->toString()),
            );
    }

    public function catchall(CatchallState $state): ResponseInterface
    {
        return $this->withEtag(200, AddressView::catchall($state));
    }

    public function forwards(ForwardState $state): ResponseInterface
    {
        return $this->withEtag(200, AddressView::forwards($state));
    }

    public function vacation(VacationState $state): ResponseInterface
    {
        return $this->withEtag(200, AddressView::vacation($state));
    }

    public function noContent(): ResponseInterface
    {
        return $this->responses
            ->createResponse(204)
            ->withHeader('Cache-Control', 'no-store');
    }

    public function failure(ServerRequestInterface $request, AddressOperationFailed $failure): ResponseInterface
    {
        $this->logger->info('An address operation was refused.', [
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
     * A value the contract accepted and the model did not. The value is never
     * echoed; the log carries the model's message, which names it.
     */
    public function invalid(ServerRequestInterface $request, DomainError $error, string $field): ResponseInterface
    {
        $this->logger->info('An address request carried a value the model rejected.', [
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

    public function badCriteria(ServerRequestInterface $request, CriteriaError $error): ResponseInterface
    {
        $this->logger->info('An address listing was asked for with an unusable criterion.', [
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

    /**
     * The request field a domain error is about — so that a client can put the
     * message next to the right input.
     */
    public static function fieldOf(DomainError $error, string $default = 'body'): string
    {
        return match ($error->code()) {
            'address.invalid_destination' => 'body.destinations',
            'address.invalid_local_part' => 'body.local_part',
            default => $default,
        };
    }

    /**
     * @param array<string, mixed> $representation
     */
    private function withEtag(int $status, array $representation): ResponseInterface
    {
        return $this
            ->json($status, $representation)
            ->withHeader('ETag', AddressView::etag($representation));
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
