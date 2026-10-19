<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

/**
 * The stable error codes A2's use cases raise (U-7).
 *
 * The authoritative list is the `ErrorCode` enum in
 * `openapi/components/common.yaml`; a contract test fails if a code appears here
 * and not there.
 *
 * **There is no `account.not_found`**, for the reason A1's `DomainErrorCode`
 * gives: an account that does not exist and an account in a domain the actor
 * may not see have to be the same document down to the byte (`CLAUDE.md`,
 * U-44), and that document carries `request.not_found`.
 */
final class AccountErrorCode
{
    /**
     * The domain holds as many accounts as `domain.maxaccounts` allows (U-48,
     * U-81). `409`.
     */
    public const string LIMIT_REACHED = 'account.limit_reached';

    /**
     * Cyrus already has a mailbox under the name the new account would get, and
     * no account claims it. `409`.
     *
     * That mailbox is **not** removed: it was not made by this request, and it
     * may hold somebody's mail. The reconciliation (A6) shows it.
     */
    public const string MAILBOX_EXISTS = 'account.mailbox_exists';

    /** The account has a row and no mailbox in Cyrus. `409`. */
    public const string MAILBOX_MISSING = 'account.mailbox_missing';

    /**
     * The mail store could not be reached or refused the step, before anything
     * was changed. `503`.
     */
    public const string MAILSTORE_UNAVAILABLE = 'account.mailstore_unavailable';

    /**
     * The address is already in `virtual`, or the Cyrus user name already in
     * `accountuser` — or, in the dotted scheme, the user name is the one an
     * account of an existing subdomain would get (U-86). `409`. Says nothing
     * about scope, for the reason `domain.name_taken` gives.
     */
    public const string NAME_TAKEN = 'account.name_taken';

    /**
     * The new or raised limit would make the sum of the domain's account limits
     * exceed `domain.domainquota` (U-48, U-81). `409`.
     */
    public const string QUOTA_EXCEEDS_DOMAIN = 'account.quota_exceeds_domain';

    /**
     * A step after the mailbox was created failed and was undone: no row was
     * written, and the mailbox was removed again — or, if that failed too, is a
     * repair-queue entry (architecture 8.1, U-85). `409`.
     */
    public const string SETUP_INCOMPLETE = 'account.setup_incomplete';

    /**
     * The Cyrus user name would be longer than `virtual.username` holds (50
     * characters, U-85). `422`.
     */
    public const string USERNAME_TOO_LONG = 'account.username_too_long';

    /**
     * The account was to be disabled, and the view `allusers` still offers its
     * login: the installation runs the view without the status filter
     * (U-127). Nothing was changed. `409`.
     *
     * An operator rolls out the new view (docs/deployment/allusers.sql.example)
     * — the application cannot, and it does not claim a lock it cannot see
     * take effect.
     */
    public const string STATUS_NOT_ENFORCED = 'account.status_not_enforced';

    /**
     * Owned by F4's domain model block; A2 raises it with `409` for a stored
     * user name that contradicts its `domain_name`, so that the mailbox cannot
     * be derived and the account cannot be changed through the API.
     */
    public const string IDENTITY_MISMATCH = 'account.identity_mismatch';

    /** Cross-cutting; owned by F2. See the class comment. */
    public const string NOT_FOUND = 'request.not_found';

    /** Cross-cutting; owned by F2. */
    public const string CONFLICT = 'request.conflict';

    /** Cross-cutting; owned by F2. */
    public const string PRECONDITION_REQUIRED = 'request.precondition_required';

    /** Cross-cutting; owned by F2. */
    public const string PRECONDITION_FAILED = 'request.precondition_failed';

    private function __construct()
    {
    }

    /**
     * The codes this package adds to the contract.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::STATUS_NOT_ENFORCED,
            self::LIMIT_REACHED,
            self::MAILBOX_EXISTS,
            self::MAILBOX_MISSING,
            self::MAILSTORE_UNAVAILABLE,
            self::NAME_TAKEN,
            self::QUOTA_EXCEEDS_DOMAIN,
            self::SETUP_INCOMPLETE,
            self::USERNAME_TOO_LONG,
        ];
    }
}
