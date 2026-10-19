<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

/**
 * The stable error codes A3's use cases raise (U-7).
 *
 * The authoritative list is the `ErrorCode` enum in
 * `openapi/components/common.yaml`; a contract test fails if a code appears here
 * and not there.
 *
 * No `address.not_found`, for the reason A1's `DomainErrorCode` gives: an alias
 * that does not exist and one in a domain the actor may not see are the same
 * document down to the byte (`CLAUDE.md`, U-44), and that document carries
 * `request.not_found`.
 */
final class AddressErrorCode
{
    /**
     * The rows under this address, catchall or forward are not all of the type
     * the operation edits — a row that fits no pattern among them, or rows that
     * disagree about who may send as the address. Saving would have to drop or
     * rewrite them, and an unknown row is never destroyed on the way (U-22,
     * U-105). `409`; the single rows can be removed through
     * `DELETE /domains/{domain}/addresses/{entry}`.
     */
    public const string NOT_EDITABLE = 'address.not_editable';

    /**
     * `postmaster` or `abuse` would stop receiving mail — deleted or switched
     * off — without `confirm_reserved` (U-108). `409`.
     */
    public const string RESERVED = 'address.reserved';

    /**
     * Removing or switching off the address would leave the domain's enabled
     * catchall with a destination that no longer delivers — one in its own
     * domain without an enabled row, or one that now leads into a loop
     * (U-112). `409`; the catchall has to be changed first.
     */
    public const string NEEDED_BY_CATCHALL = 'address.needed_by_catchall';

    /**
     * The address is already in `virtual` or is an account's user name. `409`.
     * Says nothing about scope: the address is in the actor's own domain.
     */
    public const string TAKEN = 'address.taken';

    /**
     * The row cannot be written within the legacy column widths, or the
     * placeholder sender would name an account (U-106). `422`.
     */
    public const string UNSTORABLE = 'address.unstorable';

    /**
     * A forward without a local copy was asked for an account whose user name
     * is its address (`fqun`): its own address row delivers into the mailbox
     * whatever the forward says, so "no copy" cannot be honoured (U-107). `409`.
     */
    public const string FORWARD_COPY_REQUIRED = 'forward.copy_required';

    /**
     * The active Sieve script was not written by mailadmin and the request did
     * not say `take_over` (architecture 9.4, U-102). `409`.
     */
    public const string VACATION_FOREIGN_SCRIPT = 'vacation.foreign_script';

    /** The Sieve server could not be reached or failed; nothing changed. `503`. */
    public const string VACATION_MAILSTORE_UNAVAILABLE = 'vacation.mailstore_unavailable';

    /** The Sieve server does not announce the `vacation` extension. `409`. */
    public const string VACATION_NOT_SUPPORTED = 'vacation.not_supported';

    /**
     * The Sieve server refused the generated script — in practice because it is
     * larger than the server accepts. `422`.
     */
    public const string VACATION_REJECTED = 'vacation.rejected';

    /** Owned by F4's domain-model block. `422`. */
    public const string INVALID_DESTINATION = 'address.invalid_destination';

    /** Cross-cutting; owned by F2. See the class comment. */
    public const string NOT_FOUND = 'request.not_found';

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
            self::NOT_EDITABLE,
            self::RESERVED,
            self::NEEDED_BY_CATCHALL,
            self::TAKEN,
            self::UNSTORABLE,
            self::FORWARD_COPY_REQUIRED,
            self::VACATION_FOREIGN_SCRIPT,
            self::VACATION_MAILSTORE_UNAVAILABLE,
            self::VACATION_NOT_SUPPORTED,
            self::VACATION_REJECTED,
        ];
    }
}
