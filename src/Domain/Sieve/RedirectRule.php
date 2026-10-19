<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Sieve;

use Mailadmin\Domain\Model\EmailAddress;

/**
 * Send a copy of every message on to another address.
 *
 * Whether the mailbox keeps a copy is **not** a property of the single
 * redirect but of the rule set — a `keep;` applies to the message, not to one
 * of several redirects. {@see SieveRuleSet::keepsLocalCopy()} holds it.
 */
final readonly class RedirectRule
{
    public function __construct(public EmailAddress $destination)
    {
    }

    public function equals(self $other): bool
    {
        return $this->destination->equals($other->destination);
    }
}
