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
 *  1. its `dest` ends in `@<transport domain>` — the form A5 writes, or
 *  2. it has the legacy form `dest = username = REPLACE(alias, '@', '.')`
 *     **and** `lists` has a row whose address is the `alias`.
 *
 * The second needs `lists` because the legacy form is, field for field, also
 * what an account's own address row in the `dotted` scheme looks like.
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
     * Rule 1: the row delivers to the list transport.
     */
    public function deliversToTransport(VirtualRow $row): bool
    {
        return str_ends_with(
            strtolower(trim($row->dest)),
            '@' . strtolower($this->transportDomain->toString()),
        );
    }

    /**
     * Rule 2: the legacy form, confirmed by a `lists` row.
     */
    public function isLegacyListRow(VirtualRow $row): bool
    {
        return self::hasLegacyListShape($row) && $this->isListAddress($row->alias);
    }

    /**
     * The domains whose list addresses rule 2 needs for these rows: the domain
     * of every row with the legacy shape, each once, lower-cased.
     *
     * @param iterable<VirtualRow> $rows
     *
     * @return list<DomainName>
     */
    public static function domainsToLookUp(iterable $rows): array
    {
        $domains = [];

        foreach ($rows as $row) {
            if (!self::hasLegacyListShape($row)) {
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
