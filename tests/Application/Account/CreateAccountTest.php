<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Account;

use Mailadmin\Application\Account\AccountCreated;
use Mailadmin\Application\Account\AccountErrorCode;
use Mailadmin\Application\Account\AccountOperationFailed;
use Mailadmin\Application\Account\CreateAccount;
use Mailadmin\Application\Audit\AuditOutcome;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Password\InvalidPassword;
use Mailadmin\Domain\Policy\ResourceNotVisible;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Mailadmin\Tests\Support\DomainFixture;
use Mailadmin\Tests\Support\RefusingAuditWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Creating an account across two systems that share no transaction.
 *
 * Mailbox first, rows second (architecture 8.1). Most of these tests are about
 * the second half failing: the mailbox is removed again, and only when that
 * fails too does the repair queue get an entry (U-85).
 */
#[CoversClass(CreateAccount::class)]
final class CreateAccountTest extends AccountUseCaseTestCase
{
    public function testTheMailboxComesFirstThenTheRowsThenTheFolders(): void
    {
        $this->givenDomain(DomainFixture::domain('example.test', 20, 20000, null, null, 'example.test', false, false, 'Sent', 'Trash'));

        $created = $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft());

        self::assertSame('anna.example.test', $created->account->storedUsername);
        self::assertSame([], $created->incompleteSteps);
        self::assertSame(
            [
                'create user/anna.example.test',
                'setAcl user/anna.example.test anna.example.test',
                'setQuota user/anna.example.test',
                'createFolder user/anna.example.test/Sent',
                'createFolder user/anna.example.test/Trash',
            ],
            array_values(array_filter(
                $this->mailstore->operations(),
                static fn (string $op): bool => !str_starts_with($op, 'subscribe'),
            )),
        );
        self::assertSame(['lock example.test', 'create anna.example.test'], $this->accounts->operations());
        self::assertSame(20000, $this->mailstore->inner()->getQuota($this->identity('anna.example.test'))->limitKibibytes());
    }

    public function testTheStoredHashIsBcryptSoPamMysqlCanReadIt(): void
    {
        $this->givenDomain(DomainFixture::domain());

        $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft());

        self::assertSame(PasswordFormat::Bcrypt, $this->accounts->formatOf('anna.example.test'));
        self::assertStringStartsWith('$2y$', (string) $this->accounts->hashOf('anna.example.test'));
        self::assertTrue(password_verify(self::PASSWORD, (string) $this->accounts->hashOf('anna.example.test')));
    }

    public function testTheFullyQualifiedSchemeGivesAnAtName(): void
    {
        $this->givenDomain(DomainFixture::domain(scheme: NamingScheme::Fqun));

        $created = $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft());

        self::assertSame('anna@example.test', $created->account->storedUsername);
        self::assertTrue($this->accounts->has('anna@example.test'));
    }

    public function testTheCreationIsAuditedWithoutThePassword(): void
    {
        $this->givenDomain(DomainFixture::domain());

        $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft());

        $entries = $this->audit->withAction(CreateAccount::OPERATION);
        self::assertCount(1, $entries);
        self::assertSame(AuditOutcome::Succeeded, $entries[0]->outcome);
        self::assertSame('anna.example.test', $entries[0]->objectId);

        $encoded = json_encode($entries[0]->after, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::PASSWORD, $encoded);
        self::assertStringNotContainsString('$2y$', $encoded);
    }

    public function testAnUnlimitedQuotaSetsNoQuota(): void
    {
        $this->givenDomain(DomainFixture::domain());

        $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft(quota: Quota::unlimited()));

        self::assertNotContains('setQuota user/anna.example.test', $this->mailstore->operations());
    }

    public function testADatabaseFailureRemovesTheMailboxAgain(): void
    {
        $this->givenDomain(DomainFixture::domain());
        $this->accounts->failOnCreate(PersistenceError::transactionMisuse('the test refused the insert'));

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft()));

        self::assertSame(AccountErrorCode::SETUP_INCOMPLETE, $failure->errorCode);
        self::assertSame(409, $failure->status);
        self::assertFalse($this->mailstore->inner()->mailboxExists($this->identity('anna.example.test')));
        self::assertContains('delete user/anna.example.test', $this->mailstore->operations());
        self::assertTrue($this->repairs->isEmpty(), 'A successful compensation leaves nothing to repair.');

        $failed = $this->audit->withAction(CreateAccount::OPERATION);
        self::assertCount(1, $failed);
        self::assertSame(AuditOutcome::Failed, $failed[0]->outcome);
    }

    public function testAFailedCompensationLeavesARepairEntry(): void
    {
        $this->givenDomain(DomainFixture::domain());
        $this->accounts->failOnCreate(PersistenceError::transactionMisuse('the test refused the insert'));
        $this->mailstore->failNext('deleteMailbox');

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft()));

        self::assertSame(AccountErrorCode::SETUP_INCOMPLETE, $failure->errorCode);
        self::assertSame(['account.create/mailstore.delete_mailbox'], $this->repairs->steps());
        self::assertSame('user/anna.example.test', $this->repairs->entries()[0]->subject);
        self::assertFalse($this->accounts->has('anna.example.test'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mailboxPreparationSteps(): iterable
    {
        yield 'ACL' => ['setAcl'];
        yield 'quota' => ['setQuota'];
    }

    #[DataProvider('mailboxPreparationSteps')]
    public function testAFailureAfterTheMailboxStepIsCompensated(string $step): void
    {
        $this->givenDomain(DomainFixture::domain());
        $this->mailstore->failNext($step);

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft()));

        self::assertSame(AccountErrorCode::SETUP_INCOMPLETE, $failure->errorCode);
        self::assertFalse($this->mailstore->inner()->mailboxExists($this->identity('anna.example.test')));
        self::assertSame([], $this->accounts->operations(), 'No row is written after a failed mailbox step.');
    }

    public function testAMailboxThatAlreadyExistsIsLeftAlone(): void
    {
        $this->givenDomain(DomainFixture::domain());
        $this->mailstore->inner()->given($this->identity('anna.example.test'));

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft()));

        self::assertSame(AccountErrorCode::MAILBOX_EXISTS, $failure->errorCode);
        self::assertTrue($this->mailstore->inner()->mailboxExists($this->identity('anna.example.test')));
        self::assertNotContains('delete user/anna.example.test', $this->mailstore->operations());
    }

    public function testACreateWhoseAnswerIsLostLeavesARepairEntryAndNoRow(): void
    {
        $this->givenDomain(DomainFixture::domain());
        $this->mailstore->loseAnswerNext('createMailbox');

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft()));

        self::assertSame(AccountErrorCode::MAILSTORE_UNAVAILABLE, $failure->errorCode);
        self::assertSame([], $this->accounts->operations());
        // Whether the mailbox is ours is unknown, so it is not removed: it is recorded.
        self::assertTrue($this->mailstore->inner()->mailboxExists($this->identity('anna.example.test')));
        self::assertNotContains('delete user/anna.example.test', $this->mailstore->operations());
        self::assertSame(['account.create/mailstore.create_mailbox_uncertain'], $this->repairs->steps());
        self::assertSame('user/anna.example.test', $this->repairs->entries()[0]->subject);
    }

    public function testACreateWithoutAnswerThatLeftNoMailboxLeavesNoRepairEntry(): void
    {
        $this->givenDomain(DomainFixture::domain());
        $this->mailstore->loseAnswerNext('createMailbox', tookEffect: false);

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft()));

        self::assertSame(AccountErrorCode::MAILSTORE_UNAVAILABLE, $failure->errorCode);
        self::assertFalse($this->mailstore->inner()->mailboxExists($this->identity('anna.example.test')));
        self::assertTrue($this->repairs->isEmpty());
    }

    public function testADefiniteMailstoreRefusalOfTheCreateLeavesNoRepairEntry(): void
    {
        $this->givenDomain(DomainFixture::domain());
        $this->mailstore->failNext('createMailbox');

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft()));

        self::assertSame(AccountErrorCode::MAILSTORE_UNAVAILABLE, $failure->errorCode);
        self::assertTrue($this->repairs->isEmpty());
    }

    public function testADottedNameThatWouldBelongToASubdomainIsRefused(): void
    {
        $this->givenDomain(DomainFixture::domain('example.test', prefix: 'example'));
        $this->givenDomain(DomainFixture::domain('kunde.example.test', prefix: 'kunde'));

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft('info.kunde')));

        self::assertSame(AccountErrorCode::NAME_TAKEN, $failure->errorCode);
        self::assertSame(409, $failure->status);
        self::assertSame([], $this->mailstore->operations());
        self::assertSame([], $this->accounts->operations());
    }

    public function testADottedNameWithoutAMatchingDomainIsAccepted(): void
    {
        $this->givenDomain(DomainFixture::domain());

        $created = $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft('anna.berta'));

        self::assertSame('anna.berta.example.test', $created->account->storedUsername);
    }

    public function testTheFullyQualifiedSchemeIsNotAmbiguousAboutSubdomains(): void
    {
        $this->givenDomain(DomainFixture::domain('example.test', scheme: NamingScheme::Fqun, prefix: 'example'));
        $this->givenDomain(DomainFixture::domain('kunde.example.test', prefix: 'kunde'));

        $created = $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft('info.kunde'));

        self::assertSame('info.kunde@example.test', $created->account->storedUsername);
    }

    public function testFailingDefaultFoldersKeepTheAccountAndReportTheStep(): void
    {
        $this->givenDomain(DomainFixture::domain('example.test', 20, 20000, null, null, 'example.test', false, false, 'Sent'));
        $this->mailstore->failNext('createDefaultFolders');

        $created = $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft());

        self::assertSame([AccountCreated::STEP_DEFAULT_FOLDERS], $created->incompleteSteps);
        self::assertTrue($this->accounts->has('anna.example.test'));
        self::assertSame(['account.create/mailstore.default_folders'], $this->repairs->steps());
    }

    public function testATakenNameIsRefusedBeforeAnythingIsTouched(): void
    {
        $this->givenDomain(DomainFixture::domain());
        $this->accounts->given('anna.example.test', 'example.test');

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft()));

        self::assertSame(AccountErrorCode::NAME_TAKEN, $failure->errorCode);
        self::assertSame([], $this->mailstore->operations());
    }

    public function testAnAliasWithTheSameAddressTakesTheName(): void
    {
        $this->givenDomain(DomainFixture::domain());
        $this->accounts->givenAlias('anna@example.test');

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft()));

        self::assertSame(AccountErrorCode::NAME_TAKEN, $failure->errorCode);
    }

    public function testAUserNameThatAnAliasNamesAsItsSenderIsTaken(): void
    {
        // Review finding on A3: the placeholder of an alias without a sending
        // account is the domain name. An account that later got that name
        // would gain the right to send as every such alias (`validsenders`).
        $this->givenDomain(DomainFixture::domain());
        $this->accounts->givenSenderName('anna.example.test');

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft()));

        self::assertSame(AccountErrorCode::NAME_TAKEN, $failure->errorCode);
        self::assertSame([], $this->mailstore->operations());
    }

    public function testADottedUserNameThatIsTheNameOfADomainIsTaken(): void
    {
        // `mail` in `example.test` is `mail.example.test` — the placeholder the
        // aliases of the domain `mail.example.test` carry (U-106).
        $this->givenDomain(DomainFixture::domain('example.test', prefix: 'example'));
        $this->givenDomain(DomainFixture::domain('mail.example.test', prefix: 'mail'));

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft('mail')));

        self::assertSame(AccountErrorCode::NAME_TAKEN, $failure->errorCode);
        self::assertSame([], $this->mailstore->operations());
        self::assertSame([], $this->accounts->operations());
    }

    public function testTheAccountLimitIsEnforced(): void
    {
        $this->givenDomain(DomainFixture::domain(maxAccounts: 1));
        $this->accounts->given('bert.example.test', 'example.test');

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft()));

        self::assertSame(AccountErrorCode::LIMIT_REACHED, $failure->errorCode);
        self::assertSame([], $this->mailstore->operations());
    }

    public function testAConcurrentCreationUnderTheLockIsCaughtAndCompensated(): void
    {
        $this->givenDomain(DomainFixture::domain(maxAccounts: 1));

        // Another request takes the last place between the courtesy check and
        // the lock.
        $this->accounts->beforeLockedWork(function (): void {
            $this->accounts->given('bert.example.test', 'example.test');
        });

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft()));

        self::assertSame(AccountErrorCode::LIMIT_REACHED, $failure->errorCode);
        self::assertFalse($this->accounts->has('anna.example.test'));
        self::assertFalse($this->mailstore->inner()->mailboxExists($this->identity('anna.example.test')));
        self::assertCount(1, $this->audit->withAction(CreateAccount::OPERATION));
    }

    public function testASubdomainCreatedBeforeTheLockIsCaughtAndCompensated(): void
    {
        // U-97: the ambiguity check is repeated under the namespace lock, which
        // domain creation takes as well. Without it, kunde.example.test could
        // appear between the courtesy check and the insert.
        $this->givenDomain(DomainFixture::domain('example.test', prefix: 'example'));
        $this->domains->beforeNamespaceLockedWork(function (): void {
            $this->domains->given(DomainFixture::domain('kunde.example.test', prefix: 'kunde'));
        });

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft('info.kunde')));

        self::assertSame(AccountErrorCode::NAME_TAKEN, $failure->errorCode);
        self::assertFalse($this->accounts->has('info.kunde.example.test'));
        self::assertFalse($this->mailstore->inner()->mailboxExists($this->identity('info.kunde.example.test')));
        self::assertSame(1, $this->domains->namespaceLocksTaken());
    }

    public function testTheRowsAreWrittenUnderTheNamespaceLock(): void
    {
        $this->givenDomain(DomainFixture::domain());
        $locked = null;
        $this->accounts->beforeLockedWork(function () use (&$locked): void {
            $locked = $this->domains->holdsNamespaceLock();
        });

        $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft());

        self::assertTrue($locked, 'The per-domain lock is taken inside the namespace lock.');
        self::assertFalse($this->domains->holdsNamespaceLock());
    }

    public function testADomainDeletedBeforeTheLockTakesTheMailboxBack(): void
    {
        $this->givenDomain(DomainFixture::domain());
        $this->domains->beforeNamespaceLockedWork(function (): void {
            $this->domains->delete(DomainFixture::name());
        });

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft()));

        self::assertSame(404, $failure->status);
        self::assertFalse($this->accounts->has('anna.example.test'));
        self::assertFalse($this->mailstore->inner()->mailboxExists($this->identity('anna.example.test')));
    }

    public function testAUserNameLongerThanTheVirtualColumnIsRefused(): void
    {
        $this->givenDomain(DomainFixture::domain());

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(
            DomainFixture::superuser(),
            DomainFixture::name(),
            $this->draft(str_repeat('a', 40)),
        ));

        self::assertSame(AccountErrorCode::USERNAME_TOO_LONG, $failure->errorCode);
        self::assertSame(422, $failure->status);
        self::assertSame([], $this->mailstore->operations());
    }

    public function testAQuotaBeyondTheDomainAllowanceIsRefused(): void
    {
        $this->givenDomain(DomainFixture::domain(domainQuotaKib: 30000));
        $this->givenAccount('bert.example.test', quotaKib: 20000);

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(
            DomainFixture::superuser(),
            DomainFixture::name(),
            $this->draft(quota: Quota::ofKibibytes(15000)),
        ));

        self::assertSame(AccountErrorCode::QUOTA_EXCEEDS_DOMAIN, $failure->errorCode);
        self::assertNotContains('create user/anna.example.test', $this->mailstore->operations());
    }

    public function testAQuotaWithinTheDomainAllowanceIsAccepted(): void
    {
        $this->givenDomain(DomainFixture::domain(domainQuotaKib: 30000));
        $this->givenAccount('bert.example.test', quotaKib: 20000);

        $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft(quota: Quota::ofKibibytes(10000)));

        self::assertTrue($this->accounts->has('anna.example.test'));
    }

    public function testAnUnlimitedQuotaInADomainWithAnAllowanceIsRefused(): void
    {
        $this->givenDomain(DomainFixture::domain(domainQuotaKib: 30000));

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(
            DomainFixture::superuser(),
            DomainFixture::name(),
            $this->draft(quota: Quota::unlimited()),
        ));

        self::assertSame(AccountErrorCode::QUOTA_EXCEEDS_DOMAIN, $failure->errorCode);
    }

    public function testAWeakPasswordLeavesNoMailboxBehind(): void
    {
        $this->givenDomain(DomainFixture::domain());

        try {
            $this->createAccount()->handle(
                DomainFixture::superuser(),
                DomainFixture::name(),
                new \Mailadmin\Application\Account\AccountDraft(
                    \Mailadmin\Domain\Model\LocalPart::fromString('anna'),
                    'short',
                    null,
                    \Mailadmin\Domain\Model\AccountServices::all(),
                ),
            );
            self::fail('A short password was accepted.');
        } catch (InvalidPassword) {
        }

        self::assertSame([], $this->mailstore->operations());
    }

    public function testAForeignDomainIsNotVisible(): void
    {
        $this->givenDomain(DomainFixture::domain('other.test', prefix: 'other.test'));

        $this->expectException(ResourceNotVisible::class);

        $this->createAccount()->handle(DomainFixture::domainMaster('anna', 'example.test'), DomainFixture::name('other.test'), $this->draft());
    }

    public function testAMissingDomainIsNotFound(): void
    {
        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name('missing.test'), $this->draft()));

        self::assertSame(404, $failure->status);
    }

    public function testAnAuditFailureInTheDatabaseStepIsCompensated(): void
    {
        $this->givenDomain(DomainFixture::domain());
        $this->auditWriter = RefusingAuditWriter::successes($this->audit);

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft()));

        self::assertSame(AccountErrorCode::SETUP_INCOMPLETE, $failure->errorCode);
        self::assertFalse($this->mailstore->inner()->mailboxExists($this->identity('anna.example.test')));
        // The double does not roll back — like a MyISAM table (O-11). The rows
        // the failed step left behind are removed before the mailbox.
        self::assertFalse($this->accounts->has('anna.example.test'));
        self::assertSame(['lock example.test', 'create anna.example.test', 'delete anna.example.test'], $this->accounts->operations());
    }

    public function testRowsThatCannotBeRemovedKeepTheMailbox(): void
    {
        $this->givenDomain(DomainFixture::domain());
        $this->auditWriter = RefusingAuditWriter::successes($this->audit);
        $this->accounts->failOnDelete(PersistenceError::transactionMisuse('the test refused the cleanup'));

        $failure = $this->expectFailure(fn () => $this->createAccount()->handle(DomainFixture::superuser(), DomainFixture::name(), $this->draft()));

        self::assertSame(AccountErrorCode::SETUP_INCOMPLETE, $failure->errorCode);
        // A row without a mailbox bounces mail; a mailbox under a row does not.
        self::assertTrue($this->mailstore->inner()->mailboxExists($this->identity('anna.example.test')));
        self::assertSame(['account.create/database.remove_rows'], $this->repairs->steps());
    }

    /**
     * @param callable(): mixed $call
     */
    private function expectFailure(callable $call): AccountOperationFailed
    {
        try {
            $call();
        } catch (AccountOperationFailed $failure) {
            return $failure;
        }

        self::fail('The operation was expected to be refused.');
    }
}
