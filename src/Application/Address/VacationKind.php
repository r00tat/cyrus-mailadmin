<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

/**
 * What the account's active Sieve script means for the vacation notice
 * (architecture 9.4, U-102).
 */
enum VacationKind: string
{
    /** No active script, or one of ours without a vacation notice. */
    case None = 'none';

    /** One of ours, with a vacation notice. */
    case Active = 'active';

    /**
     * Written by someone else — webcyradm, a mail client, a person. Shown
     * read-only; replacing it needs `take_over`.
     */
    case Foreign = 'foreign';

    /**
     * Ours by its marker, but its model line is missing or does not decode.
     * Shown read-only; replacing it needs no takeover, because it is ours.
     */
    case Unreadable = 'unreadable';

    public function showsScript(): bool
    {
        return $this === self::Foreign || $this === self::Unreadable;
    }
}
