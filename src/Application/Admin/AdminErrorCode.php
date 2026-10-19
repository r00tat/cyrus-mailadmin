<?php

declare(strict_types=1);

namespace Mailadmin\Application\Admin;

/**
 * The error codes A4 produces.
 *
 * The authoritative list is the `ErrorCode` enum in
 * `openapi/components/common.yaml`; these constants exist so that the use cases
 * do not spell them as literals, and a contract test keeps the two in step —
 * the same arrangement S2 uses in
 * {@see \Mailadmin\Application\Auth\AuthErrorCode}.
 *
 * ## Why there is an `admin.not_found` at all
 *
 * A1 deliberately has no `domain.not_found`: a domain that does not exist and a
 * domain the actor may not see have to be one answer down to the byte
 * (`CLAUDE.md`, U-44), so both are `request.not_found`.
 *
 * **This resource has no such pair.** An administrator is not in a domain —
 * `adminuser` has no domain column and `domainadmin` is a list, not an owner —
 * and `admin.read`, `admin.write` and `admin.delete` are superuser-only, so
 * whoever reaches the lookup already sees every row there is. There is no second
 * meaning for the `404` to be confused with, and therefore nothing for a
 * distinct code to give away. It buys the interface a translatable "this
 * administrator no longer exists" instead of "no such page".
 *
 * That reasoning depends on `admin.*` staying superuser-only (E-18). Should this
 * resource ever become domain-scoped, this constant has to go back to
 * `request.not_found` in the same change.
 *
 * ## The password rules are not here
 *
 * A rejected password raises {@see \Mailadmin\Domain\Password\InvalidPassword}
 * with one of the `auth.password_*` codes S1 owns. A4 passes it through rather
 * than restating it: the rule is the same rule wherever a password is set, and a
 * second code for it would be a second translation key for one situation.
 */
final class AdminErrorCode
{
    /** Deleting or demoting the last superuser. Refused — the system would be unmanageable. */
    public const string LAST_SUPERUSER = 'admin.last_superuser';

    /** No `adminuser` row with this name. */
    public const string NOT_FOUND = 'admin.not_found';

    /**
     * The role and the domain assignment contradict each other: a superuser was
     * given named domains, or a domain master was given none.
     *
     * Both directions are one code because both are the same mistake seen from
     * two sides, and the interface shows the same form either way.
     */
    public const string ROLE_SCOPE_MISMATCH = 'admin.role_scope_mismatch';

    /** An administrator tried to delete their own account. */
    public const string SELF_DELETION = 'admin.self_deletion';

    /** An assignment names a domain that has no row in `domain`. */
    public const string UNKNOWN_DOMAIN = 'admin.unknown_domain';

    /** The name is already taken. Compared byte for byte, as `latin1_bin` does. */
    public const string USERNAME_TAKEN = 'admin.username_taken';

    /** `PUT /admins/me/password` was called with the wrong current password. */
    public const string WRONG_CURRENT_PASSWORD = 'admin.wrong_current_password';

    /**
     * The `If-Match` value does not describe the administrator as it is now
     * (U-19).
     *
     * Cross-cutting and owned by F2, which declares and tests it. Repeated here
     * so that the use cases spell no literal — the same arrangement S2 uses for
     * `request.rate_limited`. It is not in {@see all()} for that reason.
     */
    public const string PRECONDITION_FAILED = 'request.precondition_failed';

    private function __construct()
    {
    }

    /**
     * The codes this package owns — what the contract test compares against the
     * `ErrorCode` enum. `PRECONDITION_FAILED` is excluded: F2 owns it.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::LAST_SUPERUSER,
            self::NOT_FOUND,
            self::ROLE_SCOPE_MISMATCH,
            self::SELF_DELETION,
            self::UNKNOWN_DOMAIN,
            self::USERNAME_TAKEN,
            self::WRONG_CURRENT_PASSWORD,
        ];
    }
}
