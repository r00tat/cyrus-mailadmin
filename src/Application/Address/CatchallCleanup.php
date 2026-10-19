<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\Virtual\DestinationList;
use Mailadmin\Domain\Model\Virtual\VirtualRow;

/**
 * What an account deletion does to its domain's catchall (U-128, closes O-19).
 *
 * `DeleteAccount` removes the account's rows in `virtual`. Before U-128 it did
 * not look at the catchall `@<domain>`: a catchall that delivered to the
 * account kept pointing at a mailbox that no longer exists, and every unknown
 * address of the domain bounced from then on. Worse, a legacy catchall whose
 * `username` named the account (webcyradm's `catchall.php` wrote the first
 * destination's account there) was removed outright by the deletion's
 * `WHERE username = …` — including any destinations it had elsewhere.
 *
 * This class works out the replacement rows; `DeleteAccount` writes them in the
 * same database step as the deletion. The rules:
 *
 *  - **A destination is dead** when, without regard to case, it is
 *     1. the account's stored user name — unless another account carries that
 *        spelling — or
 *     2. an address whose rows all go with the account (its own address, an
 *        alias with the account as sender, a forward key) and that no other
 *        enabled row and no other account answers for afterwards.
 *    Anything else stays, including destinations that were broken before.
 *  - **A catchall row with dead destinations** loses exactly those. If others
 *    remain, the row is rewritten with them (comma, no space, U-104); if none
 *    remain, it is removed.
 *  - **A catchall row whose `username` is the account** loses that sending
 *    right (the account is gone, and the name must not hand the right to the
 *    next account that gets it, U-110) and is otherwise kept.
 *  - Enabled and disabled rows alike: a disabled catchall that still pointed at
 *    the account would dangle the moment someone switched it back on.
 *
 * Only the catchall of the account's **own** domain is touched. A catchall of
 * another domain that delivers to the account is left alone — changing it
 * would reach into a domain the actor may not manage (O-20). That holds for one
 * that names the account as its sender as well: the deletion no longer removes
 * it either (U-129).
 */
final readonly class CatchallCleanup
{
    public function __construct(
        private AddressStore $store,
        private AddressReader $reader,
    ) {
    }

    /**
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function forDeletedAccount(string $storedUsername, MailboxIdentity $identity): CatchallCleanupPlan
    {
        $domain = $identity->domain;
        $alias = CatchallState::alias($domain);
        $rows = array_values(array_filter(
            $this->store->rowsWithAlias($alias),
            static fn (VirtualRow $row): bool => str_starts_with($row->alias, '@'),
        ));

        $before = new CatchallState($domain, $this->reader->classify($rows));

        if ($rows === []) {
            return CatchallCleanupPlan::nothing($before);
        }

        $dead = [];
        $remove = [];
        $insert = [];
        $kept = [];

        foreach ($rows as $row) {
            $destinations = preg_split('/[\s,]+/', $row->dest, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $remaining = [];

            foreach ($destinations as $destination) {
                if ($this->isDead($destination, $storedUsername, $identity)) {
                    $dead[strtolower($destination)] = $destination;
                } else {
                    $remaining[] = $destination;
                }
            }

            $username = $row->username === $storedUsername ? '' : $row->username;

            if ($remaining === $destinations && $username === $row->username) {
                $kept[] = $row;

                continue;
            }

            $remove[] = $row;

            if ($remaining !== []) {
                $replacement = new VirtualRow(
                    $row->alias,
                    implode(DestinationList::SEPARATOR, $remaining),
                    $username,
                    $row->status,
                );
                $insert[] = $replacement;
                $kept[] = $replacement;
            }
        }

        if ($remove === []) {
            return CatchallCleanupPlan::nothing($before);
        }

        return new CatchallCleanupPlan(
            $before,
            new CatchallState($domain, $this->reader->classify($kept)),
            $remove,
            $insert,
            array_values($dead),
        );
    }

    /**
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function isDead(string $destination, string $storedUsername, MailboxIdentity $identity): bool
    {
        if (strcasecmp($destination, $storedUsername) === 0) {
            return !$this->anotherAccountIsNamed($destination, $storedUsername);
        }

        if (!$this->goesWithTheAccount($destination, $storedUsername, $identity)) {
            return false;
        }

        foreach ($this->store->rowsWithAlias($destination) as $row) {
            if ($row->isEnabled() && !$this->isRemovedWith($row, $storedUsername, $identity->domain)) {
                return false;
            }
        }

        return !$this->anotherAccountIsNamed($destination, $storedUsername);
    }

    /**
     * Is `$key` the account's address, or the key of a row that the deletion
     * removes?
     *
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function goesWithTheAccount(string $key, string $storedUsername, MailboxIdentity $identity): bool
    {
        if (strcasecmp($key, $identity->address()->toString()) === 0) {
            return true;
        }

        foreach ($this->store->rowsWithAlias($key) as $row) {
            if ($this->isRemovedWith($row, $storedUsername, $identity->domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rows `AccountRepository::delete()` removes, by the same rule: its
     * forward key with an empty `username`, or a row of its own domain with
     * the account as `username` — the user names compared as bytes. A row of
     * another domain stays (U-129): an address whose part after the `@` is
     * another domain, a key that names an account of another domain (U-126).
     *
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function isRemovedWith(VirtualRow $row, string $storedUsername, DomainName $domain): bool
    {
        if ($row->alias === $storedUsername && $row->username === '') {
            return true;
        }

        if ($row->username !== $storedUsername) {
            return false;
        }

        $at = strrpos($row->alias, '@');

        if ($at !== false) {
            return strcasecmp(substr($row->alias, $at + 1), $domain->toString()) === 0;
        }

        foreach ($this->store->accountDomains([$row->alias]) as $accountDomain) {
            if (strcasecmp($accountDomain->toString(), $domain->toString()) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function anotherAccountIsNamed(string $name, string $storedUsername): bool
    {
        foreach (array_keys($this->store->accountDomains([$name])) as $account) {
            if ((string) $account !== $storedUsername) {
                return true;
            }
        }

        return false;
    }
}
