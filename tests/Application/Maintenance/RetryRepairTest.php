<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Maintenance;

use Mailadmin\Application\Account\AccountCreated;
use Mailadmin\Application\Account\CreateAccount;
use Mailadmin\Application\Audit\AuditOutcome;
use Mailadmin\Application\Maintenance\GetRepair;
use Mailadmin\Application\Maintenance\ListRepairs;
use Mailadmin\Application\Maintenance\RepairCatalogue;
use Mailadmin\Application\Maintenance\RepairErrorCode;
use Mailadmin\Application\Maintenance\RepairLedger;
use Mailadmin\Application\Maintenance\RepairOperationFailed;
use Mailadmin\Application\Maintenance\RepairResolution;
use Mailadmin\Application\Maintenance\RepairStatus;
use Mailadmin\Application\Maintenance\ResolveRepair;
use Mailadmin\Application\Maintenance\RetryRepair;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Password\InMemoryCommonPasswordList;
use Mailadmin\Domain\Password\PasswordPolicy;
use Mailadmin\Domain\Repair\RepairRequest;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Repair\InMemoryRepairLedger;
use Mailadmin\Tests\Application\Account\AccountUseCaseTestCase;
use Mailadmin\Tests\Support\DomainFixture;
use Mailadmin\Tests\Support\FrozenClock;
use Mailadmin\Tests\Support\Repair\StaleFirstReadLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;

/**
 * The repair queue as A6 works it: retry what the application can finish,
 * refuse what it cannot, and never act on a state that moved on (U-118).
 */
final class RetryRepairTest extends AccountUseCaseTestCase
{
    private InMemoryRepairLedger $ledger;

    /** What the use cases see; the ledger itself unless a test puts a double in front. */
    private ?RepairLedger $port = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new InMemoryRepairLedger();
    }

    // ------------------------------------------------- the DoD round trip

    public function testAFailedCreationLandsInTheQueueAndCanBeRetried(): void
    {
        $this->givenDomain(DomainFixture::domain('example.test', 20, 20000, null, null, 'example.test', false, false, 'Sent', 'Trash'));
        $this->mailstore->failNext('createDefaultFolders');

        $created = $this->creating()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft());

        self::assertSame([AccountCreated::STEP_DEFAULT_FOLDERS], $created->incompleteSteps);

        $entries = $this->ledger->entries();
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame('account.create', $entry->operation);
        self::assertSame(RepairCatalogue::DEFAULT_FOLDERS, $entry->step);
        self::assertSame(
            ['account' => 'anna.example.test', 'domain' => 'example.test', 'folders' => 'Sent,Trash'],
            $entry->parameters,
        );
        self::assertTrue($entry->toRepresentation()['retryable']);

        $identity = $this->identity('anna.example.test');
        self::assertFalse($this->mailstore->inner()->isSubscribed($identity, 'Sent'));

        $after = $this->retry()->handle(DomainFixture::superuser(), $entry->id);

        self::assertTrue($this->mailstore->inner()->isSubscribed($identity, 'Sent'));
        self::assertTrue($this->mailstore->inner()->isSubscribed($identity, 'Trash'));
        self::assertSame(RepairStatus::Resolved, $after->status);
        self::assertSame(RepairResolution::Retried, $after->resolution);
        self::assertSame('root', $after->resolvedBy);

        $audit = $this->audit->withAction(RetryRepair::ACTION);
        self::assertCount(1, $audit);
    }

    public function testAResolvedEntryIsNotRetriedTwice(): void
    {
        $id = $this->givenFolderEntry();
        $this->retry()->handle(DomainFixture::superuser(), $id);

        $failure = $this->expectRefusal(fn () => $this->retry()->handle(DomainFixture::superuser(), $id));

        self::assertSame(RepairErrorCode::ALREADY_RESOLVED, $failure->errorCode);
        self::assertSame(409, $failure->status);
    }

    public function testAnUnknownEntryIsNotFound(): void
    {
        $failure = $this->expectRefusal(fn () => $this->retry()->handle(DomainFixture::superuser(), 99));

        self::assertSame(RepairErrorCode::NOT_FOUND, $failure->errorCode);
        self::assertSame(404, $failure->status);
    }

    public function testAFailingRetryCountsTheAttemptAndKeepsTheEntryOpen(): void
    {
        $id = $this->givenFolderEntry();
        $this->mailstore->failNext('createDefaultFolders');

        $failure = $this->expectRefusal(fn () => $this->retry()->handle(DomainFixture::superuser(), $id));

        self::assertSame(RepairErrorCode::RETRY_FAILED, $failure->errorCode);

        $entry = $this->ledger->find($id);
        self::assertNotNull($entry);
        self::assertTrue($entry->isOpen());
        self::assertSame(1, $entry->attempts);
        self::assertNotSame('', $entry->lastError);
        self::assertNotNull($entry->lastAttemptAt);

        $last = $this->audit->last();
        self::assertNotNull($last);
        self::assertSame(RetryRepair::ACTION, $last->action);
        self::assertSame(AuditOutcome::Failed, $last->outcome);
    }

    public function testAnUnreachableMailStoreAnswers503(): void
    {
        $id = $this->givenFolderEntry();
        $this->mailstore->makeUnreachable();

        $failure = $this->expectRefusal(fn () => $this->retry()->handle(DomainFixture::superuser(), $id));

        self::assertSame(RepairErrorCode::RETRY_FAILED, $failure->errorCode);
        self::assertSame(503, $failure->status);
    }

    public function testFoldersAreNotCreatedForAnAccountThatIsGone(): void
    {
        $id = $this->record(RepairCatalogue::DEFAULT_FOLDERS, [
            'account' => 'ghost.example.test',
            'domain' => 'example.test',
            'folders' => 'Sent',
        ]);

        $failure = $this->expectRefusal(fn () => $this->retry()->handle(DomainFixture::superuser(), $id));

        self::assertSame(RepairErrorCode::STATE_CHANGED, $failure->errorCode);
        self::assertSame([], $this->mailstore->operations());
        self::assertSame(1, $this->ledger->find($id)?->attempts);
    }

    // --------------------------------------------------- what is manual

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function manualEntries(): iterable
    {
        yield 'uncertain creation' => ['mailstore.create_mailbox_uncertain', ['account' => 'a.example.test']];
        yield 'rows left behind' => ['database.remove_rows', []];
        yield 'audit' => ['audit.record', []];
        yield 'virtual rows' => ['virtual.restore', []];
        yield 'sieve' => ['sieve.restore', []];
        yield 'retryable step without parameters' => [RepairCatalogue::DELETE_MAILBOX, []];
        yield 'quota without its old limit' => [RepairCatalogue::RESTORE_QUOTA, ['account' => 'a.example.test', 'domain' => 'example.test']];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('manualEntries')]
    public function testManualEntriesAreRefusedAndLeaveNoTrace(string $step, array $parameters): void
    {
        $id = $this->record($step, $parameters);

        $failure = $this->expectRefusal(fn () => $this->retry()->handle(DomainFixture::superuser(), $id));

        self::assertSame(RepairErrorCode::NOT_RETRYABLE, $failure->errorCode);
        self::assertSame(0, $this->ledger->find($id)?->attempts);
        self::assertSame([], $this->mailstore->operations());
        self::assertFalse($this->ledger->find($id)?->toRepresentation()['retryable']);
    }

    public function testAnEntryWithAnUnusableAccountNameIsNotGuessedAt(): void
    {
        $id = $this->record(RepairCatalogue::DELETE_MAILBOX, ['account' => 'bob.other.test', 'domain' => 'example.test']);

        $failure = $this->expectRefusal(fn () => $this->retry()->handle(DomainFixture::superuser(), $id));

        self::assertSame(RepairErrorCode::NOT_RETRYABLE, $failure->errorCode);
        self::assertSame([], $this->mailstore->operations());
    }

    // ------------------------------------------------------------ delete

    public function testALeftoverMailboxIsDeletedOnRetry(): void
    {
        $identity = $this->identity('anna.example.test');
        $this->mailstore->inner()->given($identity);
        $id = $this->record(RepairCatalogue::DELETE_MAILBOX, ['account' => 'anna.example.test', 'domain' => 'example.test']);

        $this->retry()->handle(DomainFixture::superuser(), $id);

        self::assertFalse($this->mailstore->inner()->mailboxExists($identity));
        self::assertFalse($this->ledger->find($id)?->isOpen());
        self::assertTrue($this->domains->namespaceLocksTaken() > 0);
    }

    public function testAMailboxThatBelongsToANewAccountIsNotDeleted(): void
    {
        $this->givenAccount('anna.example.test');
        $id = $this->record(RepairCatalogue::DELETE_MAILBOX, ['account' => 'anna.example.test', 'domain' => 'example.test']);

        $failure = $this->expectRefusal(fn () => $this->retry()->handle(DomainFixture::superuser(), $id));

        self::assertSame(RepairErrorCode::STATE_CHANGED, $failure->errorCode);
        self::assertTrue($this->mailstore->inner()->mailboxExists($this->identity('anna.example.test')));
        self::assertTrue($this->ledger->find($id)?->isOpen());
    }

    public function testTheDeletionEntryOfAFailedDeleteCarriesWhatTheRetryNeeds(): void
    {
        $this->givenAccount('anna.example.test');
        $this->mailstore->failNext('deleteMailbox');

        $this->deleting()->handle(DomainFixture::superuser(), 'anna.example.test', $this->etagOf('anna.example.test'));

        $entry = $this->ledger->entries()[0];
        self::assertSame(['account' => 'anna.example.test', 'domain' => 'example.test'], $entry->parameters);

        $this->retry()->handle(DomainFixture::superuser(), $entry->id);

        self::assertFalse($this->mailstore->inner()->mailboxExists($this->identity('anna.example.test')));
    }

    // ------------------------------------------------------------- quota

    public function testAQuotaIsSetBackWhenItIsStillTheChangedOne(): void
    {
        $this->givenAccount('anna.example.test', quotaKib: 5000);
        $id = $this->record(RepairCatalogue::RESTORE_QUOTA, [
            'account' => 'anna.example.test',
            'domain' => 'example.test',
            'limit_kib' => '10000',
            'current_kib' => '5000',
        ]);

        $this->retry()->handle(DomainFixture::superuser(), $id);

        self::assertSame(10000, $this->mailstore->inner()->getQuota($this->identity('anna.example.test'))->limitKibibytes());
    }

    public function testAnUnlimitedQuotaIsRestoredAsUnlimited(): void
    {
        $this->givenAccount('anna.example.test', quotaKib: 5000);
        $id = $this->record(RepairCatalogue::RESTORE_QUOTA, [
            'account' => 'anna.example.test',
            'domain' => 'example.test',
            'limit_kib' => '',
            'current_kib' => '5000',
        ]);

        $this->retry()->handle(DomainFixture::superuser(), $id);

        self::assertNull($this->mailstore->inner()->getQuota($this->identity('anna.example.test'))->limitKibibytes());
    }

    public function testAQuotaChangedSinceIsLeftAlone(): void
    {
        $this->givenAccount('anna.example.test', quotaKib: 7000);
        $id = $this->record(RepairCatalogue::RESTORE_QUOTA, [
            'account' => 'anna.example.test',
            'domain' => 'example.test',
            'limit_kib' => '10000',
            'current_kib' => '5000',
        ]);

        $failure = $this->expectRefusal(fn () => $this->retry()->handle(DomainFixture::superuser(), $id));

        self::assertSame(RepairErrorCode::STATE_CHANGED, $failure->errorCode);
        self::assertSame(7000, $this->mailstore->inner()->getQuota($this->identity('anna.example.test'))->limitKibibytes());
    }

    public function testTheQuotaEntryOfAFailedChangeCarriesBothLimits(): void
    {
        $this->givenDomain(DomainFixture::domain());
        $this->givenAccount('anna.example.test', quotaKib: 10000);
        $this->auditWriter = \Mailadmin\Tests\Support\RefusingAuditWriter::everything($this->audit);
        $this->mailstore->failNext('setQuota', after: 1);

        try {
            $this->setQuotaWithLedger()->handle(DomainFixture::superuser(), 'anna.example.test', Quota::ofKibibytes(5000));
            self::fail('The change was reported as done.');
        } catch (\Throwable) {
        }

        $entry = $this->ledger->entries()[0];
        self::assertSame(RepairCatalogue::RESTORE_QUOTA, $entry->step);
        self::assertSame('10000', $entry->parameter('limit_kib'));
        self::assertSame('5000', $entry->parameter('current_kib'));

        $this->retry()->handle(DomainFixture::superuser(), $entry->id);

        self::assertSame(10000, $this->mailstore->inner()->getQuota($this->identity('anna.example.test'))->limitKibibytes());
    }

    // ------------------------------------------------------- rename back

    public function testAMailboxLeftUnderTheNewDomainIsMovedBack(): void
    {
        $this->accounts->given('anna.old.test', 'old.test');
        $moved = $this->identity('anna.new.test', 'new.test');
        $this->mailstore->inner()->given($moved);
        $id = $this->record(RepairCatalogue::RENAME_BACK, [
            'account' => 'anna.old.test',
            'domain' => 'old.test',
            'target_account' => 'anna.new.test',
            'target_domain' => 'new.test',
        ]);

        $this->retry()->handle(DomainFixture::superuser(), $id);

        self::assertFalse($this->mailstore->inner()->mailboxExists($moved));
        self::assertTrue($this->mailstore->inner()->mailboxExists($this->identity('anna.old.test', 'old.test')));
    }

    public function testARenameThatWasRepeatedIsNotUndone(): void
    {
        // The rename went through on a second attempt: the rows carry the new
        // name now, and the mailbox under it is where it belongs.
        $this->accounts->given('anna.new.test', 'new.test');
        $moved = $this->identity('anna.new.test', 'new.test');
        $this->mailstore->inner()->given($moved);
        $id = $this->record(RepairCatalogue::RENAME_BACK, [
            'account' => 'anna.old.test',
            'domain' => 'old.test',
            'target_account' => 'anna.new.test',
            'target_domain' => 'new.test',
        ]);

        $failure = $this->expectRefusal(fn () => $this->retry()->handle(DomainFixture::superuser(), $id));

        self::assertSame(RepairErrorCode::STATE_CHANGED, $failure->errorCode);
        self::assertTrue($this->mailstore->inner()->mailboxExists($moved));
    }

    public function testBothMailboxesPresentIsNotResolvedByRenaming(): void
    {
        $this->accounts->given('anna.old.test', 'old.test');
        $this->mailstore->inner()->given($this->identity('anna.old.test', 'old.test'));
        $this->mailstore->inner()->given($this->identity('anna.new.test', 'new.test'));
        $id = $this->record(RepairCatalogue::RENAME_BACK, [
            'account' => 'anna.old.test',
            'domain' => 'old.test',
            'target_account' => 'anna.new.test',
            'target_domain' => 'new.test',
        ]);

        $failure = $this->expectRefusal(fn () => $this->retry()->handle(DomainFixture::superuser(), $id));

        self::assertSame(RepairErrorCode::STATE_CHANGED, $failure->errorCode);
    }

    // ------------------------------------- idempotent after a lost close

    public function testAQuotaAlreadyRestoredOnlyClosesTheEntry(): void
    {
        // An earlier retry set the limit back, then closing the entry failed.
        $this->givenAccount('anna.example.test', quotaKib: 10000);
        $id = $this->record(RepairCatalogue::RESTORE_QUOTA, [
            'account' => 'anna.example.test',
            'domain' => 'example.test',
            'limit_kib' => '10000',
            'current_kib' => '5000',
        ]);

        $after = $this->retry()->handle(DomainFixture::superuser(), $id);

        self::assertSame(RepairResolution::Retried, $after->resolution);
        self::assertSame(0, $after->attempts);
        self::assertSame([], $this->mailstore->operations());
    }

    public function testAMailboxAlreadyMovedBackOnlyClosesTheEntry(): void
    {
        $this->accounts->given('anna.old.test', 'old.test');
        $this->mailstore->inner()->given($this->identity('anna.old.test', 'old.test'));
        $id = $this->record(RepairCatalogue::RENAME_BACK, [
            'account' => 'anna.old.test',
            'domain' => 'old.test',
            'target_account' => 'anna.new.test',
            'target_domain' => 'new.test',
        ]);

        $after = $this->retry()->handle(DomainFixture::superuser(), $id);

        self::assertSame(RepairResolution::Retried, $after->resolution);
        self::assertSame([], $this->mailstore->operations());
        self::assertTrue($this->mailstore->inner()->mailboxExists($this->identity('anna.old.test', 'old.test')));
    }

    // ------------------------------------------------- concurrent closing

    public function testARetryDoesNotActOnAnEntryDismissedAfterItWasRead(): void
    {
        $identity = $this->identity('anna.example.test');
        $this->mailstore->inner()->given($identity);
        $id = $this->record(RepairCatalogue::DELETE_MAILBOX, ['account' => 'anna.example.test', 'domain' => 'example.test']);
        $this->port = new StaleFirstReadLedger($this->ledger);

        // Somebody dismisses the entry — "keep the mailbox" — after this retry
        // has read it as open.
        $this->ledger->resolve($id, RepairResolution::Dismissed, 'admin', 'keep it', (new FrozenClock())->now());

        $failure = $this->expectRefusal(fn () => $this->retry()->handle(DomainFixture::superuser(), $id));

        self::assertSame(RepairErrorCode::ALREADY_RESOLVED, $failure->errorCode);
        self::assertTrue($this->mailstore->inner()->mailboxExists($identity), 'The dismissed deletion ran anyway.');
        self::assertSame([], $this->audit->withAction(RetryRepair::ACTION));
        self::assertSame(RepairResolution::Dismissed, $this->ledger->find($id)?->resolution);
    }

    public function testRetryAndDismissalBothHoldTheEntryLock(): void
    {
        $retried = $this->givenFolderEntry();
        $dismissed = $this->record('virtual.restore', []);

        $this->retry()->handle(DomainFixture::superuser(), $retried);
        self::assertSame(1, $this->ledger->locksTaken());

        (new ResolveRepair($this->ledger, $this->audit, new FrozenClock()))->handle(DomainFixture::superuser(), $dismissed);
        self::assertSame(2, $this->ledger->locksTaken());
    }

    public function testAnEntryClosedBehindTheLockIsNotReportedAsRetried(): void
    {
        $id = $this->givenFolderEntry();
        $this->port = new StaleFirstReadLedger($this->ledger, refuseResolve: true);

        $failure = $this->expectRefusal(fn () => $this->retry()->handle(DomainFixture::superuser(), $id));

        self::assertSame(RepairErrorCode::ALREADY_RESOLVED, $failure->errorCode);
        self::assertSame([], $this->audit->withAction(RetryRepair::ACTION));
    }

    public function testAFailedAttemptIsNotCountedOnAClosedEntry(): void
    {
        $id = $this->record('virtual.restore', []);
        $this->ledger->resolve($id, RepairResolution::Dismissed, 'root', '', (new FrozenClock())->now());

        $this->ledger->recordAttempt($id, 'mailstore.unavailable', (new FrozenClock())->now());

        self::assertSame(0, $this->ledger->find($id)?->attempts);
        self::assertSame('', $this->ledger->find($id)?->lastError);
    }

    // ----------------------------------------------- dismiss, get, list

    public function testAnEntryIsDismissedWithANote(): void
    {
        $id = $this->record('virtual.restore', []);

        $after = (new ResolveRepair($this->ledger, $this->audit, new FrozenClock()))
            ->handle(DomainFixture::superuser(), $id, '  fixed by hand  ');

        self::assertSame(RepairStatus::Resolved, $after->status);
        self::assertSame(RepairResolution::Dismissed, $after->resolution);
        self::assertSame('fixed by hand', $after->note);
        self::assertCount(1, $this->audit->withAction(ResolveRepair::ACTION));

        $failure = $this->expectRefusal(
            fn () => (new ResolveRepair($this->ledger, $this->audit, new FrozenClock()))->handle(DomainFixture::superuser(), $id),
        );
        self::assertSame(RepairErrorCode::ALREADY_RESOLVED, $failure->errorCode);
    }

    public function testDismissingAnUnknownEntryIsNotFound(): void
    {
        $failure = $this->expectRefusal(
            fn () => (new ResolveRepair($this->ledger, $this->audit, new FrozenClock()))->handle(DomainFixture::superuser(), 7),
        );

        self::assertSame(404, $failure->status);
        self::assertTrue($this->audit->isEmpty());
    }

    public function testListAndGetShowTheEntries(): void
    {
        $first = $this->record('virtual.restore', []);
        $second = $this->record('sieve.restore', []);

        $page = (new ListRepairs($this->ledger))->handle(
            DomainFixture::superuser(),
            new ListCriteria(order: ListRepairs::defaultOrder()),
        );

        self::assertSame([$second, $first], array_map(static fn ($entry): int => $entry->id, $page->items));
        self::assertSame('sieve.restore', (new GetRepair($this->ledger))->handle(DomainFixture::superuser(), $second)->step);

        $failure = $this->expectRefusal(fn () => (new GetRepair($this->ledger))->handle(DomainFixture::superuser(), 42));
        self::assertSame(404, $failure->status);
    }

    // ----------------------------------------------------------- helpers

    private function retry(): RetryRepair
    {
        return new RetryRepair(
            $this->port ?? $this->ledger,
            $this->mailstore,
            $this->accounts,
            $this->domains,
            $this->audit,
            new FrozenClock(),
            new NullLogger(),
        );
    }

    private function creating(): CreateAccount
    {
        return new CreateAccount(
            $this->accounts,
            $this->domains,
            $this->defaults,
            $this->mailstore,
            $this->allowance(),
            new PasswordPolicy(new InMemoryCommonPasswordList()),
            self::hasher(),
            $this->ledger,
            $this->auditWriter,
            new NullLogger(),
        );
    }

    private function deleting(): \Mailadmin\Application\Account\DeleteAccount
    {
        return new \Mailadmin\Application\Account\DeleteAccount(
            $this->accounts,
            $this->locator(),
            $this->mailstore,
            $this->ledger,
            $this->auditWriter,
            new NullLogger(),
            $this->domains,
            $this->virtual,
            new \Mailadmin\Application\Address\CatchallCleanup(
                $this->virtual,
                \Mailadmin\Tests\Support\ListDeliveryFixture::reader($this->virtual),
            ),
            new \Mailadmin\Application\Address\RowWriter($this->virtual, $this->auditWriter, $this->ledger, new NullLogger()),
        );
    }

    private function setQuotaWithLedger(): \Mailadmin\Application\Account\SetAccountQuota
    {
        return new \Mailadmin\Application\Account\SetAccountQuota(
            $this->locator(),
            $this->domains,
            $this->mailstore,
            $this->allowance(),
            $this->ledger,
            $this->auditWriter,
            new NullLogger(),
        );
    }

    private function givenFolderEntry(): int
    {
        $this->givenAccount('anna.example.test');

        return $this->record(RepairCatalogue::DEFAULT_FOLDERS, [
            'account' => 'anna.example.test',
            'domain' => 'example.test',
            'folders' => 'Sent',
        ]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function record(string $step, array $parameters): int
    {
        $this->ledger->record(new RepairRequest(
            'test.operation',
            $step,
            'user/anna.example.test',
            'what was intended',
            'what was reached',
            'test',
            $parameters,
        ));
        $this->mailstore->inner()->forget();

        $entries = $this->ledger->entries();

        return $entries[array_key_last($entries)]->id;
    }

    private function expectRefusal(callable $action): RepairOperationFailed
    {
        try {
            $action();
        } catch (RepairOperationFailed $failure) {
            return $failure;
        }

        self::fail('The operation was not refused.');
    }
}
