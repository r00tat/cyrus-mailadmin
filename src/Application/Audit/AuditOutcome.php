<?php

declare(strict_types=1);

namespace Mailadmin\Application\Audit;

/**
 * How the recorded attempt ended.
 *
 * Three values, and the reason there are three rather than a boolean is the
 * middle one: a refusal is the entry an operator most wants to find, and it is
 * neither a success nor a malfunction. S4's open point 5 proposed recording
 * failed operations with a result field; this is that field.
 *
 * The distinction that matters when reading a trail:
 *
 *  - {@see Succeeded} — the change happened. `state_after` describes it.
 *  - {@see Denied} — the actor was not allowed. Nothing changed, and the
 *    interesting column is `actor`.
 *  - {@see Failed} — the actor was allowed and it did not work anyway. Nothing
 *    changed that the operation meant to change, and the interesting column is
 *    `reason`.
 */
enum AuditOutcome: string
{
    case Succeeded = 'succeeded';
    case Denied = 'denied';
    case Failed = 'failed';

    /**
     * Did the object change? Only a success may carry a `state_after`.
     */
    public function changedSomething(): bool
    {
        return $this === self::Succeeded;
    }
}
