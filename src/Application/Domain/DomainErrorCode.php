<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

/**
 * The stable error codes A1's use cases raise (U-7).
 *
 * The authoritative list is the `ErrorCode` enum in `openapi/components/common.yaml`;
 * a contract test fails if a code appears here and not there. The constants
 * exist so that no layer spells one as a literal — the code is the contract, the
 * English text is a fallback (E-16).
 *
 * **There is no `domain.not_found`, and that is the point.** A domain that does
 * not exist and a domain the actor may not see have to produce the same document
 * down to the byte (`CLAUDE.md`, U-44), so both answer with the cross-cutting
 * `request.not_found` from {@see \Mailadmin\Http\Problem\ErrorCode}. A code of
 * our own would restore the distinction in the one field a client actually
 * reads.
 *
 * The two precondition codes are cross-cutting for the same reason: `If-Match`
 * behaves identically on every resource, and a per-resource code would make a
 * frontend translate the same sentence six times.
 */
final class DomainErrorCode
{
    /**
     * A domain with this name already exists — on create, or as the target of a
     * rename.
     *
     * Answered with `409`. It says nothing about scope: the name of a domain the
     * actor cannot see is still taken, and pretending otherwise would let a
     * caller enumerate domains by trying to create them.
     */
    public const string NAME_TAKEN = 'domain.name_taken';

    /**
     * Every candidate for the unique `domain.prefix` is already in the table
     * (U-46).
     *
     * Effectively unreachable for prefixes this code wrote — it would need eight
     * SHA-256 prefixes of the same name to collide. It is reachable if somebody
     * filled the column by hand, which is exactly when a human should look
     * rather than a loop should keep trying.
     */
    public const string PREFIX_TAKEN = 'domain.prefix_taken';

    /**
     * The domain still has accounts and was therefore not deleted (U-49).
     *
     * webcyradm cascaded: one confirmation removed every mailbox of the domain
     * and the mail in them. The account count is in the log; the client is told
     * to look at the accounts.
     */
    public const string NOT_EMPTY = 'domain.not_empty';

    /**
     * The domain has no accounts but still has rows in `virtual` — addresses,
     * forwards or a catchall — and was therefore not deleted (U-95).
     *
     * Answered with `409`. Those rows survive a deleted `domain` row, and they
     * come back to life when the name is created again. They are A3's to remove.
     */
    public const string STILL_HAS_ADDRESSES = 'domain.still_has_addresses';

    /**
     * The name would put Cyrus user names in two places at once (U-96).
     *
     * Answered with `409`. In the dotted scheme `info.kunde@example.de` and
     * `info@kunde.example.de` are the same user `info.kunde.example.de`. Raised
     * when a domain is created or renamed into a namespace a parent domain's
     * account already occupies, and when a rename would move an account into
     * the namespace of an existing subdomain or onto an existing user name.
     */
    public const string NAMESPACE_CONFLICT = 'domain.namespace_conflict';

    /**
     * The name is not a domain, but rows from an earlier domain of that name
     * are still there: `domainadmin` assignments or `virtual` rows ending in
     * `@<name>` (U-99).
     *
     * Answered with `409`, on create and as the target of a rename. Taking the
     * name over would hand the new domain to an old domain master, or its mail
     * to an old catchall, without anybody deciding so. The counts are in the
     * message and the log; a superuser removes the rows deliberately (A4, A3).
     */
    public const string LEFTOVER_STATE = 'domain.leftover_state';

    /**
     * A rename was asked for without `confirm_rename: true`.
     *
     * Answered with `422`. A rename walks every mailbox in Cyrus and changes the
     * user name every owner logs in with; it is not something a `PATCH` should
     * be able to trigger by carrying one extra field.
     */
    public const string RENAME_NOT_CONFIRMED = 'domain.rename_not_confirmed';

    /**
     * A rename stopped part way through and the state is not what was asked for.
     *
     * Answered with `409`. Either the mailboxes were renamed back and nothing
     * changed, or they could not all be renamed back — in which case there is a
     * repair-queue entry naming exactly what is where (architecture 8.2).
     *
     * Also raised **before** anything is touched when an account of the domain
     * has a stored user name that contradicts its `domain_name`: the new name
     * cannot be derived for it, so the rename would leave that account
     * unreachable, which is the failure this whole operation exists to avoid.
     */
    public const string RENAME_INCOMPLETE = 'domain.rename_incomplete';

    /**
     * The domain still has mailing lists: rows in `lists` whose address ends in
     * `@<name>`.
     *
     * Answered with `409`, before anything is touched. A rename rewrites
     * `virtual.alias` but not `lists.address`, the list key in `dest` or the
     * legacy aliases file, and carrying those along is neither atomic nor, in
     * the legacy mode, complete without a configuration run. The lists are
     * deleted first and created again under the new name.
     */
    public const string RENAME_BLOCKED_BY_MAILING_LIST = 'domain.rename_blocked_by_mailing_list';

    /**
     * The mail store could not be reached, so the rename was refused outright.
     *
     * Answered with `503`. Confirming the safe answer to A1's open point 8: the
     * alternative is renaming the database half of a domain and never the
     * mailbox half, which is the legacy system's defect reproduced by an
     * unreachable server.
     */
    public const string MAILSTORE_UNAVAILABLE = 'domain.mailstore_unavailable';

    /**
     * Cross-cutting; owned by F2. Repeated here so that the use cases read
     * evenly and never spell a code as a literal — the arrangement S2 uses for
     * `request.rate_limited`.
     *
     * This is the code for a domain that does not exist **and** for one the
     * actor may not see. See the class comment.
     */
    public const string NOT_FOUND = 'request.not_found';

    /** Cross-cutting; owned by F2. `If-Match` was required and absent (U-19). */
    public const string PRECONDITION_REQUIRED = 'request.precondition_required';

    /** Cross-cutting; owned by F2. `If-Match` did not match (U-19). */
    public const string PRECONDITION_FAILED = 'request.precondition_failed';

    private function __construct()
    {
    }

    /**
     * The codes this package owns — what A1's contract test compares against
     * the `ErrorCode` enum. The three cross-cutting ones are excluded because
     * F2 already declares and tests them.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::LEFTOVER_STATE,
            self::MAILSTORE_UNAVAILABLE,
            self::NAME_TAKEN,
            self::NAMESPACE_CONFLICT,
            self::NOT_EMPTY,
            self::PREFIX_TAKEN,
            self::RENAME_BLOCKED_BY_MAILING_LIST,
            self::RENAME_INCOMPLETE,
            self::RENAME_NOT_CONFIRMED,
            self::STILL_HAS_ADDRESSES,
        ];
    }
}
