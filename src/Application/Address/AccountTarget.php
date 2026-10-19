<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Account\AccountOperationFailed;
use Mailadmin\Application\Account\AccountRecord;
use Mailadmin\Application\Account\AccountStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Policy\AddressPolicy;

/**
 * `/accounts/{account}/forwards` and `/accounts/{account}/vacation` → the
 * account, with **A3's** policy applied.
 *
 * Not A2's `AccountLocator`: that one checks `account.*`, and a forward or a
 * vacation notice is a question of where mail for an address goes — `address.*`
 * (`AddressPolicy`). The order is A2's for the same reason A2 gives: the domain
 * of an account is not in its identifier and is only known once the row has
 * been read. A missing account and a foreign one end in the same `404`.
 *
 * A row without an identity (U-84) can be read — its forward rows are in
 * `virtual` whatever its name says — but not written: a write needs the
 * account's address, and that is what such a row cannot give.
 */
final readonly class AccountTarget
{
    public function __construct(private AccountStore $accounts)
    {
    }

    /**
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function forRead(ActorContext $actor, string $id): AccountRecord
    {
        $record = $this->find($id);

        if ($record->identity === null) {
            AddressPolicy::requireListIn($actor, $record->domain);
        } else {
            AddressPolicy::requireRead($actor, $record->identity->address());
        }

        return $record;
    }

    /**
     * @return array{0: AccountRecord, 1: MailboxIdentity}
     *
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function forWrite(ActorContext $actor, string $id): array
    {
        $record = $this->forRead($actor, $id);
        $identity = self::identityOf($record);
        AddressPolicy::requireWrite($actor, $identity->address());

        return [$record, $identity];
    }

    /**
     * @return array{0: AccountRecord, 1: MailboxIdentity}
     *
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function forDelete(ActorContext $actor, string $id): array
    {
        $record = $this->forRead($actor, $id);
        $identity = self::identityOf($record);
        AddressPolicy::requireDelete($actor, $identity->address());

        return [$record, $identity];
    }

    /**
     * The account again, inside the lock — it may have been deleted between
     * the first read and the write.
     *
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function stillThere(AccountRecord $record): AccountRecord
    {
        return $this->find($record->storedUsername);
    }

    /**
     * @throws AddressOperationFailed
     */
    public static function identityOf(AccountRecord $record): MailboxIdentity
    {
        try {
            return $record->requireIdentity();
        } catch (AccountOperationFailed $failure) {
            throw AddressOperationFailed::fromAccount($failure);
        }
    }

    /**
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function find(string $id): AccountRecord
    {
        return $this->accounts->find($id) ?? throw AddressOperationFailed::notFound('such account');
    }
}
