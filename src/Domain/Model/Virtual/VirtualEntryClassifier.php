<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model\Virtual;

use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\MailboxIdentity;

/**
 * Turns a `virtual` row into one of the six types — **once**, when the row is
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
 * dest on the list transport → mailing list when exactly a list's own row,
 *                          otherwise unknown (list_destination_mismatch)
 * alias starts with "@"  → catchall
 * legacy list row        → mailing list (needs a `lists` row, see below)
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
 *
 * **Why the list addresses are a parameter.** A list row in the transport form
 * has an empty `username` and would otherwise read as a forward; one in the
 * legacy form looks like an account's address row. {@see MailingListRecognition}
 * carries the transport domain and the addresses in `lists`, which the caller
 * loads — the classifier itself reads nothing. The transport rule comes first
 * of all, so that no catchall, forward or alias can deliver to a list; the
 * legacy rule comes before "username empty" and the address/alias decision.
 */
final readonly class VirtualEntryClassifier
{
    /**
     * @param DomainName|null $accountDomain `accountuser.domain_name` of the
     *                                       account named in `virtual.username`
     */
    public function classify(
        VirtualRow $row,
        MailingListRecognition $lists,
        ?DomainName $accountDomain = null,
    ): VirtualEntry {
        $alias = trim($row->alias);
        $destinations = DestinationList::fromLegacyString($row->dest);

        if ($alias === '') {
            return new UnknownVirtualEntry(UnknownReason::NoAlias, $row);
        }

        if ($destinations->isEmpty()) {
            return new UnknownVirtualEntry(UnknownReason::NoDestination, $row);
        }

        if ($lists->mentionsTransport($destinations)) {
            // Only a list's own row may deliver to the list transport. Anything
            // else naming it would feed a list nobody routed it to.
            if (!$lists->isTransportListRow($row, $destinations)) {
                return new UnknownVirtualEntry(UnknownReason::ListDestinationMismatch, $row);
            }

            // The list's own form, but no list behind it: the script finds
            // nothing and bounces. Unknown, so that it is reported and can be
            // removed like any row nobody understands.
            return $lists->isListAddress($alias)
                ? $this->mailingList($alias, $destinations, $row)
                : new UnknownVirtualEntry(UnknownReason::ListMissing, $row);
        }

        if (str_starts_with($alias, '@')) {
            return $this->catchAll($alias, $destinations, $row);
        }

        if ($lists->isLegacyListRow($row)) {
            return $this->mailingList($alias, $destinations, $row);
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
    public function classifyAll(iterable $rows, MailingListRecognition $lists, array $accountDomains = []): array
    {
        $byLowercaseName = [];

        foreach ($accountDomains as $username => $domain) {
            $byLowercaseName[strtolower((string) $username)] = $domain;
        }

        $entries = [];

        foreach ($rows as $row) {
            $key = strtolower(trim($row->username));
            $entries[] = $this->classify($row, $lists, $byLowercaseName[$key] ?? null);
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

    private function mailingList(
        string $alias,
        DestinationList $destinations,
        VirtualRow $row,
    ): VirtualEntry {
        if (!EmailAddress::looksLikeAddress($alias)) {
            return new UnknownVirtualEntry(UnknownReason::AliasIsNotAnAddress, $row);
        }

        try {
            return new MailingListEntry(EmailAddress::parseStored($alias), $destinations, $row);
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
