<?php

declare(strict_types=1);

namespace Mailadmin\Application\Auth;

/**
 * The error codes S2 produces.
 *
 * The authoritative list is the `ErrorCode` enum in
 * `openapi/components/common.yaml`; these constants exist so that the use cases
 * do not spell them as literals, and a contract test keeps the two in step —
 * the same arrangement F2 uses for the cross-cutting codes in
 * {@see \Mailadmin\Http\Problem\ErrorCode}.
 *
 * `RATE_LIMITED` is F2's `request.rate_limited` rather than an `auth.` code of
 * its own: nothing about it is specific to signing in, and a client that already
 * knows how to back off should not need a second code to recognise the same
 * situation.
 */
final class AuthErrorCode
{
    /** Wrong password, unknown user name, or an account that cannot act. */
    public const string INVALID_CREDENTIALS = 'auth.invalid_credentials';

    /** No refresh token, an unknown one, an expired or revoked one. */
    public const string REFRESH_INVALID = 'auth.refresh_invalid';

    /** A spent refresh token came back. The family is now revoked. */
    public const string REFRESH_REUSED = 'auth.refresh_reused';

    /** Cross-cutting; owned by F2. Repeated here so the use cases read evenly. */
    public const string RATE_LIMITED = 'request.rate_limited';

    private function __construct()
    {
    }

    /**
     * The codes this package owns — what the contract test compares against the
     * `ErrorCode` enum. `RATE_LIMITED` is excluded because F2 already declares
     * and tests it.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::INVALID_CREDENTIALS,
            self::REFRESH_INVALID,
            self::REFRESH_REUSED,
        ];
    }
}
