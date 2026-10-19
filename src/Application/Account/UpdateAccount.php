<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\AccountServices;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `PATCH /accounts/{account}` — the four service flags and whether the account
 * may log in at all.
 *
 * ## Service flags: carried, not enforced (U-78)
 *
 * The flags are written and shown; the authentication chain does not read
 * them, as it did not under webcyradm. The handler's contract says so; this
 * class does not pretend otherwise by, say, refusing to switch off the last
 * service.
 *
 * ## `enabled`: enforced, and proven before it is claimed (U-127)
 *
 * Disabling writes the account's row in `mailadmin_account_status`, which the
 * view `allusers` leaves out — so `pam_mysql` refuses the account on IMAP, POP,
 * ManageSieve and SMTP-AUTH. Delivery continues.
 *
 * The view is not ours: Salt deploys it, and an installation may still run the
 * old one. So inside the same transaction, after the write, the use case asks
 * the view whether it still offers the login. If it does, the transaction is
 * rolled back and the request refused with `account.status_not_enforced` — a
 * switch that says "disabled" and locks nobody out is what U-78 refused to
 * build. The status table is InnoDB, so the rollback really undoes the write.
 *
 * Enabling needs no such proof: a view that ignores the table already lets the
 * account in.
 *
 * ## Audit
 *
 * `account.disable` / `account.enable` for the status, `account.update` for the
 * flags — one entry per aspect that changed, both in the transaction of the
 * change. A request that changes nothing writes nothing and audits nothing.
 *
 * `If-Match` is checked when sent and not required: both are cheap to set
 * back, which is the line U-55 draws for administrators as well.
 *
 * If the audit entry cannot be written, the change is set back before the error
 * goes to the client (U-86): `accountuser` is MyISAM (O-11), the transaction
 * does not undo the flag `UPDATE`, and no change may stand without its record —
 * the rule U-85 set for the quota. The status is set back only if it did not
 * roll back by itself.
 */
final readonly class UpdateAccount
{
    public const string OPERATION = 'account.update';
    public const string DISABLE = 'account.disable';
    public const string ENABLE = 'account.enable';

    public function __construct(
        private AccountStore $store,
        private AccountLocator $locator,
        private AuditWriter $audit,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<array-key, mixed> $flags   the `services` object of the request
     * @param bool|null               $enabled the `enabled` field; `null` when absent
     *
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(
        ActorContext $actor,
        string $id,
        array $flags,
        ?string $ifMatch,
        ?bool $enabled = null,
    ): AccountRecord {
        $current = $this->locator->forWrite($actor, $id);

        AccountView::requireMatch($current, $ifMatch, required: false);

        $services = AccountDraft::servicesFrom($current->services, $flags);
        $servicesChange = $services->equals($current->services) ? null : $services;
        $statusChange = $enabled === null || $enabled === $current->status->enabled ? null : $enabled;

        if ($servicesChange === null && $statusChange === null) {
            return $current;
        }

        return $this->apply($actor, $current, $servicesChange, $statusChange);
    }

    /**
     * @throws Throwable when the change or its audit entry cannot be written
     */
    private function apply(
        ActorContext $actor,
        AccountRecord $current,
        ?AccountServices $services,
        ?bool $enabled,
    ): AccountRecord {
        $username = $current->storedUsername;

        try {
            $this->store->atomically(function () use ($actor, $current, $services, $enabled, $username): void {
                if ($enabled !== null) {
                    $this->store->setStatus($username, $enabled, $actor->username);

                    if (!$enabled && $this->offersLogin($current)) {
                        throw AccountOperationFailed::statusNotEnforced($username);
                    }
                }

                if ($services !== null) {
                    $this->store->updateServices($username, $services);
                }

                $after = AccountView::full($this->store->find($username) ?? $current);
                $before = AccountView::full($current);

                if ($enabled !== null) {
                    $this->audit->record(AuditEntry::change(
                        $actor,
                        $enabled ? self::ENABLE : self::DISABLE,
                        'account',
                        $username,
                        before: $before,
                        after: $after,
                        domain: $current->domain,
                    ));
                }

                if ($services !== null) {
                    $this->audit->record(AuditEntry::change(
                        $actor,
                        self::OPERATION,
                        'account',
                        $username,
                        before: $before,
                        after: $after,
                        domain: $current->domain,
                    ));
                }
            });
        } catch (Throwable $error) {
            $this->setBack($actor, $current, $services !== null, $enabled !== null);

            throw $error;
        }

        return $this->store->find($username)
            ?? $current->withServices($services ?? $current->services);
    }

    private function offersLogin(AccountRecord $current): bool
    {
        return $this->store->offersLogin(
            $current->storedUsername,
            $current->requireIdentity()->address()->toString(),
        );
    }

    private function setBack(ActorContext $actor, AccountRecord $current, bool $services, bool $status): void
    {
        try {
            if ($services) {
                $this->store->updateServices($current->storedUsername, $current->services);
            }

            // InnoDB has rolled the status back already; only a store that did
            // not is written again — which keeps who disabled it and when.
            if ($status) {
                $now = $this->store->find($current->storedUsername);

                if ($now !== null && $now->status->enabled !== $current->status->enabled) {
                    $this->store->setStatus($current->storedUsername, $current->status->enabled, $actor->username);
                }
            }
        } catch (Throwable $error) {
            $this->logger->error('An account change could not be audited and could not be set back.', [
                'admin' => $actor->username,
                'account' => $current->storedUsername,
                'reason' => $error->getMessage(),
            ]);
        }
    }
}
