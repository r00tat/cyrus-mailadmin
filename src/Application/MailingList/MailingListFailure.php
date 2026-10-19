<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Exception\DomainErrorCode;
use Mailadmin\Domain\Exception\InvalidMailingList;
use RuntimeException;

/**
 * A mailing list operation was refused.
 *
 * Carries the stable code, the HTTP status and — for a rule violation — the
 * `errors` map, so that the decision about *what happened* stays in the use case
 * and the handler only renders it. Same shape as
 * {@see \Mailadmin\Application\Auth\AuthenticationFailed}, and for the same
 * reason: a handler that decided status codes would decide them differently in
 * the next package.
 *
 * The English message is for the log. The client gets the code and translates it
 * (E-16).
 *
 * **What is deliberately absent is an authorisation case.** A role that may not
 * manage lists and an object outside an actor's scope are both answered by
 * {@see \Mailadmin\Http\Middleware\AuthorizationMiddleware}, from the policy
 * exceptions it catches (U-44). A second way to produce `403` here would be a
 * second place for the 403/404 split to be got wrong.
 */
final class MailingListFailure extends RuntimeException
{
    /**
     * @param array<string, string> $errors field path => the constraint that failed
     */
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public static function alreadyExists(string $name): self
    {
        return new self(
            MailingListErrorCode::ALREADY_EXISTS,
            409,
            sprintf('A mailing list named "%s" already exists.', $name),
        );
    }

    /**
     * No such list — or a name that could never be one.
     *
     * A name carrying whitespace or a colon cannot be a row in `lists` at all
     * ({@see \Mailadmin\Domain\MailingList\MailingListName}), and answering `422`
     * for it while answering `404` for a name that merely does not exist would
     * tell a caller which of the two it had guessed.
     */
    public static function notFound(string $name): self
    {
        return new self(
            MailingListErrorCode::NOT_FOUND,
            404,
            sprintf('No mailing list named "%s".', $name),
        );
    }

    public static function preconditionFailed(string $name): self
    {
        return new self(
            MailingListErrorCode::PRECONDITION_FAILED,
            412,
            sprintf('The mailing list "%s" has changed since the If-Match value was issued.', $name),
        );
    }

    /**
     * A value the domain model refused.
     *
     * The offending field is named through `errors` so that a form can point at
     * it; the value itself is never echoed back, which is why the map carries the
     * domain error's code rather than its context.
     *
     * The field is derived from the code rather than passed in by the caller.
     * The code is the stable thing — a rearranged constructor or a second call
     * site would otherwise start blaming a different field for the same error.
     */
    public static function rejected(InvalidMailingList $error): self
    {
        return new self(
            $error->code(),
            422,
            $error->getMessage(),
            ['body.' . self::fieldFor($error) => $error->code()],
        );
    }

    private static function fieldFor(DomainError $error): string
    {
        return match ($error->code()) {
            DomainErrorCode::LIST_INVALID_TAG => 'tag',
            DomainErrorCode::LIST_INVALID_ADDRESS => 'address',
            DomainErrorCode::LIST_INVALID_MEMBER => 'members',
            default => 'name',
        };
    }
}
