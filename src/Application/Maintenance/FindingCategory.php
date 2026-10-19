<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

/**
 * The kinds of disagreement the reconciliation reports (A6 open point 4, U-119).
 *
 * Every one of them is reported and none of them is acted on (E-18).
 */
enum FindingCategory: string
{
    /** An `accountuser` row whose mailbox does not exist. Mail to it bounces. */
    case AccountWithoutMailbox = 'account_without_mailbox';

    /** A mailbox no `accountuser` row claims, in either namespace. */
    case MailboxWithoutAccount = 'mailbox_without_account';

    /**
     * A mailbox under the **other** scheme's name of an existing account —
     * `user/info@example.de` next to the account `info.example.de`. Neither
     * namespace alone shows it, which is how the legacy system missed it.
     */
    case ShadowMailbox = 'shadow_mailbox';

    /**
     * An `accountuser` row that cannot be read as an account: its stored name
     * does not belong to its `domain_name`, or the domain name is unusable.
     */
    case UnresolvableAccount = 'unresolvable_account';

    /**
     * A dotted user name that a subdomain's account could also have — U-96's
     * ambiguity, found after the fact rather than refused.
     */
    case AmbiguousUsername = 'ambiguous_username';

    /**
     * A `virtual` row whose `username` names no account and is not the domain
     * placeholder of U-106. `validsenders` joins on it.
     */
    case VirtualOwnerMissing = 'virtual_owner_missing';

    /**
     * A destination that leads nowhere: a user name without `@` that no account
     * has — reported as written, never completed with a guessed domain — or an
     * address in a locally delivered domain that no account, address, catchall
     * or list answers.
     */
    case VirtualTargetMissing = 'virtual_target_missing';

    /** A `virtual` row that fits none of the four types (architecture 3.4). */
    case VirtualUnclassified = 'virtual_unclassified';
}
