<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\MailingList\ListDelivery;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\Virtual\MailingListRecognition;
use Mailadmin\Domain\Model\Virtual\VirtualEntryClassifier;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Infrastructure\Persistence\MailingListRepository;

/**
 * Reads rows and classifies them — the one way A3's use cases see `virtual`.
 *
 * The classifier needs `accountuser.domain_name` of every account a row names
 * (architecture 3.3: a user name cannot be taken apart), so the store is asked
 * once per set of rows, not once per row.
 *
 * It also needs to recognise mailing list rows (A5): the transport domain from
 * {@see ListDelivery}, and the addresses in `lists` of every domain a row with
 * the legacy list shape belongs to — again once per domain, and not at all
 * when no row has that shape.
 */
final readonly class AddressReader
{
    public function __construct(
        private AddressStore $store,
        private VirtualEntryClassifier $classifier,
        private MailingListRepository $lists,
        private ListDelivery $delivery,
    ) {
    }

    /**
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function ofDomain(DomainName $domain): ClassifiedRows
    {
        return $this->classify($this->store->rowsOfDomain($domain));
    }

    /**
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function withAlias(string $alias): ClassifiedRows
    {
        return $this->classify($this->store->rowsWithAlias($alias));
    }

    /**
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function forwardsOf(string $storedUsername): ClassifiedRows
    {
        return $this->classify($this->store->forwardRowsOf($storedUsername));
    }

    /**
     * The domains whose accounts a bare key (an `alias` without `@`) names —
     * without regard to case, the way Postfix looks the key up (U-126). Empty
     * when the key names no account.
     *
     * @return list<DomainName>
     *
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function domainsOfKey(string $key): array
    {
        $domains = [];

        foreach ($this->store->accountDomains([$key]) as $domain) {
            $domains[strtolower($domain->toString())] = $domain;
        }

        return array_values($domains);
    }

    /**
     * @param list<VirtualRow> $rows
     *
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function classify(array $rows): ClassifiedRows
    {
        $names = [];

        foreach ($rows as $row) {
            $username = trim($row->username);

            if ($username !== '') {
                $names[strtolower($username)] = $username;
            }
        }

        $domains = $names === [] ? [] : $this->store->accountDomains(array_values($names));

        $accounts = [];
        $stored = [];

        foreach ($domains as $username => $domain) {
            $accounts[strtolower((string) $username)] = $domain;
            $stored[strtolower((string) $username)] = (string) $username;
        }

        return new ClassifiedRows(
            $this->classifier->classifyAll($rows, $this->recognition($rows), $domains),
            $accounts,
            $stored,
        );
    }

    /**
     * @param list<VirtualRow> $rows
     *
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function recognition(array $rows): MailingListRecognition
    {
        $addresses = [];

        foreach (MailingListRecognition::domainsToLookUp($rows) as $domain) {
            foreach ($this->lists->addressesInDomain($domain) as $address) {
                $addresses[] = $address;
            }
        }

        return new MailingListRecognition($this->delivery->transportDomain, $addresses);
    }
}
