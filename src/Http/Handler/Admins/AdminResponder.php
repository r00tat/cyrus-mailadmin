<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Admins;

use Mailadmin\Application\Admin\AdminAccount;
use Mailadmin\Application\Admin\AdminErrorCode;
use Mailadmin\Application\Admin\AdminEtag;
use Mailadmin\Application\Admin\AdminOperationFailed;
use Mailadmin\Application\Admin\AdminPreferences;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Password\InvalidPassword;
use Mailadmin\Http\Middleware\AuthenticationMiddleware;
use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\Problem\ErrorCode;
use Mailadmin\Http\Problem\ProblemDetails;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Infrastructure\Persistence\Page;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * What A4's six handlers have in common: rendering, the `ETag`, and turning a
 * refused rule into a problem document.
 *
 * Written once for the reason S2's {@see \Mailadmin\Http\Handler\Auth\SessionResponder}
 * was: six handlers that each got the `ETag` and the `If-Match` comparison
 * slightly differently would be six chances to get concurrency control wrong,
 * and the symptom would be a `412` that nobody can reproduce.
 *
 * ## The `ETag` is computed elsewhere
 *
 * {@see AdminEtag} hashes the representation (U-19), and it lives in the
 * application layer beside the representation itself — the precondition is
 * enforced in the use case, so the hashing had to be reachable from there. This
 * class only puts the result in the header.
 *
 * ## What never reaches the client
 *
 * The English `detail` texts below are fallbacks, not contract (E-16); the
 * `code` is what a frontend translates. None of them repeats a value from the
 * request, and none can name a password: {@see AdminOperationFailed} and
 * {@see InvalidPassword} are constructed without one.
 */
final readonly class AdminResponder
{
    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private ProblemResponseFactory $problems,
    ) {
    }

    /**
     * The verified actor, which the authentication middleware put there.
     *
     * A missing one is a {@see \LogicException} and therefore a `500`: none of
     * these operations is public, so an unauthenticated request never gets this
     * far. Answering `401` here would be a lie about what went wrong.
     */
    public static function actorOf(ServerRequestInterface $request): ActorContext
    {
        $actor = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_ACTOR);

        if (!$actor instanceof ActorContext) {
            throw new \LogicException(
                'An admin handler ran without a verified actor. The authentication middleware '
                . 'must be in the dispatcher’s route-middleware chain.',
            );
        }

        return $actor;
    }

    /**
     * A path parameter, as the dispatcher matched it.
     */
    public static function pathParameter(ServerRequestInterface $request, string $name): string
    {
        /** @var mixed $parameters */
        $parameters = $request->getAttribute(\Mailadmin\Http\Dispatcher::ATTRIBUTE_PATH_PARAMETERS);

        if (!is_array($parameters) || !is_string($parameters[$name] ?? null)) {
            throw new \LogicException(sprintf(
                'An admin handler expected the path parameter "%s" and the dispatcher did not '
                . 'provide it. The contract and the handler disagree.',
                $name,
            ));
        }

        return $parameters[$name];
    }

    public function admin(
        AdminAccount $admin,
        int $status = 200,
        ?string $location = null,
    ): ResponseInterface {
        $response = $this->json($status, $admin->representation())
            ->withHeader('ETag', AdminEtag::of($admin));

        return $location === null ? $response : $response->withHeader('Location', $location);
    }

    /**
     * @param Page<AdminAccount> $page
     */
    public function collection(Page $page): ResponseInterface
    {
        return $this->json(200, [
            'items' => array_map(static fn (AdminAccount $a): array => $a->representation(), $page->items),
            'meta' => $page->meta(),
        ]);
    }

    /**
     * `AdminPreferences` (W2). No `ETag`: the only writer is the administrator
     * themselves, and the operation takes no `If-Match` (U-90).
     */
    public function preferences(AdminPreferences $preferences): ResponseInterface
    {
        return $this->json(200, $preferences->representation());
    }

    public function noContent(): ResponseInterface
    {
        return $this->responses->createResponse(204)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Input the contract should already have rejected — a page number outside
     * the criteria object's range, a role that is not one of the two.
     *
     * The second lock rather than the first: request validation runs in front of
     * every handler and declares both bounds. It carries F2's
     * `request.validation_failed`, the same code the validator would have used,
     * because from a client's point of view the same thing happened.
     */
    public function unprocessable(ServerRequestInterface $request, string $detail): ResponseInterface
    {
        return $this->problem(
            $request,
            422,
            ErrorCode::VALIDATION_FAILED,
            'Unprocessable Content',
            $detail,
        );
    }

    public function failure(ServerRequestInterface $request, AdminOperationFailed $failure): ResponseInterface
    {
        return $this->problem(
            $request,
            $failure->status,
            $failure->errorCode,
            self::titleFor($failure->status),
            self::detailFor($failure->errorCode),
        );
    }

    /**
     * A password the policy refused (U-21). Always `422`: the request was
     * understood and is not acceptable.
     *
     * The `code` is S1's (`auth.password_too_short` and friends) rather than one
     * of A4's — the rule is the same rule wherever a password is set, and a
     * second key for it would be a second thing to translate.
     */
    public function invalidPassword(ServerRequestInterface $request, InvalidPassword $error): ResponseInterface
    {
        return $this->problem(
            $request,
            422,
            $error->code(),
            'Unprocessable Content',
            'The password does not meet the rules for an administrator account.',
        );
    }

    private function problem(
        ServerRequestInterface $request,
        int $status,
        string $code,
        string $title,
        string $detail,
    ): ResponseInterface {
        return $this->problems->create(new ProblemDetails(
            status: $status,
            code: $code,
            title: $title,
            detail: $detail,
            instance: $request->getUri()->getPath(),
            requestId: self::requestId($request),
        ));
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
            422 => 'Unprocessable Content',
            default => 'Bad Request',
        };
    }

    /**
     * English fallback text, not a contract (E-16).
     */
    private static function detailFor(string $code): string
    {
        return match ($code) {
            AdminErrorCode::NOT_FOUND => 'There is no administrator with this name.',
            AdminErrorCode::USERNAME_TAKEN => 'An administrator with this name already exists.',
            AdminErrorCode::LAST_SUPERUSER
                => 'This is the last superuser. Give somebody else the role first.',
            AdminErrorCode::SELF_DELETION
                => 'An administrator cannot delete their own account. Ask another superuser.',
            AdminErrorCode::ROLE_SCOPE_MISMATCH
                => 'A superuser sees every domain and is given none; a domain master needs at least one.',
            AdminErrorCode::UNKNOWN_DOMAIN => 'One of the domains does not exist.',
            AdminErrorCode::WRONG_CURRENT_PASSWORD => 'The current password is not correct.',
            AdminErrorCode::PRECONDITION_FAILED
                => 'The administrator was changed by somebody else. Read it again and retry.',
            default => 'The request could not be carried out.',
        };
    }

    private static function requestId(ServerRequestInterface $request): ?string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : null;
    }
}
