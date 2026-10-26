<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model\Virtual;

use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;

/**
 * What the classifier needs to know to recognise a mailing list row, handed in
 * by the caller so that the classifier stays a pure function.
 *
 * A row is a list row when
 *
 *  1. it has exactly the form A5 writes — an empty `username` and a single
 *     destination, `<list key of alias>@<transport domain>` — **and** `lists`
 *     has a row whose address is the `alias` (U-146), or
 *  2. it has the legacy form `dest = username = REPLACE(alias, '@', '.')`
 *     **and** `lists` has a row whose address is the `alias`.
 *
 * A row with a destination on the transport domain that is not exactly that
 * is **not** a list row ({@see mentionsTransport()}); the classifier reports
 * it as unknown rather than guessing which list it belongs to.
 *
 * The second needs `lists` because the legacy form is, field for field, also
 * what an account's own address row in the `dotted` scheme looks like. The
 * first needs it because a transport row whose list is gone delivers nowhere
 * and has to show up as unknown rather than as a list nobody can open.
 * {@see domainsToLookUp()} tells a caller which domains' list addresses it has
 * to load for a set of rows — one query per domain, not one per row.
 */
final readonly class MailingListRecognition
{
    /** @var array<string, true> lower-cased list addresses */
    private array $addresses;

    /**
     * @param list<string> $listAddresses addresses present in `lists`; compared
     *                                    without regard to case
     */
    public function __construct(public DomainName $transportDomain, array $listAddresses)
    {
        $addresses = [];

        foreach ($listAddresses as $address) {
            $addresses[strtolower(trim($address))] = true;
        }

        $this->addresses = $addresses;
    }

    public function isListAddress(string $alias): bool
    {
        return isset($this->addresses[strtolower(trim($alias))]);
    }

    /**
     * `REPLACE(address, '@', '.')` — the key the pipe script looks a list up
     * by. {@see \Mailadmin\Application\MailingList\ListDelivery::listKey()}
     * delegates here.
     */
    public static function listKey(EmailAddress $address): string
    {
        return str_replace('@', '.', $address->toString());
    }

    /**
     * Is any destination on the transport domain?
     */
    public function mentionsTransport(DestinationList $destinations): bool
    {
        foreach ($destinations->all() as $destination) {
            if ($this->isTransportDestination($destination)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rule 1, strictly: no `username`, one destination, and that destination
     * is the list key of `alias` on the transport domain.
     */
    public function isTransportListRow(VirtualRow $row, DestinationList $destinations): bool
    {
        if (trim($row->username) !== '' || $destinations->count() !== 1) {
            return false;
        }

        $alias = trim($row->alias);

        if (!EmailAddress::looksLikeAddress($alias)) {
            return false;
        }

        try {
            $expected = self::listKey(EmailAddress::parseStored($alias)) . '@' . $this->transportDomain->toString();
        } catch (DomainError) {
            return false;
        }

        return strtolower(trim((string) $destinations->first())) === strtolower($expected);
    }

    /**
     * Is this a destination on the transport domain — something only a list's
     * own row may name?
     */
    public function isTransportDestination(string $destination): bool
    {
        return self::isDestinationOnDomain($destination, $this->transportDomain);
    }

    /**
     * The one definition of "this destination is on that domain", for the
     * classifier and for the write rules alike: the part after the last `@`,
     * compared after {@see normalizedDomain()}. `x@maillist.invalid.` is on
     * `maillist.invalid` — DNS and Postfix treat the trailing dot as the root.
     */
    public static function isDestinationOnDomain(string $destination, DomainName $domain): bool
    {
        $value = trim($destination);
        $at = strrpos($value, '@');

        return $at !== false
            && self::normalizedDomain(substr($value, $at + 1)) === self::normalizedDomain($domain->toString());
    }

    /**
     * Lower case, without surrounding space and without trailing dots — the
     * form in which two spellings of one domain compare equal.
     */
    public static function normalizedDomain(string $domain): string
    {
        return rtrim(strtolower(trim($domain)), '.');
    }

    /**
     * Rule 2: the legacy form, confirmed by a `lists` row.
     */
    public function isLegacyListRow(VirtualRow $row): bool
    {
        return self::hasLegacyListShape($row) && $this->isListAddress($row->alias);
    }

    /**
     * The domains whose list addresses the two rules need for these rows: the
     * domain of every row with the legacy shape or a destination on the
     * transport domain, each once, lower-cased.
     *
     * @param iterable<VirtualRow> $rows
     *
     * @return list<DomainName>
     */
    public static function domainsToLookUp(iterable $rows, DomainName $transportDomain): array
    {
        $domains = [];

        foreach ($rows as $row) {
            if (!self::hasLegacyListShape($row) && !self::namesTransport($row, $transportDomain)) {
                continue;
            }

            try {
                $domain = EmailAddress::parseStored(trim($row->alias))->domain;
                $lower = strtolower($domain->toString());
                $domains[$lower] ??= DomainName::fromStoredValue($lower);
            } catch (DomainError) {
                continue;
            }
        }

        return array_values($domains);
    }

    private static function namesTransport(VirtualRow $row, DomainName $transportDomain): bool
    {
        foreach (DestinationList::fromLegacyString($row->dest)->all() as $destination) {
            if (self::isDestinationOnDomain($destination, $transportDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `dest = username = REPLACE(alias, '@', '.')`, without regard to case or
     * surrounding space.
     */
    private static function hasLegacyListShape(VirtualRow $row): bool
    {
        $alias = strtolower(trim($row->alias));

        if (!str_contains($alias, '@')) {
            return false;
        }

        $key = str_replace('@', '.', $alias);

        return strtolower(trim($row->dest)) === $key
            && strtolower(trim($row->username)) === $key;
    }
}
