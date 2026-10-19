<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Domain\Repair\RepairRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `PUT /accounts/{account}/quota`.
 *
 * The change happens in Cyrus, so there is no transaction that could hold it
 * together with its audit entry (U-63). The order is therefore: change, then
 * audit — and if the audit entry cannot be written, the limit is set back, so
 * that no change stands without its record (U-85). Setting it back failing as
 * well is a repair-queue entry.
 *
 * A raise — a higher limit, none, or a first limit on an account that had none
 * — is checked against the domain's allowance first (U-48, U-81, U-86). Lowering
 * an existing limit is not.
 */
final readonly class SetAccountQuota
{
    public const string OPERATION = 'account.quota.set';

    public function __construct(
        private AccountLocator $locator,
        private DomainStore $domains,
        private Mailstore $mailstore,
        private DomainAllowance $allowance,
        private RepairQueue $repairs,
        private AuditWriter $audit,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     * @throws Throwable when the audit entry cannot be written
     */
    public function handle(ActorContext $actor, string $id, Quota $requested): Quota
    {
        $record = $this->locator->forWrite($actor, $id);
        $identity = $record->requireIdentity();

        $current = $this->read($identity);

        if (self::raises($current, $requested)) {
            $domain = $this->domains->find($record->domain) ?? throw AccountOperationFailed::notFound('such domain');
            $this->allowance->requireRoomFor($domain->domain, $identity, $requested);
        }

        $this->write($identity, $requested);

        try {
            $this->audit->record(AuditEntry::change(
                $actor,
                self::OPERATION,
                'account',
                $record->storedUsername,
                before: ['limit_kib' => $current->limitKibibytes()],
                after: ['limit_kib' => $requested->limitKibibytes()],
                domain: $record->domain,
            ));
        } catch (Throwable $error) {
            $this->restore($identity, $current, $requested);

            throw $error;
        }

        $used = $current->usedKibibytes();

        return $used === null ? $requested : $requested->withUsage($used);
    }

    /**
     * Does the change need the allowance check? Measured by what it does to the
     * domain's allocated sum, not by the limit alone (U-86): an account without
     * a limit counts as `0` there ({@see DomainAllowance}), so giving it one
     * adds that limit to the sum and is checked. Only lowering an existing
     * limit is free.
     */
    public static function raises(Quota $current, Quota $requested): bool
    {
        $before = $current->limitKibibytes();
        $after = $requested->limitKibibytes();

        if ($after === null) {
            return $before !== null;
        }

        return $before === null || $after > $before;
    }

    /**
     * @throws AccountOperationFailed
     */
    private function read(MailboxIdentity $identity): Quota
    {
        try {
            return $this->mailstore->getQuota($identity);
        } catch (MailstoreFailure $failure) {
            throw AccountOperationFailed::fromMailstore($failure, $identity->imapMailbox());
        }
    }

    /**
     * @throws AccountOperationFailed
     */
    private function write(MailboxIdentity $identity, Quota $quota): void
    {
        try {
            $this->mailstore->setQuota($identity, $quota);
        } catch (MailstoreFailure $failure) {
            throw AccountOperationFailed::fromMailstore($failure, $identity->imapMailbox());
        }
    }

    private function restore(MailboxIdentity $identity, Quota $previous, Quota $requested): void
    {
        $this->logger->error('A quota change could not be audited and is being set back.', [
            'account' => $identity->cyrusUsername(),
        ]);

        try {
            $limit = $previous->limitKibibytes();
            $this->mailstore->setQuota($identity, $limit === null ? Quota::unlimited() : Quota::ofKibibytes($limit));
        } catch (MailstoreFailure $failure) {
            $this->repairs->record(new RepairRequest(
                operation: self::OPERATION,
                step: 'mailstore.restore_quota',
                subject: $identity->imapMailbox(),
                intended: sprintf(
                    'set the limit of %s back to %s KiB after its audit entry failed',
                    $identity->imapMailbox(),
                    $previous->limitKibibytes() ?? 'no',
                ),
                reached: sprintf(
                    'the limit is %s KiB and no audit entry records the change',
                    $requested->limitKibibytes() ?? 'no',
                ),
                reason: $failure->errorCode(),
                // The empty string is "no limit", on both sides.
                parameters: [
                    'account' => $identity->cyrusUsername(),
                    'domain' => $identity->domain->toString(),
                    'limit_kib' => (string) ($previous->limitKibibytes() ?? ''),
                    'current_kib' => (string) ($requested->limitKibibytes() ?? ''),
                ],
            ));
        }
    }
}
