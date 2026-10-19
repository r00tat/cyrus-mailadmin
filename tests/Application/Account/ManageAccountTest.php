<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Account;

use Mailadmin\Application\Account\AccountErrorCode;
use Mailadmin\Application\Account\AccountLocator;
use Mailadmin\Application\Account\AccountOperationFailed;
use Mailadmin\Application\Account\DeleteAccount;
use Mailadmin\Application\Account\GetAccount;
use Mailadmin\Application\Account\GetAccountQuota;
use Mailadmin\Application\Account\ListAccounts;
use Mailadmin\Application\Account\SetAccountPassword;
use Mailadmin\Application\Account\SetAccountQuota;
use Mailadmin\Application\Account\UpdateAccount;
use Mailadmin\Application\Address\CatchallCleanup;
use Mailadmin\Application\Address\DeleteCatchall;
use Mailadmin\Application\Address\SetCatchall;
use Mailadmin\Application\Audit\AuditOutcome;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Password\InvalidPassword;
use Mailadmin\Domain\Policy\ResourceNotVisible;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Mailadmin\Tests\Support\DomainFixture;
use Mailadmin\Tests\Support\RefusingAuditWriter;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Everything after the creation: reading, listing, the service flags, the
 * password, the quota, and the deletion.
 */
#[CoversClass(AccountLocator::class)]
#[CoversClass(GetAccount::class)]
#[CoversClass(ListAccounts::class)]
#[CoversClass(UpdateAccount::class)]
#[CoversClass(SetAccountPassword::class)]
#[CoversClass(GetAccountQuota::class)]
#[CoversClass(SetAccountQuota::class)]
#[CoversClass(DeleteAccount::class)]
#[CoversClass(CatchallCleanup::class)]
final class ManageAccountTest extends AccountUseCaseTestCase
{
    // ---------------------------------------------------------------- read

    public function testAnAccountInAForeignDomainIsNotVisible(): void
    {
        $this->givenAccount('bert.other.test', 'other.test');

        $this->expectException(ResourceNotVisible::class);

        $this->getAccount()->handle(DomainFixture::domainMaster('anna', 'example.test'), 'bert.other.test');
    }

    public function testAMissingAccountIsNotFound(): void
    {
        $failure = $this->expectFailure(fn () => $this->getAccount()->handle(DomainFixture::superuser(), 'nobody.example.test'));

        self::assertSame(404, $failure->status);
    }

    public function testARowThatDoesNotFitItsDomainIsShownButNotChanged(): void
    {
        $this->accounts->given('stray', 'example.test');

        $record = $this->getAccount()->handle(DomainFixture::superuser(), 'stray');
        self::assertFalse($record->isResolvable());

        $failure = $this->expectFailure(fn () => $this->updateAccount()->handle(DomainFixture::superuser(), 'stray', ['pop' => false], null));
        self::assertSame(AccountErrorCode::IDENTITY_MISMATCH, $failure->errorCode);
    }

    public function testAnUnfittingRowInAForeignDomainIsStillNotVisible(): void
    {
        $this->accounts->given('stray', 'other.test');

        $this->expectException(ResourceNotVisible::class);

        $this->updateAccount()->handle(DomainFixture::domainMaster('anna', 'example.test'), 'stray', ['pop' => false], null);
    }

    public function testTheListingIsScopedToTheDomainAndFilterable(): void
    {
        $this->givenDomain(DomainFixture::domain());
        $this->givenAccount('anna.example.test');
        $this->givenAccount('bert.example.test');
        $this->givenAccount('carl.other.test', 'other.test');
        $this->accounts->given('legacy.example.test', 'example.test', hash: 'plaintext-secret');

        $page = $this->listAccounts()->handle(DomainFixture::superuser(), DomainFixture::name(), ListCriteria::fromQuery([]));
        self::assertSame(
            ['anna.example.test', 'bert.example.test', 'legacy.example.test'],
            array_map(static fn ($record): string => $record->storedUsername, $page->items),
        );

        $weak = $this->listAccounts()->handle(
            DomainFixture::superuser(),
            DomainFixture::name(),
            ListCriteria::fromQuery(['password_format' => PasswordFormat::Plaintext->value]),
        );
        self::assertSame(['legacy.example.test'], array_map(static fn ($record): string => $record->storedUsername, $weak->items));
    }

    public function testListingAMissingDomainIsNotFound(): void
    {
        $failure = $this->expectFailure(fn () => $this->listAccounts()->handle(DomainFixture::superuser(), DomainFixture::name('missing.test'), ListCriteria::fromQuery([])));

        self::assertSame(404, $failure->status);
    }

    // -------------------------------------------------------------- update

    public function testTheServiceFlagsChangeAndAreAudited(): void
    {
        $this->givenAccount('anna.example.test');

        $record = $this->updateAccount()->handle(DomainFixture::superuser(), 'anna.example.test', ['pop' => false], null);

        self::assertFalse($record->services->pop);
        self::assertTrue($record->services->imap);
        self::assertCount(1, $this->audit->withAction(UpdateAccount::OPERATION));
    }

    public function testAnUnchangedUpdateWritesNothing(): void
    {
        $this->givenAccount('anna.example.test');

        $this->updateAccount()->handle(DomainFixture::superuser(), 'anna.example.test', ['pop' => true], null);

        self::assertSame([], $this->accounts->operations());
        self::assertTrue($this->audit->isEmpty());
    }

    public function testAStaleEtagIsRefused(): void
    {
        $this->givenAccount('anna.example.test');

        $failure = $this->expectFailure(fn () => $this->updateAccount()->handle(DomainFixture::superuser(), 'anna.example.test', ['pop' => false], '"stale"'));

        self::assertSame(412, $failure->status);
    }

    public function testAServiceChangeThatCannotBeAuditedIsSetBack(): void
    {
        // The in-memory store rolls nothing back, like the MyISAM legacy tables (O-11).
        $this->givenAccount('anna.example.test');
        $this->auditWriter = RefusingAuditWriter::everything($this->audit);

        try {
            $this->updateAccount()->handle(DomainFixture::superuser(), 'anna.example.test', ['pop' => false], null);
            self::fail('The change was reported as done.');
        } catch (\Throwable $error) {
            self::assertNotInstanceOf(AccountOperationFailed::class, $error);
        }

        self::assertTrue($this->accounts->find('anna.example.test')?->services->pop);
    }

    // ------------------------------------------------- enabled (U-127)

    public function testDisablingIsStoredAuditedAndShown(): void
    {
        $this->givenAccount('anna.example.test');

        $record = $this->updateAccount()->handle(DomainFixture::superuser(), 'anna.example.test', [], null, enabled: false);

        self::assertFalse($record->status->enabled);
        self::assertSame(DomainFixture::superuser()->username, $record->status->disabledBy);
        self::assertNotNull($record->status->disabledAt);
        self::assertSame(['disable anna.example.test'], $this->accounts->operations());

        $entries = $this->audit->withAction(UpdateAccount::DISABLE);
        self::assertCount(1, $entries);
        self::assertTrue($entries[0]->before['enabled'] ?? null);
        self::assertFalse($entries[0]->after['enabled'] ?? null);
        self::assertSame([], $this->audit->withAction(UpdateAccount::OPERATION));
    }

    public function testEnablingAgainIsAuditedSeparately(): void
    {
        $this->givenAccount('anna.example.test');
        $this->updateAccount()->handle(DomainFixture::superuser(), 'anna.example.test', [], null, enabled: false);

        $record = $this->updateAccount()->handle(DomainFixture::superuser(), 'anna.example.test', [], null, enabled: true);

        self::assertTrue($record->status->enabled);
        self::assertNull($record->status->disabledAt);
        self::assertCount(1, $this->audit->withAction(UpdateAccount::ENABLE));
    }

    public function testAnUnchangedStatusWritesNothing(): void
    {
        $this->givenAccount('anna.example.test');

        $this->updateAccount()->handle(DomainFixture::superuser(), 'anna.example.test', [], null, enabled: true);

        self::assertSame([], $this->accounts->operations());
        self::assertTrue($this->audit->isEmpty());
    }

    public function testStatusAndServicesInOneRequestAreTwoEntries(): void
    {
        $this->givenAccount('anna.example.test');

        $record = $this->updateAccount()->handle(DomainFixture::superuser(), 'anna.example.test', ['pop' => false], null, enabled: false);

        self::assertFalse($record->status->enabled);
        self::assertFalse($record->services->pop);
        self::assertCount(1, $this->audit->withAction(UpdateAccount::DISABLE));
        self::assertCount(1, $this->audit->withAction(UpdateAccount::OPERATION));
    }

    public function testADomainMasterDisablesAnAccountOfTheDomain(): void
    {
        $this->givenAccount('bert.example.test');

        $record = $this->updateAccount()->handle(DomainFixture::domainMaster('anna', 'example.test'), 'bert.example.test', [], null, enabled: false);

        self::assertFalse($record->status->enabled);
    }

    public function testAForeignAccountCannotBeDisabled(): void
    {
        $this->givenAccount('bert.other.test', 'other.test');

        try {
            $this->updateAccount()->handle(DomainFixture::domainMaster('anna', 'example.test'), 'bert.other.test', [], null, enabled: false);
            self::fail('A foreign account was disabled.');
        } catch (ResourceNotVisible) {
        }

        self::assertTrue($this->accounts->find('bert.other.test')?->status->enabled);
        self::assertSame([], $this->accounts->operations());
    }

    public function testADisableTheDeployedViewIgnoresIsRefusedAndSetBack(): void
    {
        $this->givenAccount('anna.example.test');
        $this->accounts->viewIgnoresStatus();

        $failure = $this->expectFailure(fn () => $this->updateAccount()->handle(DomainFixture::superuser(), 'anna.example.test', [], null, enabled: false));

        self::assertSame(409, $failure->status);
        self::assertSame(AccountErrorCode::STATUS_NOT_ENFORCED, $failure->errorCode);
        self::assertTrue($this->accounts->find('anna.example.test')?->status->enabled);
        self::assertTrue($this->audit->isEmpty());
    }

    public function testADisableThatCannotBeAuditedIsSetBack(): void
    {
        $this->givenAccount('anna.example.test');
        $this->auditWriter = RefusingAuditWriter::everything($this->audit);

        try {
            $this->updateAccount()->handle(DomainFixture::superuser(), 'anna.example.test', [], null, enabled: false);
            self::fail('The change was reported as done.');
        } catch (\Throwable $error) {
            self::assertNotInstanceOf(AccountOperationFailed::class, $error);
        }

        self::assertTrue($this->accounts->find('anna.example.test')?->status->enabled);
    }

    // ------------------------------------------------------------ password

    public function testANewPasswordIsStoredAsBcrypt(): void
    {
        $this->accounts->given('anna.example.test', 'example.test', hash: 'plaintext-secret');

        $record = $this->setPassword()->handle(DomainFixture::superuser(), 'anna.example.test', self::PASSWORD);

        self::assertSame(PasswordFormat::Bcrypt, $record->passwordFormat);
        self::assertTrue(password_verify(self::PASSWORD, (string) $this->accounts->hashOf('anna.example.test')));

        $entry = $this->audit->withAction(SetAccountPassword::OPERATION)[0];
        self::assertSame(['password_format' => 'plaintext'], $entry->before);
        self::assertSame(['password_format' => 'bcrypt'], $entry->after);
    }

    public function testAPasswordContainingTheLocalPartIsRefused(): void
    {
        $this->givenAccount('annabelle.example.test');

        $this->expectException(InvalidPassword::class);

        $this->setPassword()->handle(DomainFixture::superuser(), 'annabelle.example.test', 'annabelle-2026-sommer');
    }

    // --------------------------------------------------------------- quota

    public function testTheQuotaIsReadFromTheMailStore(): void
    {
        $this->givenAccount('anna.example.test', quotaKib: 20000, usedKib: 1234);

        $quota = $this->getQuota()->handle(DomainFixture::superuser(), 'anna.example.test');

        self::assertSame(20000, $quota->limitKibibytes());
        self::assertSame(1234, $quota->usedKibibytes());
    }

    public function testAMissingMailboxIsAConflictNotANotFound(): void
    {
        $this->accounts->given('anna.example.test', 'example.test');

        $failure = $this->expectFailure(fn () => $this->getQuota()->handle(DomainFixture::superuser(), 'anna.example.test'));

        self::assertSame(AccountErrorCode::MAILBOX_MISSING, $failure->errorCode);
        self::assertSame(409, $failure->status);
    }

    public function testLoweringTheQuotaNeedsNoAllowanceCheck(): void
    {
        $this->givenDomain(DomainFixture::domain(domainQuotaKib: 10000));
        $this->givenAccount('anna.example.test', quotaKib: 20000);

        $quota = $this->setQuota()->handle(DomainFixture::superuser(), 'anna.example.test', Quota::ofKibibytes(5000));

        self::assertSame(5000, $quota->limitKibibytes());
        self::assertSame(5000, $this->mailstore->inner()->getQuota($this->identity('anna.example.test'))->limitKibibytes());
        self::assertCount(1, $this->audit->withAction(SetAccountQuota::OPERATION));
    }

    public function testRaisingBeyondTheDomainAllowanceIsRefused(): void
    {
        $this->givenDomain(DomainFixture::domain(domainQuotaKib: 30000));
        $this->givenAccount('anna.example.test', quotaKib: 10000);
        $this->givenAccount('bert.example.test', quotaKib: 20000);

        $failure = $this->expectFailure(fn () => $this->setQuota()->handle(DomainFixture::superuser(), 'anna.example.test', Quota::ofKibibytes(15000)));

        self::assertSame(AccountErrorCode::QUOTA_EXCEEDS_DOMAIN, $failure->errorCode);
        self::assertSame(10000, $this->mailstore->inner()->getQuota($this->identity('anna.example.test'))->limitKibibytes());
    }

    public function testLimitingAnUnlimitedAccountIsCheckedAgainstTheDomainAllowance(): void
    {
        $this->givenDomain(DomainFixture::domain(domainQuotaKib: 10000));
        $this->givenAccount('anna.example.test');

        $failure = $this->expectFailure(fn () => $this->setQuota()->handle(DomainFixture::superuser(), 'anna.example.test', Quota::ofKibibytes(50000)));

        self::assertSame(AccountErrorCode::QUOTA_EXCEEDS_DOMAIN, $failure->errorCode);
        self::assertNull($this->mailstore->inner()->getQuota($this->identity('anna.example.test'))->limitKibibytes());
    }

    public function testLimitingAnUnlimitedAccountWithinTheAllowanceIsAccepted(): void
    {
        $this->givenDomain(DomainFixture::domain(domainQuotaKib: 10000));
        $this->givenAccount('anna.example.test');

        $quota = $this->setQuota()->handle(DomainFixture::superuser(), 'anna.example.test', Quota::ofKibibytes(8000));

        self::assertSame(8000, $quota->limitKibibytes());
    }

    public function testAQuotaChangeThatCannotBeAuditedIsSetBack(): void
    {
        $this->givenDomain(DomainFixture::domain());
        $this->givenAccount('anna.example.test', quotaKib: 10000);
        $this->auditWriter = RefusingAuditWriter::everything($this->audit);

        try {
            $this->setQuota()->handle(DomainFixture::superuser(), 'anna.example.test', Quota::ofKibibytes(5000));
            self::fail('The change was reported as done.');
        } catch (\Throwable $error) {
            self::assertNotInstanceOf(AccountOperationFailed::class, $error);
        }

        self::assertSame(10000, $this->mailstore->inner()->getQuota($this->identity('anna.example.test'))->limitKibibytes());
        self::assertTrue($this->repairs->isEmpty());
    }

    public function testAQuotaThatCannotBeSetBackLeavesARepairEntry(): void
    {
        $this->givenDomain(DomainFixture::domain());
        $this->givenAccount('anna.example.test', quotaKib: 10000);
        $this->auditWriter = RefusingAuditWriter::everything($this->audit);

        // The change goes through; setting it back does not.
        $this->mailstore->failNext('setQuota', after: 1);

        try {
            $this->setQuota()->handle(DomainFixture::superuser(), 'anna.example.test', Quota::ofKibibytes(5000));
            self::fail('The change was reported as done.');
        } catch (\Throwable $error) {
            self::assertNotInstanceOf(AccountOperationFailed::class, $error);
        }

        self::assertSame(['account.quota.set/mailstore.restore_quota'], $this->repairs->steps());
    }

    // -------------------------------------------------------------- delete

    public function testDeletionRemovesTheRowsFirstThenTheMailbox(): void
    {
        $this->givenAccount('anna.example.test');

        $this->deleteAccount()->handle(DomainFixture::superuser(), 'anna.example.test', $this->etagOf('anna.example.test'));

        self::assertFalse($this->accounts->has('anna.example.test'));
        self::assertSame(['delete user/anna.example.test'], $this->mailstore->operations());

        $entry = $this->audit->withAction(DeleteAccount::OPERATION)[0];
        self::assertSame(AuditOutcome::Succeeded, $entry->outcome);
        self::assertSame(1, $entry->before['virtual_rows_removed'] ?? null);
    }

    public function testDeletionRequiresIfMatch(): void
    {
        $this->givenAccount('anna.example.test');

        $failure = $this->expectFailure(fn () => $this->deleteAccount()->handle(DomainFixture::superuser(), 'anna.example.test', null));

        self::assertSame(428, $failure->status);
        self::assertTrue($this->accounts->has('anna.example.test'));
    }

    public function testAMailboxThatCannotBeRemovedAfterTheRowsIsARepairEntry(): void
    {
        $this->givenAccount('anna.example.test');
        $this->mailstore->failNext('deleteMailbox');

        $this->deleteAccount()->handle(DomainFixture::superuser(), 'anna.example.test', $this->etagOf('anna.example.test'));

        self::assertFalse($this->accounts->has('anna.example.test'));
        self::assertSame(['account.delete/mailstore.delete_mailbox'], $this->repairs->steps());
    }

    public function testADeletionWhoseAuditEntryFailsAfterTheRowsAreGoneStillRemovesTheMailbox(): void
    {
        // The in-memory store rolls nothing back, like the MyISAM legacy tables (O-11).
        $this->givenAccount('anna.example.test');
        $this->auditWriter = RefusingAuditWriter::everything($this->audit);

        $this->deleteAccount()->handle(DomainFixture::superuser(), 'anna.example.test', $this->etagOf('anna.example.test'));

        self::assertFalse($this->accounts->has('anna.example.test'));
        self::assertFalse($this->mailstore->inner()->mailboxExists($this->identity('anna.example.test')));
        self::assertSame(['account.delete/audit.record'], $this->repairs->steps());
        self::assertSame('anna.example.test', $this->repairs->entries()[0]->subject);
    }

    public function testADeletionThatLeftTheRowsInPlaceIsRefusedAndTouchesNoMailbox(): void
    {
        $this->givenAccount('anna.example.test');
        $this->accounts->failOnDelete(PersistenceError::transactionMisuse('the test refused the delete'));

        try {
            $this->deleteAccount()->handle(DomainFixture::superuser(), 'anna.example.test', $this->etagOf('anna.example.test'));
            self::fail('The deletion was reported as done.');
        } catch (\Throwable $error) {
            self::assertNotInstanceOf(AccountOperationFailed::class, $error);
        }

        self::assertTrue($this->accounts->has('anna.example.test'));
        self::assertTrue($this->mailstore->inner()->mailboxExists($this->identity('anna.example.test')));
        self::assertTrue($this->repairs->isEmpty());
    }

    // ------------------------------------------- the catchall (U-128)

    public function testACatchallLeftWithoutDestinationsGoesWithTheAccount(): void
    {
        $this->givenAccount('anna.example.test');
        $this->virtual->givenRow('@example.test', 'anna@example.test');

        $this->deleteAccount()->handle(DomainFixture::superuser(), 'anna.example.test', $this->etagOf('anna.example.test'));

        self::assertSame([], $this->virtual->all());

        $parent = $this->audit->withAction(DeleteAccount::OPERATION)[0];
        $child = $this->audit->withAction(DeleteCatchall::OPERATION);
        self::assertCount(1, $child);
        self::assertSame($parent->id, $child[0]->parentId);
        self::assertSame('@example.test', $child[0]->objectId);
        self::assertSame(['anna@example.test'], $child[0]->before['removed_destinations'] ?? null);
    }

    public function testOnlyTheDeadDestinationLeavesACatchallWithOthers(): void
    {
        $this->givenAccount('anna.example.test');
        $this->virtual->givenRow('@example.test', 'anna@example.test, bert@example.test,extern@example.invalid');

        $this->deleteAccount()->handle(DomainFixture::superuser(), 'anna.example.test', $this->etagOf('anna.example.test'));

        $rows = $this->virtual->all();
        self::assertCount(1, $rows);
        self::assertSame('@example.test', $rows[0]->alias);
        self::assertSame('bert@example.test,extern@example.invalid', $rows[0]->dest);

        $child = $this->audit->withAction(SetCatchall::OPERATION);
        self::assertCount(1, $child);
        self::assertSame($this->audit->withAction(DeleteAccount::OPERATION)[0]->id, $child[0]->parentId);
        self::assertSame([], $this->audit->withAction(DeleteCatchall::OPERATION));
    }

    public function testTheStoredUserNameAsDestinationIsDeadToo(): void
    {
        $this->givenAccount('anna.example.test');
        $this->virtual->givenRow('@example.test', 'ANNA.example.test,bert@example.test');

        $this->deleteAccount()->handle(DomainFixture::superuser(), 'anna.example.test', $this->etagOf('anna.example.test'));

        self::assertSame('bert@example.test', $this->virtual->all()[0]->dest);
    }

    public function testACatchallSendingAsTheAccountLosesOnlyThatRight(): void
    {
        $this->givenAccount('anna.example.test');
        $this->virtual->givenRow('@example.test', 'bert@example.test', 'anna.example.test');

        $this->deleteAccount()->handle(DomainFixture::superuser(), 'anna.example.test', $this->etagOf('anna.example.test'));

        $rows = $this->virtual->all();
        self::assertCount(1, $rows);
        self::assertSame('bert@example.test', $rows[0]->dest);
        self::assertSame('', $rows[0]->username);
        self::assertCount(1, $this->audit->withAction(SetCatchall::OPERATION));
    }

    public function testADisabledCatchallIsCleanedAsWell(): void
    {
        $this->givenAccount('anna.example.test');
        $this->virtual->givenRow('@example.test', 'anna@example.test', status: 0);

        $this->deleteAccount()->handle(DomainFixture::superuser(), 'anna.example.test', $this->etagOf('anna.example.test'));

        self::assertSame([], $this->virtual->all());
    }

    public function testACatchallElsewhereIsLeftAlone(): void
    {
        $this->givenAccount('anna.example.test');
        $this->virtual->givenRow('@example.test', 'bert@example.test');
        $this->virtual->givenRow('@other.test', 'anna@example.test');

        $this->deleteAccount()->handle(DomainFixture::superuser(), 'anna.example.test', $this->etagOf('anna.example.test'));

        self::assertCount(2, $this->virtual->all());
        self::assertSame([], $this->virtual->operations());
        self::assertSame([], $this->audit->withAction(SetCatchall::OPERATION));
        self::assertSame([], $this->audit->withAction(DeleteCatchall::OPERATION));
    }

    public function testAnAliasOfAnotherDomainSendingAsTheAccountStaysAsADestination(): void
    {
        // kontakt@other.test names anna as its sender, but the deletion leaves
        // a row of another domain where it is (U-129) — so the address keeps
        // answering, and the catchall pointing at it keeps working.
        $this->givenAccount('anna.example.test');
        $this->virtual->givenRow('kontakt@other.test', 'anna.example.test,office@other.test', 'anna.example.test');
        $this->virtual->givenRow('@example.test', 'kontakt@other.test');

        $this->deleteAccount()->handle(DomainFixture::superuser(), 'anna.example.test', $this->etagOf('anna.example.test'));

        self::assertSame([], $this->audit->withAction(DeleteCatchall::OPERATION));
        self::assertSame([], $this->audit->withAction(SetCatchall::OPERATION));
        self::assertCount(2, $this->virtual->all());
    }

    public function testAKeyNamingAnAccountOfAnotherDomainStaysAsADestination(): void
    {
        $this->givenAccount('anna.example.test');
        $this->virtual->givenAccount('bob.other.test', 'other.test');
        $this->virtual->givenRow('bob.other.test', 'anna.example.test', 'anna.example.test');
        $this->virtual->givenRow('@example.test', 'bob.other.test');

        $this->deleteAccount()->handle(DomainFixture::superuser(), 'anna.example.test', $this->etagOf('anna.example.test'));

        self::assertSame([], $this->audit->withAction(DeleteCatchall::OPERATION));
    }

    public function testAnAliasOfTheOwnDomainSendingAsTheAccountStillGoesWithIt(): void
    {
        $this->givenAccount('anna.example.test');
        $this->virtual->givenRow('kontakt@Example.test', 'anna.example.test', 'anna.example.test');
        $this->virtual->givenRow('@example.test', 'kontakt@example.test');

        $this->deleteAccount()->handle(DomainFixture::superuser(), 'anna.example.test', $this->etagOf('anna.example.test'));

        self::assertCount(1, $this->audit->withAction(DeleteCatchall::OPERATION));
    }

    public function testAnAddressAnotherAccountAnswersForStays(): void
    {
        // A second account carries the address as its stored user name (fqun).
        $this->givenAccount('anna.example.test');
        $this->virtual->givenAccount('anna@example.test', 'example.test');
        $this->virtual->givenRow('@example.test', 'anna@example.test');

        $this->deleteAccount()->handle(DomainFixture::superuser(), 'anna.example.test', $this->etagOf('anna.example.test'));

        self::assertSame('anna@example.test', $this->virtual->all()[0]->dest);
    }

    public function testACatchallIsSetBackWhenTheAccountStays(): void
    {
        $this->givenAccount('anna.example.test');
        $this->virtual->givenRow('@example.test', 'anna@example.test,bert@example.test');
        $this->accounts->failOnDelete(PersistenceError::transactionMisuse('the test refused the delete'));

        try {
            $this->deleteAccount()->handle(DomainFixture::superuser(), 'anna.example.test', $this->etagOf('anna.example.test'));
            self::fail('The deletion was reported as done.');
        } catch (\Throwable $error) {
            self::assertNotInstanceOf(AccountOperationFailed::class, $error);
        }

        $rows = $this->virtual->all();
        self::assertCount(1, $rows);
        self::assertSame('anna@example.test,bert@example.test', $rows[0]->dest);
        self::assertTrue($this->accounts->has('anna.example.test'));
        self::assertTrue($this->audit->isEmpty());
    }

    public function testAForeignAccountCannotBeDeleted(): void
    {
        $this->givenAccount('bert.other.test', 'other.test');

        try {
            $this->deleteAccount()->handle(DomainFixture::domainMaster('anna', 'example.test'), 'bert.other.test', '"x"');
            self::fail('A foreign account was deleted.');
        } catch (ResourceNotVisible) {
        }

        self::assertTrue($this->accounts->has('bert.other.test'));
        self::assertSame([], $this->mailstore->operations());
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
