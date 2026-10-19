<?php

declare(strict_types=1);

namespace Mailadmin\Application\Auth;

use RuntimeException;

/**
 * A sign-in or a refresh was refused.
 *
 * Carries the stable error code and the HTTP status the handler will use, so
 * that the decision about *what happened* stays in the use case and the handler
 * only renders it. The English message is for the log; the client gets the code
 * and translates it (E-16).
 *
 * ## Why "wrong password" and "no such user" are one case
 *
 * There is exactly one constructor for both, and it takes no argument saying
 * which. That is not an oversight to be tidied up later: an endpoint that
 * distinguishes them is an endpoint that enumerates administrator accounts, and
 * the distinction is worth nothing to a legitimate user who mistyped either
 * field.
 */
final class AuthenticationFailed extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly int $retryAfterSeconds,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Wrong password, unknown user name, or an administrator who cannot act.
     * One code, one status, one wording.
     */
    public static function invalidCredentials(): self
    {
        return new self(
            AuthErrorCode::INVALID_CREDENTIALS,
            401,
            0,
            'The credentials were not accepted.',
        );
    }

    public static function rateLimited(int $retryAfterSeconds, string $reason): self
    {
        return new self(
            AuthErrorCode::RATE_LIMITED,
            429,
            $retryAfterSeconds,
            sprintf('Too many failed sign-in attempts (limited by %s).', $reason),
        );
    }

    /**
     * No refresh token, one that was never issued, or one whose family has
     * expired or been revoked.
     */
    public static function refreshInvalid(): self
    {
        return new self(
            AuthErrorCode::REFRESH_INVALID,
            401,
            0,
            'The refresh token is not usable.',
        );
    }

    /**
     * A refresh token that had already been spent came back.
     *
     * The family is revoked before this is thrown. The distinct code exists
     * because the client's reaction differs: not "sign in again, that expired"
     * but "sign in again, and someone should know".
     */
    public static function refreshReused(): self
    {
        return new self(
            AuthErrorCode::REFRESH_REUSED,
            401,
            0,
            'A spent refresh token was presented again; the family has been revoked.',
        );
    }
}
