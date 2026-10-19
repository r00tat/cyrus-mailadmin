<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

/**
 * Whether a repair-queue entry still needs somebody (A6).
 *
 * Two states and no more. "Being retried" is not a state: a retry runs inside
 * one request and either resolves the entry or leaves it open with one more
 * failed attempt.
 */
enum RepairStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
