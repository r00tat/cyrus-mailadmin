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

    public static function addressTaken(string $address): self
    {
        return new self(
            MailingListErrorCode::ADDRESS_TAKEN,
            409,
            sprintf('The address "%s" is already in use.', $address),
        );
    }

    /**
     * The local part carries the recipient delimiter. A field error, because
     * it is something the form can point at.
     */
    public static function addressHasExtension(string $address): self
    {
        return new self(
            MailingListErrorCode::ADDRESS_HAS_EXTENSION,
            422,
            sprintf('The list address "%s" carries an address extension.', $address),
            ['body.address' => MailingListErrorCode::ADDRESS_HAS_EXTENSION],
        );
    }

    public static function unknownDomain(string $domain): self
    {
        return new self(
            MailingListErrorCode::UNKNOWN_DOMAIN,
            422,
            sprintf('The domain "%s" is not a managed domain.', $domain),
            ['body.address' => MailingListErrorCode::UNKNOWN_DOMAIN],
        );
    }

    /**
     * No such list — or a value that could never be the address of one.
     *
     * A path value that is not an address cannot be a list at all, and
     * answering `422` for it while answering `404` for an address that merely
     * does not exist would tell a caller which of the two it had guessed.
     */
    public static function notFound(string $address): self
    {
        return new self(
            MailingListErrorCode::NOT_FOUND,
            404,
            sprintf('No mailing list with the address "%s".', $address),
        );
    }

    public static function preconditionFailed(string $address): self
    {
        return new self(
            MailingListErrorCode::PRECONDITION_FAILED,
            412,
            sprintf('The mailing list "%s" has changed since the If-Match value was issued.', $address),
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

    /**
     * The member entry's position, where the error knows it — the same
     * `body.members.<n>` shape schema validation uses, which the forms already
     * resolve onto the member field.
     */
    private static function fieldFor(DomainError $error): string
    {
        return match ($error->code()) {
            DomainErrorCode::LIST_INVALID_TAG, DomainErrorCode::LIST_TAG_OUTSIDE_LATIN1 => 'tag',
            DomainErrorCode::LIST_INVALID_ADDRESS => 'address',
            DomainErrorCode::LIST_INVALID_MEMBER => 'members',
            DomainErrorCode::LIST_MEMBER_OUTSIDE_LATIN1 => self::memberField($error),
            default => 'name',
        };
    }

    private static function memberField(DomainError $error): string
    {
        $index = $error->context()['index'] ?? null;

        return is_int($index) ? 'members.' . $index : 'members';
    }
}
