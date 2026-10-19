<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\Virtual\DestinationList;

/**
 * What goes into `virtual.username` of an alias or a catchall — which is the
 * question who may **send** as the address (U-106).
 *
 * Postfix does not read `username` for delivery; delivery is `dest`. But the
 * view `validsenders` joins `virtual.username` against `accountuser`, and
 * `smtpd_sender_login_maps` reads that view: the account named there may use
 * the address as its sender. So the field is a sending right, and A3 writes it
 * as one:
 *
 *  - **an account** — the one `sender_account` names, which must be among the
 *    destinations: whoever may send as an address also receives its mail;
 *  - **otherwise the domain name** — webcyradm's alias editor wrote that, it is
 *    no account, and the join finds nothing. Refused when the domain name does
 *    not fit the column (50 characters) or *is* an account's user name, which
 *    in the `dotted` scheme it can be (`example` in `test` is `example.test`).
 *
 * The column must not be left empty: an empty `username` makes the row a
 * forward (architecture 3.4).
 */
final readonly class SenderRules
{
    /** `virtual.username` is `VARCHAR(50)` and stays so (CLAUDE.md, rule one). */
    public const int USERNAME_LIMIT = 50;

    public function __construct(private AddressStore $store)
    {
    }

    /**
     * The stored user name `$requested` names, checked to be an account among
     * the destinations.
     *
     * @throws InvalidAddress
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function explicit(string $requested, DestinationList $destinations): string
    {
        $wanted = strtolower(trim($requested));

        foreach ($this->accountsAmong($destinations) as $account) {
            if (strtolower($account) === $wanted) {
                return $account;
            }
        }

        throw InvalidAddress::unusableDestination($requested);
    }

    /**
     * The default for a new alias: the account, when the alias delivers to
     * exactly one account and nowhere else — webcyradm's "further address"
     * (`newemail.php`) — otherwise nobody.
     *
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function derived(DestinationList $destinations): ?string
    {
        if ($destinations->count() !== 1) {
            return null;
        }

        return $this->accountsAmong($destinations)[0] ?? null;
    }

    /**
     * Is `$username` an account that is still among `$destinations`?
     *
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function stillReceives(string $username, DestinationList $destinations): bool
    {
        foreach ($this->accountsAmong($destinations) as $account) {
            if (strcasecmp($account, $username) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * `username` for an alias nobody may send as.
     *
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function placeholder(DomainName $domain): string
    {
        $name = strtolower($domain->toString());

        if (strlen($name) > self::USERNAME_LIMIT) {
            throw AddressOperationFailed::unstorable(sprintf(
                'The domain name "%s" is longer than virtual.username holds (%d), so an alias without '
                . 'a sending account cannot be stored.',
                $name,
                self::USERNAME_LIMIT,
            ));
        }

        if ($this->store->isAccountName($name)) {
            throw AddressOperationFailed::unstorable(sprintf(
                'The domain name "%s" is also an account\'s user name; writing it as the placeholder '
                . 'would let that account send as the alias.',
                $name,
            ));
        }

        return $name;
    }

    /**
     * @return list<string> stored user names
     *
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function accountsAmong(DestinationList $destinations): array
    {
        if ($destinations->isEmpty()) {
            return [];
        }

        $found = array_map('strval', array_keys($this->store->accountDomains($destinations->all())));
        $ordered = [];

        foreach ($destinations->all() as $destination) {
            foreach ($found as $account) {
                if (strcasecmp($account, $destination) === 0) {
                    $ordered[] = $account;
                }
            }
        }

        return $ordered;
    }
}
