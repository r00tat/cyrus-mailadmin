<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

use Mailadmin\Domain\Model\Domain;
use Mailadmin\Domain\Model\DomainName;

/**
 * A domain together with the one number that is not in its row: how many
 * accounts it has.
 *
 * The count lives here rather than on {@see Domain} because it is a
 * *measurement* of another table, not a property of the domain — the same
 * distinction {@see \Mailadmin\Domain\Mailstore\Quota} draws between a limit and
 * its usage. A `Domain` built to be written to the database must not carry a
 * count that was true when it was read.
 *
 * It is one indexed `COUNT(*)` per row, taken in the same statement as the
 * listing. Quota **usage** is deliberately not here: that would be an IMAP round
 * per row, which is what made the legacy lists slow (architecture 7.2).
 */
final readonly class DomainSummary
{
    public function __construct(
        public Domain $domain,
        public int $accountCount,
    ) {
    }

    public function name(): DomainName
    {
        return $this->domain->name;
    }

    public function isEmpty(): bool
    {
        return $this->accountCount === 0;
    }

    public function with(Domain $domain): self
    {
        return new self($domain, $this->accountCount);
    }
}
