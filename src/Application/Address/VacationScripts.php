<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Domain\Mailstore\SieveStore;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Domain\Repair\RepairRequest;
use Mailadmin\Domain\Sieve\SieveRuleSet;
use Mailadmin\Domain\Sieve\SieveScript;
use Mailadmin\Infrastructure\Mailstore\SieveScriptInstaller;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The Sieve side of the vacation notice: read, install, record — and set back
 * when the record cannot be written (U-85's rule, applied to the script).
 *
 * The script lives in Cyrus, so there is no transaction that holds it together
 * with its audit entry. The order is that of `SetAccountQuota`: change, then
 * audit, and on a failing audit the previous script goes back — stored and
 * activated when there was one, withdrawn when there was none — and so does a
 * script that was stored, inactive, under our name and replaced by a takeover
 * (U-113). Setting it back failing as well is a repair-queue entry with step
 * `sieve.restore`.
 */
final readonly class VacationScripts
{
    public function __construct(
        private SieveStore $scripts,
        private SieveScriptInstaller $installer,
        private AuditWriter $audit,
        private RepairQueue $repairs,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws AddressOperationFailed
     */
    public function state(string $account, MailboxIdentity $identity): VacationState
    {
        try {
            return VacationState::of($account, $this->scripts->activeScript($identity));
        } catch (MailstoreFailure $failure) {
            throw AddressOperationFailed::fromSieve($failure, $account);
        }
    }

    /**
     * Install `$rules` (or withdraw everything, when they are empty), record
     * `$entry`, and set the previous script back when recording fails.
     *
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Domain\Exception\InvalidSieveRule
     * @throws Throwable                                     when the audit entry cannot be written
     */
    public function replace(
        string $operation,
        VacationState $before,
        MailboxIdentity $identity,
        SieveRuleSet $rules,
        bool $takeOver,
        AuditEntry $entry,
    ): void {
        try {
            $stored = $this->storedUnderOurName($before, $identity);
            $this->installer->install($identity, $rules, $takeOver);
        } catch (MailstoreFailure $failure) {
            throw AddressOperationFailed::fromSieve($failure, $before->account);
        }

        $this->record($operation, $before, $identity, $entry, $stored);
    }

    /**
     * Withdraw the active script: ours is deactivated and deleted, a foreign
     * one (the takeover case of `DELETE /accounts/{account}/vacation`) only
     * deactivated.
     *
     * @throws AddressOperationFailed
     * @throws Throwable
     */
    public function remove(string $operation, VacationState $before, MailboxIdentity $identity, AuditEntry $entry): void
    {
        try {
            $stored = $this->storedUnderOurName($before, $identity);

            if ($before->kind === VacationKind::Foreign) {
                // Deactivating is what stops a foreign script; the script
                // itself stays stored, so that whoever wrote it still finds it.
                $this->scripts->deactivate($identity);
            } else {
                $this->installer->remove($identity);
            }
        } catch (MailstoreFailure $failure) {
            throw AddressOperationFailed::fromSieve($failure, $before->account);
        }

        $this->record($operation, $before, $identity, $entry, $stored);
    }

    /**
     * @throws Throwable
     */
    private function record(
        string $operation,
        VacationState $before,
        MailboxIdentity $identity,
        AuditEntry $entry,
        ?SieveScript $stored,
    ): void {
        try {
            $this->audit->record($entry);
        } catch (Throwable $error) {
            $this->restore($operation, $before, $identity, $stored, $error);

            throw $error;
        }
    }

    /**
     * What the name we store under holds before the change — the active
     * script, or an inactive one a takeover is about to replace (U-113).
     *
     * @throws MailstoreFailure
     */
    private function storedUnderOurName(VacationState $before, MailboxIdentity $identity): ?SieveScript
    {
        return $before->script?->name === SieveScript::LEGACY_NAME
            ? $before->script
            : $this->scripts->script($identity, SieveScript::LEGACY_NAME);
    }

    /**
     * Put back what was active, and what was stored under our name.
     *
     * `$stored` is the script that held the name `sieve` before the change.
     * When there was one, it goes back (inactive, unless it is also the active
     * one). When there was none, the `sieve` there now is ours and is removed —
     * but only after reading it: a script without our marker is never deleted
     * here (U-113).
     *
     * @throws MailstoreFailure
     */
    private function setBack(MailboxIdentity $identity, VacationState $before, ?SieveScript $stored): void
    {
        if ($before->script === null) {
            $this->scripts->deactivate($identity);
        } else {
            $this->scripts->putScript($identity, $before->script);
            $this->scripts->activate($identity, $before->script->name);
        }

        if ($before->script?->name === SieveScript::LEGACY_NAME) {
            return;
        }

        if ($stored !== null) {
            $this->scripts->putScript($identity, $stored);

            return;
        }

        if ($this->scripts->script($identity, SieveScript::LEGACY_NAME)?->isManaged() === true) {
            $this->scripts->deleteScript($identity, SieveScript::LEGACY_NAME);
        }
    }

    private function restore(
        string $operation,
        VacationState $before,
        MailboxIdentity $identity,
        ?SieveScript $stored,
        Throwable $cause,
    ): void {
        $this->logger->error('A vacation change could not be audited and is being set back.', [
            'operation' => $operation,
            'account' => $before->account,
            'reason' => $cause->getMessage(),
        ]);

        try {
            $this->setBack($identity, $before, $stored);
        } catch (Throwable $error) {
            $this->logger->error('A vacation change could not be set back.', [
                'operation' => $operation,
                'account' => $before->account,
                'reason' => $error->getMessage(),
            ]);

            try {
                $this->repairs->record(new RepairRequest(
                    operation: $operation,
                    step: 'sieve.restore',
                    subject: $identity->cyrusUsername(),
                    intended: sprintf(
                        'set the Sieve script of %s back after its audit entry failed',
                        $identity->cyrusUsername(),
                    ),
                    reached: 'the changed script is active and no audit entry records the change',
                    reason: $error instanceof MailstoreFailure ? $error->errorCode() : 'sieve.unavailable',
                ));
            } catch (Throwable $repairError) {
                $this->logger->error('The repair entry for a vacation change could not be written.', [
                    'account' => $before->account,
                    'reason' => $repairError->getMessage(),
                ]);
            }
        }
    }
}
