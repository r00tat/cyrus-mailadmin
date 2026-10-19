<?php

declare(strict_types=1);

namespace Mailadmin\Application\Admin;

use RuntimeException;

/**
 * A rule of A4 refused the operation.
 *
 * Carries the stable code and the HTTP status, so that *what happened* is
 * decided in the use case and the handler only renders it — the arrangement
 * {@see \Mailadmin\Application\Auth\AuthenticationFailed} established.
 *
 * The message is an English log line. It never names a password and never
 * carries anything the client has not already sent.
 *
 * **This is not an authorisation failure.** Those are
 * {@see \Mailadmin\Domain\Policy\AccessDenied} and
 * {@see \Mailadmin\Domain\Policy\ResourceNotVisible}, which A4 lets run past it
 * so that `AuthorizationMiddleware` turns them into `403` and `404` — one place
 * spells that split (U-44). What this class covers is everything that is
 * permitted but wrong: a name already taken, the last superuser, a domain that
 * does not exist.
 */
final class AdminOperationFailed extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $username): self
    {
        return new self(
            AdminErrorCode::NOT_FOUND,
            404,
            sprintf('There is no administrator named "%s".', self::excerpt($username)),
        );
    }

    public static function usernameTaken(string $username): self
    {
        return new self(
            AdminErrorCode::USERNAME_TAKEN,
            409,
            sprintf('The administrator name "%s" is already in use.', self::excerpt($username)),
        );
    }

    /**
     * The last superuser may be neither deleted nor demoted.
     *
     * Counted in the database rather than inferred from the actor: "am I the
     * only one?" is a question about the table, and the answer changes without
     * this session noticing.
     */
    public static function lastSuperuser(string $username): self
    {
        return new self(
            AdminErrorCode::LAST_SUPERUSER,
            409,
            sprintf(
                'The administrator "%s" is the last superuser and may not be removed or demoted.',
                self::excerpt($username),
            ),
        );
    }

    public static function selfDeletion(string $username): self
    {
        return new self(
            AdminErrorCode::SELF_DELETION,
            409,
            sprintf('The administrator "%s" may not delete their own account.', self::excerpt($username)),
        );
    }

    /**
     * A superuser was given named domains.
     */
    public static function superuserWithDomains(string $username): self
    {
        return new self(
            AdminErrorCode::ROLE_SCOPE_MISMATCH,
            422,
            sprintf(
                'The superuser "%s" cannot be given individual domains: a superuser sees every domain.',
                self::excerpt($username),
            ),
        );
    }

    /**
     * A domain master was left with no domain at all.
     *
     * Such an account cannot act: `AdminDirectory::findActor()` answers `null`
     * and the sign-in refuses it. Writing the state would be a way to lock
     * somebody out with no visible cause.
     */
    public static function domainMasterWithoutDomains(string $username): self
    {
        return new self(
            AdminErrorCode::ROLE_SCOPE_MISMATCH,
            422,
            sprintf(
                'The domain master "%s" needs at least one domain; an empty scope cannot sign in.',
                self::excerpt($username),
            ),
        );
    }

    /**
     * @param list<string> $names
     */
    public static function unknownDomains(array $names): self
    {
        return new self(
            AdminErrorCode::UNKNOWN_DOMAIN,
            422,
            sprintf(
                'These domains do not exist and cannot be assigned: %s.',
                implode(', ', array_map(self::excerpt(...), $names)),
            ),
        );
    }

    /**
     * The `If-Match` the client sent does not describe the administrator as it
     * is now — somebody else changed it in between (U-19).
     */
    public static function preconditionFailed(string $username): self
    {
        return new self(
            AdminErrorCode::PRECONDITION_FAILED,
            412,
            sprintf(
                'The administrator "%s" has changed since the client last read it.',
                self::excerpt($username),
            ),
        );
    }

    public static function wrongCurrentPassword(string $username): self
    {
        return new self(
            AdminErrorCode::WRONG_CURRENT_PASSWORD,
            422,
            sprintf('The current password given by "%s" is not correct.', self::excerpt($username)),
        );
    }

    /**
     * Printable ASCII only, shortened — the same treatment
     * {@see \Mailadmin\Domain\Exception\DomainError::excerpt()} gives a value
     * before it reaches a log line. A user name comes from the request and may
     * hold any byte; a broken UTF-8 sequence would make the whole JSON log entry
     * unserialisable.
     */
    private static function excerpt(string $value, int $limit = 80): string
    {
        $printable = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';

        return strlen($printable) > $limit ? substr($printable, 0, $limit) . '...' : $printable;
    }
}
