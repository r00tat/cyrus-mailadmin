<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

use Mailadmin\Domain\Model\DomainName;
use RuntimeException;

/**
 * A domain operation was refused, with the code and status that say why.
 *
 * The same shape as S2's `AuthenticationFailed`: the use case decides **what
 * happened**, the handler only renders it. Keeping the status here rather than
 * in a `match` in the handler means the decision is next to the reason for it,
 * and a new refusal cannot be added without also deciding what it answers.
 *
 * ## What is deliberately *not* thrown from here
 *
 * `403` and the `404` for a foreign object. Those are
 * {@see \Mailadmin\Domain\Policy\AccessDenied} and
 * {@see \Mailadmin\Domain\Policy\ResourceNotVisible}, they are raised by the
 * policies, and the authorisation middleware turns them into responses (U-44).
 * A use case lets them travel; catching them here would be the first step
 * towards eight packages each spelling the `403`/`404` split their own way.
 *
 * {@see notFound()} is the one apparent exception, and it is not one: it is for
 * a domain that genuinely is not in the table. It carries the **same** code as
 * the scope refusal, and the handler renders it through the **same** factory,
 * so the two documents are identical. That identity is the requirement
 * (`CLAUDE.md`); a separate code would undo it.
 */
final class DomainOperationFailed extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * No such domain. Rendered exactly like a domain outside the actor's scope.
     */
    public static function notFound(DomainName $name): self
    {
        return new self(
            DomainErrorCode::NOT_FOUND,
            404,
            sprintf('There is no domain "%s".', $name->toString()),
        );
    }

    public static function nameTaken(DomainName $name): self
    {
        return new self(
            DomainErrorCode::NAME_TAKEN,
            409,
            sprintf('A domain named "%s" already exists.', $name->toString()),
        );
    }

    public static function prefixTaken(DomainName $name): self
    {
        return new self(
            DomainErrorCode::PREFIX_TAKEN,
            409,
            sprintf(
                'No free value is left for the unique legacy column domain.prefix for "%s". '
                . 'Every derived candidate is taken, which means the column holds values this '
                . 'application did not write.',
                $name->toString(),
            ),
        );
    }

    public static function notEmpty(DomainName $name, int $accountCount): self
    {
        return new self(
            DomainErrorCode::NOT_EMPTY,
            409,
            sprintf(
                'The domain "%s" still has %d account(s) and was not deleted.',
                $name->toString(),
                $accountCount,
            ),
        );
    }

    public static function stillHasAddresses(DomainName $name, int $addressCount): self
    {
        return new self(
            DomainErrorCode::STILL_HAS_ADDRESSES,
            409,
            sprintf(
                'The domain "%s" still has %d address(es), forward(s) or a catchall and was not deleted; '
                . 'GET /domains/{domain}/addresses lists them (A3).',
                $name->toString(),
                $addressCount,
            ),
        );
    }

    /**
     * `$name` and an existing account would share a Cyrus user name (U-96).
     * The account is named for the log; it is not secret to a superuser, the
     * only role that creates or renames domains.
     */
    public static function namespaceConflict(DomainName $name, string $username): self
    {
        return new self(
            DomainErrorCode::NAMESPACE_CONFLICT,
            409,
            sprintf(
                'The name "%s" would make the user name "%s" ambiguous in the dotted naming scheme.',
                $name->toString(),
                $username,
            ),
        );
    }

    /**
     * Rows of an earlier domain of this name are still there (U-99). The
     * counts are for the log and the superuser; nothing of them is secret to
     * the only role that creates or renames domains.
     */
    public static function leftoverState(DomainName $name, int $assignments, int $addresses): self
    {
        return new self(
            DomainErrorCode::LEFTOVER_STATE,
            409,
            sprintf(
                'The name "%s" is not a domain, but %d administrator assignment(s) and %d address(es), '
                . 'forward(s) or catchall(s) of an earlier domain of that name are still there. '
                . 'They would apply to the new domain; remove them first.',
                $name->toString(),
                $assignments,
                $addresses,
            ),
        );
    }

    /**
     * The accounts of the domain changed while its mailboxes were being
     * renamed (U-100): the plan no longer covers every account, so the
     * database half would strand the newcomer. Raised under the namespace
     * lock, after which the mailboxes go back.
     */
    public static function accountsChangedDuringRename(DomainName $name): self
    {
        return new self(
            DomainErrorCode::RENAME_INCOMPLETE,
            409,
            sprintf(
                'The accounts of domain "%s" changed while its mailboxes were being renamed. '
                . 'The renamed mailboxes were moved back; nothing changed. Try again.',
                $name->toString(),
            ),
        );
    }

    public static function renameNotConfirmed(DomainName $from, DomainName $to): self
    {
        return new self(
            DomainErrorCode::RENAME_NOT_CONFIRMED,
            422,
            sprintf(
                'Renaming "%s" to "%s" renames every mailbox of the domain and changes the user '
                . 'name each owner signs in with. It needs confirm_rename.',
                $from->toString(),
                $to->toString(),
            ),
        );
    }

    public static function mailstoreUnavailable(DomainName $name, string $reason): self
    {
        return new self(
            DomainErrorCode::MAILSTORE_UNAVAILABLE,
            503,
            sprintf(
                'The mail store could not be reached, so "%s" was not renamed: %s',
                $name->toString(),
                $reason,
            ),
        );
    }

    /**
     * The rename stopped part way. `$compensated` says whether everything that
     * had already been done was undone.
     */
    public static function renameIncomplete(DomainName $name, string $reason, bool $compensated): self
    {
        return new self(
            DomainErrorCode::RENAME_INCOMPLETE,
            409,
            sprintf(
                'Renaming "%s" stopped part way: %s %s',
                $name->toString(),
                $reason,
                $compensated
                    ? 'Everything already done was undone; nothing changed.'
                    : 'Not everything could be undone; the repair queue names what is where.',
            ),
        );
    }

    /**
     * An account of the domain cannot be given a new name, so the rename would
     * strand it. Raised before anything is touched.
     */
    public static function renameBlockedByAccount(DomainName $name, string $username, string $reason): self
    {
        return new self(
            DomainErrorCode::RENAME_INCOMPLETE,
            409,
            sprintf(
                'The account "%s" of domain "%s" has a stored user name that cannot be resolved '
                . 'against its domain (%s), so its new name cannot be derived. Nothing was changed.',
                $username,
                $name->toString(),
                $reason,
            ),
        );
    }

    /**
     * The domain still has mailing lists, whose addresses a rename would not
     * carry along. Raised before anything is touched.
     */
    public static function renameBlockedByMailingList(DomainName $name, int $lists): self
    {
        return new self(
            DomainErrorCode::RENAME_BLOCKED_BY_MAILING_LIST,
            409,
            sprintf(
                'The domain "%s" still has %d mailing list(s). A rename would not carry them along; '
                . 'delete them first and create them again under the new name. Nothing was changed.',
                $name->toString(),
                $lists,
            ),
        );
    }

    /**
     * `If-Match` is required by this operation and was not sent (U-19, U-49).
     */
    public static function preconditionRequired(): self
    {
        return new self(
            DomainErrorCode::PRECONDITION_REQUIRED,
            428,
            'This operation requires an If-Match header carrying the ETag of the domain as the '
            . 'client last saw it.',
        );
    }

    public static function preconditionFailed(): self
    {
        return new self(
            DomainErrorCode::PRECONDITION_FAILED,
            412,
            'The domain has changed since the client last read it.',
        );
    }
}
