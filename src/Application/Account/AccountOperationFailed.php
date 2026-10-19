<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Infrastructure\Mailstore\ErrorCode as MailstoreErrorCode;
use RuntimeException;

/**
 * An account operation was refused, with the code and status that say why.
 *
 * The shape of A1's `DomainOperationFailed`: the use case decides what
 * happened, the handler renders it. The message is for the log; the client
 * gets the code and a fixed text.
 *
 * `403` and the `404` for a foreign account are not thrown from here — they are
 * the policies' `AccessDenied` and `ResourceNotVisible` (U-44). {@see notFound()}
 * is for an account that genuinely does not exist and is rendered through the
 * same factory as the scope refusal.
 */
final class AccountOperationFailed extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what): self
    {
        return new self(AccountErrorCode::NOT_FOUND, 404, sprintf('There is no %s.', $what));
    }

    public static function nameTaken(string $address, string $cyrusUsername): self
    {
        return new self(
            AccountErrorCode::NAME_TAKEN,
            409,
            sprintf('The address "%s" or the user name "%s" is already taken.', $address, $cyrusUsername),
        );
    }

    public static function limitReached(string $domain, int $count, ?int $maximum): self
    {
        return new self(
            AccountErrorCode::LIMIT_REACHED,
            409,
            sprintf(
                'The domain "%s" holds %d account(s) and may hold %s.',
                $domain,
                $count,
                $maximum === null ? 'any number' : (string) $maximum,
            ),
        );
    }

    public static function usernameTooLong(string $cyrusUsername, int $limit): self
    {
        return new self(
            AccountErrorCode::USERNAME_TOO_LONG,
            422,
            sprintf(
                'The Cyrus user name "%s" has %d characters; virtual.username holds %d.',
                $cyrusUsername,
                strlen($cyrusUsername),
                $limit,
            ),
        );
    }

    public static function quotaExceedsDomain(string $domain, ?int $requested, int $allocated, int $allowance): self
    {
        return new self(
            AccountErrorCode::QUOTA_EXCEEDS_DOMAIN,
            409,
            sprintf(
                'A limit of %s KiB does not fit into the allowance of "%s": the other accounts '
                . 'hold %d KiB of %d KiB.',
                $requested === null ? 'no' : (string) $requested,
                $domain,
                $allocated,
                $allowance,
            ),
        );
    }

    public static function identityMismatch(string $storedUsername, string $domain): self
    {
        return new self(
            AccountErrorCode::IDENTITY_MISMATCH,
            409,
            sprintf(
                'The stored user name "%s" cannot be resolved against the domain "%s", so its '
                . 'mailbox cannot be derived. The account can be read, not changed.',
                $storedUsername,
                $domain,
            ),
        );
    }

    /**
     * Rows the operation read changed before it could write — someone outside
     * the namespace lock (webcyradm, a hand-made statement). Nothing changed.
     */
    public static function changedMeanwhile(string $what): self
    {
        return new self(
            AccountErrorCode::CONFLICT,
            409,
            sprintf('The rows of "%s" changed while the account was being deleted. Nothing was changed.', $what),
        );
    }

    public static function statusNotEnforced(string $storedUsername): self
    {
        return new self(
            AccountErrorCode::STATUS_NOT_ENFORCED,
            409,
            sprintf(
                'The view allusers still offers a login for "%s" after it was marked disabled: the '
                . 'deployed view does not honour mailadmin_account_status. Nothing was changed.',
                $storedUsername,
            ),
        );
    }

    public static function setupIncomplete(string $cyrusUsername, string $stage, bool $compensated): self
    {
        return new self(
            AccountErrorCode::SETUP_INCOMPLETE,
            409,
            sprintf(
                'Creating "%s" stopped at %s. %s',
                $cyrusUsername,
                $stage,
                $compensated
                    ? 'The mailbox was removed again; nothing changed.'
                    : 'The mailbox could not be removed; the repair queue names it.',
            ),
        );
    }

    /**
     * A mail-store failure **before** anything was changed, translated into the
     * code that tells a client what it can do about it.
     */
    public static function fromMailstore(MailstoreFailure $failure, string $mailbox): self
    {
        return match ($failure->errorCode()) {
            MailstoreErrorCode::MAILBOX_EXISTS => new self(
                AccountErrorCode::MAILBOX_EXISTS,
                409,
                sprintf('Cyrus already has a mailbox "%s" that no account claims.', $mailbox),
            ),
            MailstoreErrorCode::MAILBOX_NOT_FOUND => new self(
                AccountErrorCode::MAILBOX_MISSING,
                409,
                sprintf('Cyrus has no mailbox "%s".', $mailbox),
            ),
            default => new self(
                AccountErrorCode::MAILSTORE_UNAVAILABLE,
                503,
                sprintf('The mail store failed on "%s": %s', $mailbox, $failure->errorCode()),
            ),
        };
    }

    public static function preconditionRequired(): self
    {
        return new self(
            AccountErrorCode::PRECONDITION_REQUIRED,
            428,
            'This operation requires an If-Match header carrying the ETag of the account as the '
            . 'client last saw it.',
        );
    }

    public static function preconditionFailed(): self
    {
        return new self(
            AccountErrorCode::PRECONDITION_FAILED,
            412,
            'The account has changed since the client last read it.',
        );
    }
}
