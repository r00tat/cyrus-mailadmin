<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Account;

use Mailadmin\Application\Account\AccountDraft;
use Mailadmin\Application\Account\AccountLocator;
use Mailadmin\Application\Account\AccountRecord;
use Mailadmin\Application\Account\AccountView;
use Mailadmin\Application\Account\CreateAccount;
use Mailadmin\Application\Account\DeleteAccount;
use Mailadmin\Application\Account\DomainAllowance;
use Mailadmin\Application\Account\GetAccount;
use Mailadmin\Application\Account\GetAccountQuota;
use Mailadmin\Application\Account\ListAccounts;
use Mailadmin\Application\Account\SetAccountPassword;
use Mailadmin\Application\Account\SetAccountQuota;
use Mailadmin\Application\Account\UpdateAccount;
use Mailadmin\Application\Address\CatchallCleanup;
use Mailadmin\Application\Address\RowWriter;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Domain\DomainDefaults;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\AccountServices;
use Mailadmin\Domain\Model\Domain;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Domain\Password\InMemoryCommonPasswordList;
use Mailadmin\Domain\Password\PasswordPolicy;
use Mailadmin\Infrastructure\Mailstore\InMemoryMailstore;
use Mailadmin\Infrastructure\Persistence\InMemoryAccountStore;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\InMemoryDomainStore;
use Mailadmin\Infrastructure\Persistence\InMemoryVirtualStore;
use Mailadmin\Infrastructure\Repair\InMemoryRepairQueue;
use Mailadmin\Infrastructure\Security\PasswordHasher;
use Mailadmin\Infrastructure\Security\PasswordHashingOptions;
use Mailadmin\Tests\Support\ListDeliveryFixture;
use Mailadmin\Tests\Support\Mailstore\UnreliableMailstore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The wiring every A2 use-case test needs: in-memory `accountuser`/`virtual`
 * and `domain` tables, a mail store that can be told to fail, a repair queue
 * and an audit trail that can be read back.
 *
 * Nothing here touches MariaDB or Cyrus — the interesting half of this package
 * is what happens when the second of two systems refuses (CLAUDE.md, Tests).
 */
abstract class AccountUseCaseTestCase extends TestCase
{
    protected const string PASSWORD = 'Korrekt-Pferd-Batterie-42';

    protected InMemoryAccountStore $accounts;
    protected InMemoryDomainStore $domains;

    /** The catchall rows `DeleteAccount` looks at (U-128); account rows live in `$accounts`. */
    protected InMemoryVirtualStore $virtual;
    protected UnreliableMailstore $mailstore;
    protected InMemoryRepairQueue $repairs;
    protected InMemoryAuditTrail $audit;
    protected DomainDefaults $defaults;

    /** What the use cases write to; the trail itself unless a test swaps it. */
    protected AuditWriter $auditWriter;

    private static ?PasswordHasher $hasher = null;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountStore();
        $this->domains = new InMemoryDomainStore();
        $this->virtual = new InMemoryVirtualStore();
        $this->mailstore = new UnreliableMailstore(new InMemoryMailstore());
        $this->repairs = new InMemoryRepairQueue();
        $this->audit = new InMemoryAuditTrail();
        $this->auditWriter = $this->audit;
        $this->defaults = new DomainDefaults(NamingScheme::Dotted);
    }

    protected static function hasher(): PasswordHasher
    {
        // Cost 4: the tests are about which format is written, not how slowly.
        return self::$hasher ??= new PasswordHasher(PasswordHashingOptions::of(bcryptCost: 4));
    }

    protected function locator(): AccountLocator
    {
        return new AccountLocator($this->accounts);
    }

    protected function allowance(): DomainAllowance
    {
        return new DomainAllowance($this->accounts, $this->mailstore, new NullLogger());
    }

    protected function createAccount(): CreateAccount
    {
        return new CreateAccount(
            $this->accounts,
            $this->domains,
            $this->defaults,
            $this->mailstore,
            $this->allowance(),
            new PasswordPolicy(new InMemoryCommonPasswordList()),
            self::hasher(),
            $this->repairs,
            $this->auditWriter,
            new NullLogger(),
        );
    }

    protected function listAccounts(): ListAccounts
    {
        return new ListAccounts($this->accounts, $this->domains);
    }

    protected function getAccount(): GetAccount
    {
        return new GetAccount($this->locator());
    }

    protected function updateAccount(): UpdateAccount
    {
        return new UpdateAccount($this->accounts, $this->locator(), $this->auditWriter, new NullLogger());
    }

    protected function setPassword(): SetAccountPassword
    {
        return new SetAccountPassword(
            $this->accounts,
            $this->locator(),
            new PasswordPolicy(new InMemoryCommonPasswordList()),
            self::hasher(),
            $this->auditWriter,
        );
    }

    protected function getQuota(): GetAccountQuota
    {
        return new GetAccountQuota($this->locator(), $this->mailstore);
    }

    protected function setQuota(): SetAccountQuota
    {
        return new SetAccountQuota(
            $this->locator(),
            $this->domains,
            $this->mailstore,
            $this->allowance(),
            $this->repairs,
            $this->auditWriter,
            new NullLogger(),
        );
    }

    protected function deleteAccount(): DeleteAccount
    {
        return new DeleteAccount(
            $this->accounts,
            $this->locator(),
            $this->mailstore,
            $this->repairs,
            $this->auditWriter,
            new NullLogger(),
            $this->domains,
            $this->virtual,
            new CatchallCleanup($this->virtual, ListDeliveryFixture::reader($this->virtual)),
            new RowWriter($this->virtual, $this->auditWriter, $this->repairs, new NullLogger()),
        );
    }

    protected function draft(string $localPart = 'anna', ?Quota $quota = null): AccountDraft
    {
        return new AccountDraft(LocalPart::fromString($localPart), self::PASSWORD, $quota, AccountServices::all());
    }

    protected function givenDomain(Domain $domain): Domain
    {
        $this->domains->given($domain);

        return $domain;
    }

    /**
     * An existing account: its rows, and its mailbox in the store.
     */
    protected function givenAccount(
        string $storedUsername,
        string $domain = 'example.test',
        ?int $quotaKib = null,
        ?int $usedKib = null,
    ): AccountRecord {
        $record = $this->accounts->given($storedUsername, $domain);
        $identity = $record->requireIdentity();

        $this->mailstore->inner()->given($identity, $usedKib);

        if ($quotaKib !== null) {
            $this->mailstore->inner()->setQuota($identity, Quota::ofKibibytes($quotaKib));
        }

        $this->mailstore->inner()->forget();

        return $record;
    }

    protected function identity(string $storedUsername, string $domain = 'example.test'): MailboxIdentity
    {
        return MailboxIdentity::fromStoredUsername($storedUsername, DomainName::fromStoredValue($domain));
    }

    protected function etagOf(string $storedUsername): string
    {
        $record = $this->accounts->find($storedUsername);

        self::assertNotNull($record);

        return AccountView::etag($record);
    }
}
