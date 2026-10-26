<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Domain;

use Mailadmin\Application\Audit\AuditOutcome;
use Mailadmin\Application\Domain\DomainErrorCode;
use Mailadmin\Application\Domain\DomainOperationFailed;
use Mailadmin\Application\Domain\RenameDomain;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Mailadmin\Tests\Support\DomainFixture;
use Mailadmin\Tests\Support\RefusingAuditWriter;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The operation the legacy system got wrong.
 *
 * web-cyradm renamed the `domain` row and nothing else. The domain name is part
 * of every Cyrus user name (architecture 3.3), so every account of the domain
 * became unreachable — and there was no message, because nothing had failed.
 *
 * These tests are therefore mostly about failure. The success path is one
 * assertion; the other five are "what is true afterwards when the second system
 * refuses", which is the only question that matters here.
 */
#[CoversClass(RenameDomain::class)]
final class RenameDomainTest extends DomainUseCaseTestCase
{
    public function testEveryMailboxMovesBeforeTheDatabaseDoes(): void
    {
        $this->givenDomainWithTwoAccounts();

        $this->renameDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('old.test'),
            DomainFixture::name('new.test'),
        );

        // The order is the whole rule of architecture 8.1, and only a recording
        // double can see it: a state assertion cannot tell which happened first.
        self::assertSame(
            [
                'rename user/anna.old.test -> user/anna.new.test',
                'rename user/info.old.test -> user/info.new.test',
            ],
            $this->renamesOnly(),
        );
        // The status first, outside the transaction (U-129).
        self::assertSame(
            [
                'carry status anna.old.test -> anna.new.test, info.old.test -> info.new.test',
                'rename old.test -> new.test',
            ],
            $this->store->operations(),
        );
    }

    public function testTheAccountsKeepTheirLocalPartsIncludingTheDotsInThem(): void
    {
        $this->store->given(DomainFixture::domain('old.test'), 'first.last.old.test');
        $this->giveMailbox('first.last.old.test', 'old.test');

        $this->renameDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('old.test'),
            DomainFixture::name('new.test'),
        );

        // A `REPLACE`-style rewrite — which is what the `allusers` view does —
        // would produce `first.last.new.test` only by luck and `first.new.test`
        // where the local part contained the domain.
        self::assertSame(
            ['first.last.new.test'],
            $this->store->accountUsernames(DomainFixture::name('new.test')),
        );
    }

    public function testAnUnreachableMailStoreRefusesBeforeAnythingMoves(): void
    {
        // A1, open point 8. The alternative is renaming the database half and
        // never the mailbox half, which is the legacy defect by another route.
        $this->givenDomainWithTwoAccounts();
        $this->mailstore->makeUnreachable();

        $failure = $this->expectFailure();

        self::assertSame(DomainErrorCode::MAILSTORE_UNAVAILABLE, $failure->errorCode);
        self::assertSame(503, $failure->status);
        self::assertSame([], $this->store->operations());
        self::assertSame([], $this->renamesOnly());
        self::assertTrue($this->store->exists(DomainFixture::name('old.test')));
    }

    public function testADomainWithNoAccountsDoesNotNeedAMailServerAtAll(): void
    {
        $this->store->given(DomainFixture::domain('old.test'));
        $this->mailstore->makeUnreachable();

        $this->renameDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('old.test'),
            DomainFixture::name('new.test'),
        );

        self::assertTrue($this->store->exists(DomainFixture::name('new.test')));
    }

    public function testAFailureInTheMiddleUndoesTheMailboxesAndLeavesTheDatabaseAlone(): void
    {
        $this->givenDomainWithTwoAccounts();
        $this->mailstore->failRename(
            $this->identity('info.old.test', 'old.test'),
            $this->identity('info.new.test', 'new.test'),
        );

        $failure = $this->expectFailure();

        self::assertSame(DomainErrorCode::RENAME_INCOMPLETE, $failure->errorCode);
        self::assertSame(409, $failure->status);

        // anna moved, info refused, anna moved back. Mail keeps arriving under
        // the old name, which is the safe way to fail.
        self::assertSame(
            [
                'rename user/anna.old.test -> user/anna.new.test',
                'rename user/anna.new.test -> user/anna.old.test',
            ],
            array_values(array_filter(
                $this->renamesOnly(),
                static fn (string $entry): bool => str_contains($entry, 'anna'),
            )),
        );
        self::assertSame([], $this->store->operations());
        self::assertTrue($this->mailstore->mailboxExists($this->identity('anna.old.test', 'old.test')));
        self::assertTrue($this->repairs->isEmpty(), 'Nothing was left behind, so nothing to repair.');
    }

    public function testAMailboxThatCannotBeMovedBackEndsUpInTheRepairQueue(): void
    {
        $this->givenDomainWithTwoAccounts();

        // The second account refuses to move, and the first refuses to move
        // back. That is the one state nothing can tidy up on its own.
        $this->mailstore->failRename(
            $this->identity('info.old.test', 'old.test'),
            $this->identity('info.new.test', 'new.test'),
        );
        $this->mailstore->failRename(
            $this->identity('anna.new.test', 'new.test'),
            $this->identity('anna.old.test', 'old.test'),
        );

        $failure = $this->expectFailure();

        self::assertSame(DomainErrorCode::RENAME_INCOMPLETE, $failure->errorCode);
        self::assertSame(['domain.rename/mailstore.rename_back'], $this->repairs->steps());

        $entry = $this->repairs->entries()[0];

        self::assertSame('user/anna.new.test', $entry->subject);
        self::assertStringContainsString('old.test', $entry->reached);
        // What A6 needs to move it back (U-118): the stored names, never one
        // read back out of the mailbox name.
        self::assertSame(
            [
                'account' => 'anna.old.test',
                'domain' => 'old.test',
                'target_account' => 'anna.new.test',
                'target_domain' => 'new.test',
            ],
            $entry->parameters,
        );
    }

    public function testAFailingDatabaseWriteSendsTheMailboxesBack(): void
    {
        // The dangerous state: mailboxes moved, database did not. Every account
        // of the domain would be unreachable — exactly the legacy bug.
        $this->givenDomainWithTwoAccounts();
        $this->store->failOnRename(PersistenceError::transactionMisuse('the test asked this to fail'));

        $failure = $this->expectFailure();

        self::assertSame(DomainErrorCode::RENAME_INCOMPLETE, $failure->errorCode);
        self::assertTrue($this->mailstore->mailboxExists($this->identity('anna.old.test', 'old.test')));
        self::assertTrue($this->mailstore->mailboxExists($this->identity('info.old.test', 'old.test')));
        self::assertFalse($this->mailstore->mailboxExists($this->identity('anna.new.test', 'new.test')));
        self::assertTrue($this->repairs->isEmpty());
    }

    public function testAnAccountWhoseStoredNameContradictsItsDomainStopsTheRenameBeforeItStarts(): void
    {
        // `MailboxIdentity` cannot derive a new name for it, so renaming the
        // domain would strand it. Nothing is touched, and the refusal names it.
        $this->store->given(DomainFixture::domain('old.test'), 'anna.somewhere.else');

        $failure = $this->expectFailure();

        self::assertSame(DomainErrorCode::RENAME_INCOMPLETE, $failure->errorCode);
        self::assertSame([], $this->renamesOnly());
        self::assertSame([], $this->store->operations());
    }

    public function testADomainWithAMailingListIsNotRenamed(): void
    {
        // `lists.address` and the list key in `dest` would keep the old name
        // (plan B5b), so the rename is refused before anything is touched.
        $this->givenDomainWithTwoAccounts();
        $this->lists->given(MailingList::fromStoredValues('Team', '', 'team@old.test', ''));
        $mailstoreBefore = $this->mailstore->operations();

        $failure = $this->expectFailure();

        self::assertSame(DomainErrorCode::RENAME_BLOCKED_BY_MAILING_LIST, $failure->errorCode);
        self::assertSame(409, $failure->status);
        self::assertSame($mailstoreBefore, $this->mailstore->operations(), 'No mailbox was touched, not even probed.');
        self::assertSame([], $this->store->operations());
        self::assertSame(0, $this->lists->writeCount());
        self::assertTrue($this->store->exists(DomainFixture::name('old.test')));
        self::assertSame([], $this->audit->all());
    }

    public function testAMailingListOfAnotherDomainDoesNotBlockTheRename(): void
    {
        $this->givenDomainWithTwoAccounts();
        $this->lists->given(MailingList::fromStoredValues('Team', '', 'team@other.test', ''));
        $this->lists->given(MailingList::fromStoredValues('Sub', '', 'team@sub.old.test', ''));

        $this->renameDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('old.test'),
            DomainFixture::name('new.test'),
        );

        self::assertTrue($this->store->exists(DomainFixture::name('new.test')));
    }

    public function testAMailingListCreatedWhileTheMailboxesMovedStopsTheRenameAndSendsThemBack(): void
    {
        $this->givenDomainWithTwoAccounts();
        $this->store->beforeNamespaceLockedWork(function (): void {
            $this->lists->given(MailingList::fromStoredValues('Team', '', 'team@old.test', ''));
        });

        $failure = $this->expectFailure();

        self::assertSame(DomainErrorCode::RENAME_BLOCKED_BY_MAILING_LIST, $failure->errorCode);
        self::assertTrue($this->mailstore->mailboxExists($this->identity('anna.old.test', 'old.test')));
        self::assertTrue($this->mailstore->mailboxExists($this->identity('info.old.test', 'old.test')));
        self::assertTrue($this->store->exists(DomainFixture::name('old.test')));
        self::assertFalse($this->store->exists(DomainFixture::name('new.test')));
    }

    public function testADomainMasterMayNotRename(): void
    {
        $this->store->given(DomainFixture::domain('old.test'));

        $this->expectException(AccessDenied::class);

        $this->renameDomain()->handle(
            DomainFixture::domainMaster('anna', 'old.test'),
            DomainFixture::name('old.test'),
            DomainFixture::name('new.test'),
        );
    }

    public function testRenamingToTheSameNameDoesNothingAtAll(): void
    {
        $this->givenDomainWithTwoAccounts();

        $this->renameDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('old.test'),
            DomainFixture::name('old.test'),
        );

        self::assertSame([], $this->renamesOnly());
        self::assertSame([], $this->store->operations());
    }

    public function testASuccessfulRenameIsOneOperationEntryWithOneChildPerAccount(): void
    {
        $this->givenDomainWithTwoAccounts();

        $this->renameDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('old.test'),
            DomainFixture::name('new.test'),
        );

        $entries = $this->audit->all();

        self::assertCount(3, $entries);

        [$operation, $anna, $info] = $entries;

        self::assertSame('domain.rename', $operation->action);
        self::assertSame('domain', $operation->objectType);
        self::assertSame('old.test', $operation->objectId);
        self::assertSame('new.test', $operation->domain);
        self::assertSame(AuditOutcome::Succeeded, $operation->outcome);
        self::assertSame('old.test', $operation->before['name'] ?? null);
        self::assertSame('new.test', $operation->after['name'] ?? null);
        self::assertNull($operation->parentId);

        foreach ([$anna, $info] as $child) {
            self::assertSame('domain.rename', $child->action);
            self::assertSame('account', $child->objectType);
            self::assertSame($operation->id, $child->parentId);
        }

        self::assertSame(['username' => 'anna.old.test'], $anna->before);
        self::assertSame(['username' => 'anna.new.test'], $anna->after);
    }

    public function testARenameThatStoppedInTheMailStoreIsRecordedAsFailed(): void
    {
        $this->givenDomainWithTwoAccounts();
        $this->mailstore->failRename(
            $this->identity('info.old.test', 'old.test'),
            $this->identity('info.new.test', 'new.test'),
        );

        $this->expectFailure();

        // One entry, written after the undo: the database still says old.test,
        // so the outcome is `failed`, and nothing changed for any account.
        $entries = $this->audit->all();

        self::assertCount(1, $entries);
        self::assertSame('domain.rename', $entries[0]->action);
        self::assertSame(AuditOutcome::Failed, $entries[0]->outcome);
        self::assertSame('old.test', $entries[0]->objectId);
        self::assertSame('old.test', $entries[0]->domain);
        self::assertSame('old.test', $entries[0]->before['name'] ?? null);
        self::assertNull($entries[0]->after);
        self::assertStringContainsString('mailstore', $entries[0]->reason);
        self::assertStringContainsString('mailboxes restored', $entries[0]->reason);
    }

    public function testAnIncompleteUndoPointsTheEntryAtTheRepairQueue(): void
    {
        $this->givenDomainWithTwoAccounts();
        $this->mailstore->failRename(
            $this->identity('info.old.test', 'old.test'),
            $this->identity('info.new.test', 'new.test'),
        );
        $this->mailstore->failRename(
            $this->identity('anna.new.test', 'new.test'),
            $this->identity('anna.old.test', 'old.test'),
        );

        $this->expectFailure();

        self::assertSame(AuditOutcome::Failed, $this->audit->last()?->outcome);
        self::assertStringContainsString('see repair queue', $this->audit->last()->reason);
        self::assertSame(1, $this->audit->count(), 'The stranded mailbox is in the repair queue, not in a second row.');
    }

    public function testAFailingDatabaseWriteIsRecordedAsFailed(): void
    {
        $this->givenDomainWithTwoAccounts();
        $this->store->failOnRename(PersistenceError::transactionMisuse('the test asked this to fail'));

        $failure = $this->expectFailure();

        self::assertStringNotContainsString('the test asked this to fail', $failure->getMessage());
        self::assertSame(1, $this->audit->count());
        self::assertSame(AuditOutcome::Failed, $this->audit->last()?->outcome);
        self::assertStringContainsString('database', $this->audit->last()->reason);
    }

    public function testAFailingAuditWriteSendsTheMailboxesBack(): void
    {
        // U-63: if the record cannot be written, the change does not stand —
        // and for a rename "the change" includes the mailboxes.
        $this->givenDomainWithTwoAccounts();
        $this->auditWriter = RefusingAuditWriter::successes($this->audit);

        $failure = $this->expectFailure();

        self::assertSame(DomainErrorCode::RENAME_INCOMPLETE, $failure->errorCode);
        self::assertTrue($this->mailstore->mailboxExists($this->identity('anna.old.test', 'old.test')));
        self::assertTrue($this->mailstore->mailboxExists($this->identity('info.old.test', 'old.test')));
        self::assertFalse($this->mailstore->mailboxExists($this->identity('anna.new.test', 'new.test')));
        self::assertSame(AuditOutcome::Failed, $this->audit->last()?->outcome);
    }

    public function testABrokenAuditTableDoesNotHideWhyTheRenameFailed(): void
    {
        $this->givenDomainWithTwoAccounts();
        $this->store->failOnRename(PersistenceError::transactionMisuse('the test asked this to fail'));
        $this->auditWriter = RefusingAuditWriter::everything($this->audit);

        // Still the answer that says whether the mailboxes came back, not a 500.
        self::assertSame(DomainErrorCode::RENAME_INCOMPLETE, $this->expectFailure()->errorCode);
    }

    public function testRefusalsBeforeAnythingMovedWriteNoEntry(): void
    {
        $this->givenDomainWithTwoAccounts();
        $this->mailstore->makeUnreachable();

        $this->expectFailure();

        self::assertTrue($this->audit->isEmpty());
    }

    public function testRenamingToTheSameNameWritesNoEntry(): void
    {
        $this->givenDomainWithTwoAccounts();

        $this->renameDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('old.test'),
            DomainFixture::name('old.test'),
        );

        self::assertTrue($this->audit->isEmpty());
    }

    public function testATargetWhoseUserNamesAreAlreadyInUseIsRefusedBeforeAnythingMoves(): void
    {
        // `info.kunde.example.test` is example.test's account `info.kunde`; as
        // the domain kunde.example.test, old.test's accounts would share that
        // namespace (U-96).
        $this->givenDomainWithTwoAccounts();
        $this->store->given(DomainFixture::domain('example.test'), 'info.kunde.example.test');

        $failure = $this->renameFailure('old.test', 'kunde.example.test');

        self::assertSame(DomainErrorCode::NAMESPACE_CONFLICT, $failure->errorCode);
        self::assertSame(409, $failure->status);
        self::assertSame([], $this->renamesOnly());
        self::assertSame([], $this->store->operations());
        self::assertTrue($this->audit->isEmpty());
    }

    public function testAMovedAccountThatWouldLandInASubdomainsNamespaceIsRefused(): void
    {
        // old.test's `info.kunde` becomes `info.kunde.example.test` — the name
        // the existing kunde.example.test's `info` has, or will have.
        $this->store->given(DomainFixture::domain('old.test'), 'info.kunde.old.test');
        $this->giveMailbox('info.kunde.old.test', 'old.test');
        $this->store->given(DomainFixture::domain('kunde.example.test'));

        $failure = $this->renameFailure('old.test', 'example.test');

        self::assertSame(DomainErrorCode::NAMESPACE_CONFLICT, $failure->errorCode);
        self::assertSame([], $this->renamesOnly());
    }

    public function testTheDomainsOwnAccountsDoNotBlockItsRename(): void
    {
        // example.test -> kunde.example.test: `info.kunde.example.test` is
        // example.test's own account and moves along to
        // `info.kunde.kunde.example.test`. Nothing is left behind to collide.
        $this->store->given(DomainFixture::domain('example.test'), 'info.kunde.example.test');
        $this->giveMailbox('info.kunde.example.test', 'example.test');

        $this->renameDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('example.test'),
            DomainFixture::name('kunde.example.test'),
        );

        self::assertSame(
            ['info.kunde.kunde.example.test'],
            $this->store->accountUsernames(DomainFixture::name('kunde.example.test')),
        );
    }

    public function testAConflictThatArrivesBeforeTheLockSendsTheMailboxesBack(): void
    {
        // The checks are repeated under the namespace lock in the database step
        // (U-97). The mailboxes already moved by then, so a refusal there takes
        // the compensation path.
        $this->givenDomainWithTwoAccounts();
        $this->store->beforeNamespaceLockedWork(function (): void {
            $this->store->given(DomainFixture::domain('example.test'), 'info.kunde.example.test');
        });

        $failure = $this->renameFailure('old.test', 'kunde.example.test');

        self::assertSame(DomainErrorCode::NAMESPACE_CONFLICT, $failure->errorCode);
        self::assertTrue($this->mailstore->mailboxExists($this->identity('anna.old.test', 'old.test')));
        self::assertTrue($this->mailstore->mailboxExists($this->identity('info.old.test', 'old.test')));
        self::assertTrue($this->store->exists(DomainFixture::name('old.test')));
        self::assertFalse($this->store->exists(DomainFixture::name('kunde.example.test')));
        self::assertCount(1, $this->audit->all());
        self::assertSame(AuditOutcome::Failed, $this->audit->all()[0]->outcome);
    }

    public function testATargetTakenBeforeTheLockSendsTheMailboxesBack(): void
    {
        $this->givenDomainWithTwoAccounts();
        $this->store->beforeNamespaceLockedWork(function (): void {
            $this->store->given(DomainFixture::domain('new.test'));
        });

        $failure = $this->expectFailure();

        self::assertSame(DomainErrorCode::NAME_TAKEN, $failure->errorCode);
        self::assertTrue($this->mailstore->mailboxExists($this->identity('anna.old.test', 'old.test')));
        self::assertSame([], $this->store->operations());
    }

    public function testAChangeOfSpellingIsNotAConflictWithItself(): void
    {
        $this->store->given(DomainFixture::domain('old.test'), 'a.b.old.test');
        $this->giveMailbox('a.b.old.test', 'old.test');

        $this->renameDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('old.test'),
            DomainFixture::name('OLD.test'),
        );

        self::assertTrue($this->store->exists(DomainFixture::name('OLD.test')));
    }

    public function testAnAccountCreatedWhileTheMailboxesMovedStopsTheRenameAndSendsThemBack(): void
    {
        // U-100: account creation takes the namespace lock only for its
        // database step. carl.old.test committed while anna and info moved;
        // the plan does not know it, and the database half would move carl's
        // domain without its user name — the legacy defect for one account.
        $this->givenDomainWithTwoAccounts();
        $this->store->beforeNamespaceLockedWork(function (): void {
            $this->store->given(DomainFixture::domain('old.test'), 'anna.old.test', 'carl.old.test', 'info.old.test');
            $this->giveMailbox('carl.old.test', 'old.test');
        });

        $failure = $this->expectFailure();

        self::assertSame(DomainErrorCode::RENAME_INCOMPLETE, $failure->errorCode);
        self::assertSame(409, $failure->status);
        self::assertSame([], $this->store->operations());
        self::assertTrue($this->store->exists(DomainFixture::name('old.test')));
        self::assertTrue($this->mailstore->mailboxExists($this->identity('anna.old.test', 'old.test')));
        self::assertTrue($this->mailstore->mailboxExists($this->identity('info.old.test', 'old.test')));
        self::assertTrue($this->mailstore->mailboxExists($this->identity('carl.old.test', 'old.test')));
        self::assertTrue($this->repairs->isEmpty());
        self::assertCount(1, $this->audit->all());
        self::assertSame(AuditOutcome::Failed, $this->audit->all()[0]->outcome);
    }

    public function testADomainDeletedBeforeTheLockIsNotRenamedAndNotReportedAsRenamed(): void
    {
        // U-100: an empty domain needs no mailbox step, so a deletion can
        // commit between the courtesy check and the lock.
        $this->store->given(DomainFixture::domain('old.test'));
        $this->store->beforeNamespaceLockedWork(function (): void {
            $this->store->delete(DomainFixture::name('old.test'));
        });

        $failure = $this->expectFailure();

        self::assertSame(DomainErrorCode::NOT_FOUND, $failure->errorCode);
        self::assertSame(404, $failure->status);
        self::assertSame(['delete old.test'], $this->store->operations());
        self::assertFalse($this->store->exists(DomainFixture::name('new.test')));

        foreach ($this->audit->all() as $entry) {
            self::assertNotSame(AuditOutcome::Succeeded, $entry->outcome);
        }
    }

    public function testALeftoverForwardUnderAMovedAccountsNewNameRefusesTheRename(): void
    {
        // U-101: `clara.new.test` is the key of a forward row an earlier
        // account of new.test left behind. clara would inherit it.
        $this->store->given(DomainFixture::domain('old.test'), 'clara.old.test');
        $this->giveMailbox('clara.old.test', 'old.test');
        $this->store->givenAlias('Clara.New.test');

        $failure = $this->expectFailure();

        self::assertSame(DomainErrorCode::NAMESPACE_CONFLICT, $failure->errorCode);
        self::assertSame([], $this->renamesOnly());
        self::assertSame([], $this->store->operations());
    }

    public function testAMovedAccountsNewNameThatARowNamesAsSenderRefusesTheRename(): void
    {
        // U-110: `validsenders` joins `virtual.username` against `accountuser`.
        // clara would inherit the sending right of every row naming her new name.
        $this->store->given(DomainFixture::domain('old.test'), 'clara.old.test');
        $this->giveMailbox('clara.old.test', 'old.test');
        $this->store->givenSenderName('Clara.New.test');

        $failure = $this->expectFailure();

        self::assertSame(DomainErrorCode::NAMESPACE_CONFLICT, $failure->errorCode);
        self::assertSame([], $this->renamesOnly());
        self::assertSame([], $this->store->operations());
    }

    public function testAMovedAccountsNewNameThatIsADomainNameRefusesTheRename(): void
    {
        // U-110: `mail.new.test` is the alias placeholder of the domain of that name.
        $this->store->given(DomainFixture::domain('old.test'), 'mail.old.test');
        $this->giveMailbox('mail.old.test', 'old.test');
        $this->store->given(DomainFixture::domain('mail.new.test', prefix: 'mailnew'));

        $failure = $this->expectFailure();

        self::assertSame(DomainErrorCode::NAMESPACE_CONFLICT, $failure->errorCode);
        self::assertSame([], $this->renamesOnly());
    }

    public function testTheMovedAccountsForwardMovesWithIt(): void
    {
        $this->store->given(DomainFixture::domain('old.test'), 'clara.old.test');
        $this->giveMailbox('clara.old.test', 'old.test');
        $this->store->givenAlias('clara.old.test');
        $this->store->givenAlias('clara@old.test');

        $this->renameDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('old.test'),
            DomainFixture::name('new.test'),
        );

        self::assertSame(['clara.new.test', 'clara@new.test'], $this->store->aliases());
    }

    public function testATargetWithLeftoverAssignmentsIsRefusedBeforeAnythingMoves(): void
    {
        // U-99: a domain master's assignment survived an earlier new.test.
        $this->givenDomainWithTwoAccounts();
        $this->store->givenAssignment('master', 'New.Test');

        $failure = $this->expectFailure();

        self::assertSame(DomainErrorCode::LEFTOVER_STATE, $failure->errorCode);
        self::assertSame(409, $failure->status);
        self::assertSame([], $this->renamesOnly());
        self::assertSame([], $this->store->operations());
    }

    public function testATargetWithLeftoverAddressesIsRefusedUnderTheLockToo(): void
    {
        // U-99: a catchall written (by webcyradm, say) while the mailboxes moved.
        $this->givenDomainWithTwoAccounts();
        $this->store->beforeNamespaceLockedWork(function (): void {
            $this->store->givenAlias('@new.test');
        });

        $failure = $this->expectFailure();

        self::assertSame(DomainErrorCode::LEFTOVER_STATE, $failure->errorCode);
        self::assertSame([], $this->store->operations());
        self::assertTrue($this->mailstore->mailboxExists($this->identity('anna.old.test', 'old.test')));
    }

    public function testAChangeOfSpellingIsNotLeftoverStateOfItself(): void
    {
        $this->store->given(DomainFixture::domain('old.test'));
        $this->store->givenAssignment('master', 'old.test');
        $this->store->givenAlias('@old.test');

        $this->renameDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('old.test'),
            DomainFixture::name('OLD.test'),
        );

        self::assertTrue($this->store->exists(DomainFixture::name('OLD.test')));
    }

    private function renameFailure(string $from, string $to): DomainOperationFailed
    {
        try {
            $this->renameDomain()->handle(
                DomainFixture::superuser(),
                DomainFixture::name($from),
                DomainFixture::name($to),
            );
        } catch (DomainOperationFailed $failure) {
            return $failure;
        }

        self::fail('The rename was expected to be refused.');
    }

    /**
     * The mail-store operations a rename made, without the ones the fixture
     * setup made putting the mailboxes there in the first place.
     *
     * @return list<string>
     */
    private function renamesOnly(): array
    {
        return array_values(array_filter(
            $this->mailstore->operations(),
            static fn (string $entry): bool => str_starts_with($entry, 'rename '),
        ));
    }

    private function givenDomainWithTwoAccounts(): void
    {
        $this->store->given(DomainFixture::domain('old.test'), 'anna.old.test', 'info.old.test');
        $this->giveMailbox('anna.old.test', 'old.test');
        $this->giveMailbox('info.old.test', 'old.test');
    }

    private function expectFailure(): DomainOperationFailed
    {
        try {
            $this->renameDomain()->handle(
                DomainFixture::superuser(),
                DomainFixture::name('old.test'),
                DomainFixture::name('new.test'),
            );
        } catch (DomainOperationFailed $failure) {
            return $failure;
        }

        self::fail('The rename was expected to be refused.');
    }

    private function identity(string $storedUsername, string $domain): MailboxIdentity
    {
        return MailboxIdentity::fromStoredUsername($storedUsername, DomainName::fromStoredValue($domain));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function transportDomainSpellings(): iterable
    {
        yield 'as configured' => ['maillist.invalid'];
        yield 'upper case with a trailing dot' => ['MailList.Invalid.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('transportDomainSpellings')]
    public function testNoDomainIsRenamedToTheListTransportDomain(string $name): void
    {
        $this->givenDomainWithTwoAccounts();
        $storeBefore = $this->store->operations();
        $mailstoreBefore = $this->mailstore->operations();

        try {
            $this->renameDomain()->handle(
                DomainFixture::superuser(),
                DomainFixture::name('old.test'),
                DomainName::fromStoredValue($name),
            );
            self::fail('The domain was renamed to the transport domain.');
        } catch (\Mailadmin\Domain\Exception\InvalidAddress $refused) {
            self::assertSame('domain.invalid_name', $refused->code());
        }

        self::assertSame($storeBefore, $this->store->operations());
        self::assertSame($mailstoreBefore, $this->mailstore->operations(), 'No mailbox was touched.');
    }
}
