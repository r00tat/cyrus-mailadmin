<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Mailstore;

use Mailadmin\Application\Address\AccountTarget;
use Mailadmin\Application\Address\AddressErrorCode;
use Mailadmin\Application\Address\AddressOperationFailed;
use Mailadmin\Application\Address\DeleteVacation;
use Mailadmin\Application\Address\GetVacation;
use Mailadmin\Application\Address\SetVacation;
use Mailadmin\Application\Address\VacationDraft;
use Mailadmin\Application\Address\VacationKind;
use Mailadmin\Application\Address\VacationScripts;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Sieve\SieveRenderer;
use Mailadmin\Domain\Sieve\SieveScript;
use Mailadmin\Infrastructure\Mailstore\Credentials;
use Mailadmin\Infrastructure\Mailstore\Cyrus\CyrusMailstore;
use Mailadmin\Infrastructure\Mailstore\Cyrus\CyrusSieveStore;
use Mailadmin\Infrastructure\Mailstore\Imap\ConnectionPool;
use Mailadmin\Infrastructure\Mailstore\Imap\ProtocolTrace;
use Mailadmin\Infrastructure\Mailstore\MailstoreError;
use Mailadmin\Infrastructure\Mailstore\Net\Endpoint;
use Mailadmin\Infrastructure\Mailstore\Net\StreamTransportFactory;
use Mailadmin\Infrastructure\Mailstore\Net\TlsMode;
use Mailadmin\Infrastructure\Mailstore\Sieve\ManageSieveConnectionPool;
use Mailadmin\Infrastructure\Mailstore\Sieve\SieveTrace;
use Mailadmin\Infrastructure\Mailstore\SieveScriptInstaller;
use Mailadmin\Infrastructure\Persistence\InMemoryAccountStore;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Repair\InMemoryRepairQueue;
use Mailadmin\Tests\Integration\TestEnvironment\RequiresTestEnvironment;
use Mailadmin\Tests\Integration\TestEnvironment\TestEnvironment;
use Mailadmin\Tests\Support\DomainFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A3's vacation notice against the F5 timsieved: the use cases with the real
 * Sieve store behind them, and the one rule that matters most — a script
 * someone else wrote is not replaced without an explicit takeover.
 *
 * Works in `leer.test`, like M3's suite, with a local part only this file uses
 * (`a3.`), and removes the mailbox again.
 */
#[CoversClass(SetVacation::class)]
#[CoversClass(GetVacation::class)]
#[CoversClass(DeleteVacation::class)]
#[CoversClass(VacationScripts::class)]
final class VacationIntegrationTest extends TestCase
{
    use RequiresTestEnvironment;

    private const string DOMAIN = 'leer.test';
    private const string ACCOUNT = 'a3.anna.leer.test';
    private const string FOREIGN_NAME = 'a3-foreign';

    private ?ManageSieveConnectionPool $sievePool = null;
    private ?ConnectionPool $imapPool = null;
    private bool $ready = false;
    private InMemoryAccountStore $accounts;
    private InMemoryAuditTrail $audit;

    protected function setUp(): void
    {
        $this->requireTestEnvironment();

        if (filter_var(TestEnvironment::mailHost(), FILTER_VALIDATE_IP) !== false || TestEnvironment::mailHost() === '') {
            self::markTestSkipped('Run inside the environment: `bin/test-env test` (the certificate names the mail host).');
        }

        if (TestEnvironment::caFile() === null) {
            self::markTestSkipped('The test CA is missing. Run `bin/test-env up`, which generates it.');
        }

        $this->ready = true;

        $mailstore = new CyrusMailstore($this->imapPool());

        if (!$mailstore->mailboxExists($this->identity())) {
            $mailstore->createMailbox($this->identity());
        }

        $this->removeScripts();

        $this->accounts = new InMemoryAccountStore();
        $this->accounts->given(self::ACCOUNT, self::DOMAIN);
        $this->audit = new InMemoryAuditTrail();
    }

    protected function tearDown(): void
    {
        if ($this->ready) {
            $this->removeScripts();

            // Sieve sessions first: an open timsieved session makes the IMAP
            // `DELETE` of the same user drop the IMAP connection (U-70 d).
            $this->sievePool?->closeAll();
            $this->sievePool = null;

            try {
                (new CyrusMailstore($this->imapPool()))->deleteMailbox($this->identity());
            } catch (MailstoreError) {
            }
        }

        $this->sievePool?->closeAll();
        $this->imapPool?->closeAll();
        $this->sievePool = null;
        $this->imapPool = null;
        $this->ready = false;
    }

    public function testANoticeIsInstalledReadBackAndWithdrawn(): void
    {
        $state = $this->setVacation()->handle(
            DomainFixture::superuser(),
            self::ACCOUNT,
            new VacationDraft("Bis Montag nicht im B\u{00fc}ro.\n\"Dringendes\" bitte an info@leer.test.", 'Abwesend', 3),
            null,
        );
        self::assertSame(VacationKind::Active, $state->kind);

        // Read back from the server, through a fresh store: the model line is
        // what the notice is read from, not the rendered Sieve.
        $read = $this->getVacation()->handle(DomainFixture::superuser(), self::ACCOUNT);
        self::assertSame(VacationKind::Active, $read->kind);
        $rule = $read->rule();
        self::assertNotNull($rule);
        self::assertSame("Bis Montag nicht im B\u{00fc}ro.\n\"Dringendes\" bitte an info@leer.test.", $rule->message);
        self::assertSame(['a3.anna@leer.test'], $rule->addressStrings());

        $this->deleteVacation()->handle(DomainFixture::superuser(), self::ACCOUNT, false, null);

        self::assertSame(VacationKind::None, $this->getVacation()->handle(DomainFixture::superuser(), self::ACCOUNT)->kind);
        self::assertSame([], $this->store()->scriptNames($this->identity()));
        self::assertCount(2, $this->audit->all());
    }

    public function testAForeignScriptIsRefusedAndOnlyATakeoverReplacesIt(): void
    {
        $store = $this->store();
        $store->putScript($this->identity(), SieveScript::of(self::FOREIGN_NAME, "require \"fileinto\";\r\nkeep;\r\n"));
        $store->activate($this->identity(), self::FOREIGN_NAME);

        self::assertSame(VacationKind::Foreign, $this->getVacation()->handle(DomainFixture::superuser(), self::ACCOUNT)->kind);

        try {
            $this->setVacation()->handle(DomainFixture::superuser(), self::ACCOUNT, new VacationDraft('Weg.'), null);
            self::fail('A foreign script must not be replaced without a takeover.');
        } catch (AddressOperationFailed $failure) {
            self::assertSame(AddressErrorCode::VACATION_FOREIGN_SCRIPT, $failure->errorCode);
        }

        self::assertSame(self::FOREIGN_NAME, $this->store()->activeScript($this->identity())?->name);
        self::assertTrue($this->audit->isEmpty());

        $this->setVacation()->handle(DomainFixture::superuser(), self::ACCOUNT, new VacationDraft('Weg.', takeOver: true), null);

        $active = $this->store()->activeScript($this->identity());
        self::assertNotNull($active);
        self::assertSame(SieveScript::LEGACY_NAME, $active->name);
        self::assertTrue($active->isManaged());
        self::assertContains(self::FOREIGN_NAME, $this->store()->scriptNames($this->identity()), 'the foreign script stays stored');
    }

    public function testRemovingAForeignScriptDeactivatesItWithoutDeletingIt(): void
    {
        $store = $this->store();
        $store->putScript($this->identity(), SieveScript::of(self::FOREIGN_NAME, "keep;\r\n"));
        $store->activate($this->identity(), self::FOREIGN_NAME);

        try {
            $this->deleteVacation()->handle(DomainFixture::superuser(), self::ACCOUNT, false, null);
            self::fail('A foreign script must not be withdrawn without a takeover.');
        } catch (AddressOperationFailed $failure) {
            self::assertSame(AddressErrorCode::VACATION_FOREIGN_SCRIPT, $failure->errorCode);
        }

        $this->deleteVacation()->handle(DomainFixture::superuser(), self::ACCOUNT, true, null);

        self::assertNull($this->store()->activeScript($this->identity()));
        self::assertSame([self::FOREIGN_NAME], $this->store()->scriptNames($this->identity()));
    }

    // -----------------------------------------------------------------

    private function scripts(): VacationScripts
    {
        $store = $this->store();

        return new VacationScripts(
            $store,
            new SieveScriptInstaller($store, new SieveRenderer(), $store),
            $this->audit,
            new InMemoryRepairQueue(),
            new NullLogger(),
        );
    }

    private function setVacation(): SetVacation
    {
        return new SetVacation(new AccountTarget($this->accounts), $this->scripts());
    }

    private function getVacation(): GetVacation
    {
        return new GetVacation(new AccountTarget($this->accounts), $this->scripts());
    }

    private function deleteVacation(): DeleteVacation
    {
        return new DeleteVacation(new AccountTarget($this->accounts), $this->scripts());
    }

    private function identity(): MailboxIdentity
    {
        return MailboxIdentity::fromStoredUsername(self::ACCOUNT, DomainName::fromStoredValue(self::DOMAIN));
    }

    private function store(): CyrusSieveStore
    {
        return new CyrusSieveStore($this->sievePool());
    }

    private function removeScripts(): void
    {
        $store = $this->store();

        try {
            $store->deactivate($this->identity());

            foreach ([SieveScript::LEGACY_NAME, self::FOREIGN_NAME] as $name) {
                $store->deleteScript($this->identity(), $name);
            }
        } catch (MailstoreError) {
        }
    }

    private function sievePool(): ManageSieveConnectionPool
    {
        return $this->sievePool ??= new ManageSieveConnectionPool(
            Endpoint::of(
                host: TestEnvironment::mailHost(),
                port: TestEnvironment::manageSievePort(),
                tlsMode: TlsMode::StartTls,
                caFile: TestEnvironment::caFile(),
            ),
            $this->credentials(),
            new StreamTransportFactory(),
            SieveTrace::disabled(),
        );
    }

    private function imapPool(): ConnectionPool
    {
        return $this->imapPool ??= new ConnectionPool(
            Endpoint::of(
                host: TestEnvironment::mailHost(),
                port: TestEnvironment::imapsPort(),
                caFile: TestEnvironment::caFile(),
            ),
            $this->credentials(),
            new StreamTransportFactory(),
            ProtocolTrace::disabled(),
        );
    }

    private function credentials(): Credentials
    {
        return Credentials::of(TestEnvironment::adminUser(), TestEnvironment::adminPassword());
    }
}
