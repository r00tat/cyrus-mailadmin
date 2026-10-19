<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Password;

/**
 * The stable error codes the password rules raise (U-7).
 *
 * The prefix is `auth` because U-7 enumerates the allowed resource prefixes and
 * has no `password`; a rule about a credential belongs to `auth`, and the same
 * code has to hold whether the password is being set on an account or on an
 * administrator.
 *
 * **These codes must be added to the `ErrorCode` enum in
 * `openapi/components/common.yaml` (F2).** A code that is not in the contract
 * fails the contract test — that is intended, it is how the frontend's
 * translation catalogue stays complete (E-16).
 *
 * No code and no message ever carries the password itself; see
 * {@see InvalidPassword}.
 */
final class PasswordErrorCode
{
    /** No password at all. */
    public const string PASSWORD_EMPTY = 'auth.password_empty';

    /** Shorter than {@see PasswordSubject::minimumLength()}. */
    public const string PASSWORD_TOO_SHORT = 'auth.password_too_short';

    /**
     * Longer than what the target format can carry.
     *
     * For `accountuser` that is bcrypt's 72-byte input limit. U-21: rejected,
     * never truncated.
     */
    public const string PASSWORD_TOO_LONG = 'auth.password_too_long';

    /** Listed in the block list of common passwords (U-21). */
    public const string PASSWORD_TOO_COMMON = 'auth.password_too_common';

    /** Contains the user name, the local part or the domain name (U-21). */
    public const string PASSWORD_CONTAINS_PERSONAL_DATA = 'auth.password_contains_personal_data';

    /**
     * Holds a control character or is not valid UTF-8.
     *
     * A NUL byte is the one that matters: `crypt(3)` and `pam_mysql` work on C
     * strings and would stop at it, so the effective secret would be shorter
     * than what was entered — and shorter than what mailadmin measured.
     */
    public const string PASSWORD_INVALID_CHARACTERS = 'auth.password_invalid_characters';

    private function __construct()
    {
    }
}
