<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

/**
 * What the list delivery changeover did with one list ({@see MigrateListDelivery}).
 * The value is what the protocol line says.
 */
enum ListDeliveryResult: string
{
    /** Its legacy row now routes to the transport. */
    case Migrated = 'migrated';

    /** Its transport row is back in the legacy form (`--revert`). */
    case Reverted = 'reverted';

    /** Already in the form the run asks for. */
    case Unchanged = 'unchanged';

    /** `lists` has the list, `virtual` has no row for it; nothing is created. */
    case NoVirtualEntry = 'no virtual entry';

    /** Deliberately left alone; the outcome says why. */
    case Skipped = 'skipped';

    /** A write failed; what was written is set back where possible. */
    case Failed = 'failed';
}
