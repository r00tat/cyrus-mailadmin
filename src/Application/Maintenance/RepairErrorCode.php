<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

/**
 * The stable codes the repair queue answers with (A6).
 *
 * `repair.` is a prefix of its own, an extension of U-7's list (U-122): none of
 * the existing resources describes a queue entry, and borrowing `mailstore.` or
 * `request.` would make a refused retry indistinguishable from the failures the
 * entry describes. Every constant here is in the `ErrorCode` enum of the
 * contract and has a German text; `MaintenanceEndpointTest` checks both.
 */
final class RepairErrorCode
{
    /** The entry is already resolved — retried or dismissed. `409`. */
    public const string ALREADY_RESOLVED = 'repair.already_resolved';

    /**
     * The application cannot finish this kind of entry itself — it has to be
     * handled by hand and then dismissed (U-118). `409`.
     */
    public const string NOT_RETRYABLE = 'repair.not_retryable';

    /**
     * The retry ran and the mail store refused it again. The entry stays open
     * with one more attempt. `409`, or `503` when the mail store could not be
     * reached at all.
     */
    public const string RETRY_FAILED = 'repair.retry_failed';

    /**
     * The state no longer matches what the entry describes — the account was
     * created again, the limit was changed since — so retrying would act on
     * something the entry does not know about. Nothing was done. `409`.
     */
    public const string STATE_CHANGED = 'repair.state_changed';

    /** No such entry. Rendered exactly like any other missing resource. */
    public const string NOT_FOUND = 'request.not_found';

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
            self::ALREADY_RESOLVED,
            self::NOT_RETRYABLE,
            self::RETRY_FAILED,
            self::STATE_CHANGED,
        ];
    }
}
