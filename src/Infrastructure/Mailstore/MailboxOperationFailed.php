<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore;

use Throwable;

/**
 * A mailbox operation the server understood and refused, translated into the
 * fact behind the refusal.
 *
 * {@see \Mailadmin\Infrastructure\Mailstore\Imap\CommandFailed} says "the server
 * answered NO"; this says "the mailbox already exists". The translation happens
 * once, in the adapter, and only ever from the **response code** in brackets —
 * never from the English sentence next to it (RFC 5530). `NO Mailbox already
 * exists` is not a contract, `[ALREADYEXISTS]` is.
 *
 * The distinction earns its keep in two places: A2 has to tell "this account
 * cannot be created" from "the server is down", and A6 has to know whether
 * replaying an entry from the repair queue has any chance.
 */
final class MailboxOperationFailed extends MailstoreError
{
    private function __construct(
        string $errorCode,
        string $message,
        public readonly string $mailbox,
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, $message, $previous);
    }

    public static function alreadyExists(string $mailbox, ?Throwable $previous = null): self
    {
        return new self(
            ErrorCode::MAILBOX_EXISTS,
            sprintf('The mailbox "%s" already exists.', $mailbox),
            $mailbox,
            $previous,
        );
    }

    public static function notFound(string $mailbox, ?Throwable $previous = null): self
    {
        return new self(
            ErrorCode::MAILBOX_NOT_FOUND,
            sprintf('There is no mailbox "%s".', $mailbox),
            $mailbox,
            $previous,
        );
    }

    /**
     * The server refused to delete a mailbox that is demonstrably still there.
     *
     * Retryable, unlike everything else here, and for a concrete reason found
     * against F5: with `delete_mode: delayed` — Cyrus's default — `DELETE`
     * renames the mailbox into `DELETED/<name>/<hex seconds>`. Two deletes of
     * the same mailbox inside one second therefore collide on that target and
     * the second is refused with, of all things, "Mailbox already exists". A
     * moment later the same command works.
     */
    public static function deletionRefused(
        string $mailbox,
        string $reason,
        ?Throwable $previous = null,
    ): self {
        return new self(
            ErrorCode::COMMAND_FAILED,
            sprintf('The mailbox "%s" is still there after DELETE: %s', $mailbox, $reason),
            $mailbox,
            $previous,
        );
    }

    /**
     * The server refused to move an account's mailbox at all.
     *
     * **M2, open point 6, answered against F5 (Cyrus 3.10.2):** Cyrus *does*
     * rename a user mailbox with its folders, and across domain namespaces too
     * — but only with `allowusermoves: yes`, which is **not** the default. With
     * it off, every `RENAME user/a user/b` is refused with "Operation is not
     * supported on mailbox".
     *
     * That is a deployment fault, not a fact about this account, and the
     * distinction matters: architecture 8.1 makes `renamemailbox` the first half
     * of a domain rename and of a naming-scheme change (E-14). A caller that
     * read this as "this account cannot be renamed" would go on to change the
     * database alone and leave the mailbox unreachable — the legacy system's bug,
     * reproduced by configuration. So the message names the setting.
     */
    public static function userMoveRefused(
        string $source,
        string $target,
        ?Throwable $previous = null,
    ): self {
        return new self(
            ErrorCode::UNSUPPORTED_NAMESPACE,
            sprintf(
                'The server refused to move the account mailbox "%s" to "%s". Cyrus needs '
                . '`allowusermoves: yes` for this; without it no account can be renamed and '
                . 'no domain can be renamed either. The database must not be changed on its '
                . 'own — the account would become unreachable (architecture 8.1).',
                $source,
                $target,
            ),
            $source,
            $previous,
        );
    }

    public static function quotaRejected(
        string $mailbox,
        string $reason,
        ?Throwable $previous = null,
    ): self {
        return new self(
            ErrorCode::QUOTA_REJECTED,
            sprintf('The quota on "%s" was refused: %s', $mailbox, $reason),
            $mailbox,
            $previous,
        );
    }

    public static function aclRejected(
        string $mailbox,
        string $subject,
        string $reason,
        ?Throwable $previous = null,
    ): self {
        return new self(
            ErrorCode::ACL_REJECTED,
            sprintf('The ACL for "%s" on "%s" was refused: %s', $subject, $mailbox, $reason),
            $mailbox,
            $previous,
        );
    }

    /**
     * The server's hierarchy separator is not the one every mailbox name in this
     * application is built with.
     *
     * Cyrus with `unixhierarchysep: no` uses `.`, and a `.` is also legal inside
     * a local part — so `user.greta.mustermann.example.at` would be ambiguous in
     * a way no amount of care downstream can fix. Refusing at connect time is
     * the only honest answer.
     */
    public static function unsupportedSeparator(string $endpoint, string $separator): self
    {
        return new self(
            ErrorCode::UNSUPPORTED_NAMESPACE,
            sprintf(
                '%s separates its hierarchy with "%s"; mailadmin requires "/" '
                . '(Cyrus `unixhierarchysep: yes`).',
                $endpoint,
                $separator,
            ),
            '',
        );
    }

    /**
     * Most of these do not get better by being repeated: a mailbox that exists
     * will still exist, and a separator is a server configuration.
     *
     * {@see deletionRefused()} is the exception, and the only one — see its
     * comment for the second-boundary collision that makes a retry the right
     * answer there.
     */
    public function isRetryable(): bool
    {
        return $this->errorCode() === ErrorCode::COMMAND_FAILED;
    }
}
