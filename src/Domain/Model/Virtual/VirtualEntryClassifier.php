<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model\Virtual;

use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\MailboxIdentity;

/**
 * Turns a `virtual` row into one of the five types — **once**, when the row is
 * read (architecture 3.4).
 *
 * This is the only place in the application that looks at the field pattern of
 * the table. Everywhere else asks the type. The legacy code decided the meaning
 * of a row again at every call site, which is why the same row could count as
 * an alias in one screen and as a forward in the next.
 *
 * ```text
 * alias empty            → unknown (no_alias)
 * dest empty             → unknown (no_destination)
 * alias starts with "@"  → catchall
 * username empty         → forward
 * alias is an address    → mailbox address if it is the account's own address,
 *                          otherwise alias
 * anything else          → unknown
 * ```
 *
 * **Why the domain of record is a parameter.** Telling a mailbox address from
 * an alias means knowing which address the account in `virtual.username`
 * actually has — and that needs `accountuser.domain_name`, because the user
 * name alone cannot be taken apart (architecture 3.3). Without it the row is
 * classified as an alias: it is an address that delivers to a Cyrus user, which
 * is true either way. A6's reconciliation reports a target that has no account.
 */
final readonly class VirtualEntryClassifier
{
    /**
     * @param DomainName|null $accountDomain `accountuser.domain_name` of the
     *                                       account named in `virtual.username`
     */
    public function classify(VirtualRow $row, ?DomainName $accountDomain = null): VirtualEntry
    {
        $alias = trim($row->alias);
        $destinations = DestinationList::fromLegacyString($row->dest);

        if ($alias === '') {
            return new UnknownVirtualEntry(UnknownReason::NoAlias, $row);
        }

        if ($destinations->isEmpty()) {
            return new UnknownVirtualEntry(UnknownReason::NoDestination, $row);
        }

        if (str_starts_with($alias, '@')) {
            return $this->catchAll($alias, $destinations, $row);
        }

        if (!$row->hasUsername()) {
            return new Forward($alias, $destinations, $row);
        }

        if (!EmailAddress::looksLikeAddress($alias)) {
            return new UnknownVirtualEntry(UnknownReason::AliasIsNotAnAddress, $row);
        }

        return $this->addressOrAlias($alias, $destinations, $row, $accountDomain);
    }

    /**
     * Classify a batch, with the domains of record the caller has already
     * looked up.
     *
     * @param iterable<VirtualRow>      $rows
     * @param array<string, DomainName> $accountDomains stored Cyrus user name
     *                                                  => `domain_name`; the
     *                                                  lookup ignores case
     *
     * @return list<VirtualEntry>
     */
    public function classifyAll(iterable $rows, array $accountDomains = []): array
    {
        $byLowercaseName = [];

        foreach ($accountDomains as $username => $domain) {
            $byLowercaseName[strtolower((string) $username)] = $domain;
        }

        $entries = [];

        foreach ($rows as $row) {
            $key = strtolower(trim($row->username));
            $entries[] = $this->classify($row, $byLowercaseName[$key] ?? null);
        }

        return $entries;
    }

    private function catchAll(
        string $alias,
        DestinationList $destinations,
        VirtualRow $row,
    ): VirtualEntry {
        $domain = substr($alias, 1);

        if ($domain === '') {
            return new UnknownVirtualEntry(UnknownReason::CatchAllWithoutDomain, $row);
        }

        try {
            return new CatchAll(DomainName::fromStoredValue($domain), $destinations, $row);
        } catch (DomainError) {
            return new UnknownVirtualEntry(UnknownReason::UnusableValue, $row);
        }
    }

    private function addressOrAlias(
        string $alias,
        DestinationList $destinations,
        VirtualRow $row,
        ?DomainName $accountDomain,
    ): VirtualEntry {
        try {
            $address = EmailAddress::parseStored($alias);
        } catch (DomainError) {
            return new UnknownVirtualEntry(UnknownReason::UnusableValue, $row);
        }

        if ($accountDomain === null) {
            return new Alias($address, $destinations, $row);
        }

        try {
            $identity = MailboxIdentity::fromStoredUsername($row->username, $accountDomain);
        } catch (DomainError) {
            // The account this row points at contradicts itself. The row is
            // still an address that delivers to a Cyrus user, so it stays an
            // alias; the broken account is A6's reconciliation to report.
            return new Alias($address, $destinations, $row);
        }

        if ($identity->address()->equals($address)) {
            return new MailboxAddress($identity, $address, $row);
        }

        return new Alias($address, $destinations, $row);
    }
}
