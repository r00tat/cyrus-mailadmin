<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Policy\AccountPolicy;

/**
 * `/accounts/{account}` → the row, with the policy applied.
 *
 * ## Why the lookup comes before the policy here
 *
 * A1 checks the policy first, because the domain is in the path. An account's
 * domain is **not** in its identifier — `anna.example.test` cannot be taken
 * apart (architecture 3.3) — so it is only known once the row has been read.
 * The two refusals still end in the same document: a missing row is
 * {@see AccountOperationFailed::notFound()}, a foreign one is the policy's
 * `ResourceNotVisible`, and both are rendered by `ProblemDetails::notFound()`
 * (U-44). What a caller could still measure is one indexed lookup by primary
 * key, the same for both.
 *
 * ## Unresolvable rows
 *
 * A row whose stored name contradicts its domain has no identity, and the
 * policies take one. For such a row the domain-level checks apply — the same
 * permission, the same scope test — and a write is then refused with
 * `account.identity_mismatch`: its mailbox cannot be derived, so nothing that
 * reaches Cyrus can be done to it safely.
 */
final readonly class AccountLocator
{
    public function __construct(private AccountStore $store)
    {
    }

    /**
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function forRead(ActorContext $actor, string $id): AccountRecord
    {
        $record = $this->find($id);

        if ($record->identity === null) {
            AccountPolicy::requireListIn($actor, $record->domain);
        } else {
            AccountPolicy::requireRead($actor, $record->identity);
        }

        return $record;
    }

    /**
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function forWrite(ActorContext $actor, string $id): AccountRecord
    {
        $record = $this->resolvable($actor, $id);
        AccountPolicy::requireWrite($actor, $record->requireIdentity());

        return $record;
    }

    /**
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function forDelete(ActorContext $actor, string $id): AccountRecord
    {
        $record = $this->resolvable($actor, $id);
        AccountPolicy::requireDelete($actor, $record->requireIdentity());

        return $record;
    }

    /**
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function forPasswordChange(ActorContext $actor, string $id): AccountRecord
    {
        $record = $this->resolvable($actor, $id);
        AccountPolicy::requirePasswordChange($actor, $record->requireIdentity());

        return $record;
    }

    /**
     * The row, refused with `account.identity_mismatch` when it has no
     * identity — but only once the actor has been shown to see its domain, so
     * that the `409` cannot tell a foreign row from a missing one.
     *
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function resolvable(ActorContext $actor, string $id): AccountRecord
    {
        $record = $this->find($id);

        if ($record->identity === null) {
            AccountPolicy::requireListIn($actor, $record->domain);
            $record->requireIdentity();
        }

        return $record;
    }

    /**
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function find(string $id): AccountRecord
    {
        return $this->store->find($id) ?? throw AccountOperationFailed::notFound('such account');
    }
}
