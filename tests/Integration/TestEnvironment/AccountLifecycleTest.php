<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\TestEnvironment;

use Mailadmin\Application\Account\AccountDraft;
use Mailadmin\Application\Account\AccountLocator;
use Mailadmin\Application\Account\AccountView;
use Mailadmin\Application\Account\CreateAccount;
use Mailadmin\Application\Account\DeleteAccount;
use Mailadmin\Application\Account\DomainAllowance;
use Mailadmin\Application\Account\GetAccountQuota;
use Mailadmin\Application\Account\SetAccountPassword;
use Mailadmin\Application\Account\UpdateAccount;
use Mailadmin\Application\Address\CatchallCleanup;
use Mailadmin\Application\Address\RowWriter;
use Mailadmin\Application\Domain\DomainDefaults;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\AccountServices;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Domain\Password\InMemoryCommonPasswordList;
use Mailadmin\Domain\Password\PasswordPolicy;
use Mailadmin\Infrastructure\Mailstore\Credentials;
use Mailadmin\Infrastructure\Mailstore\Cyrus\CyrusMailstore;
use Mailadmin\Infrastructure\Mailstore\Imap\ConnectionPool;
use Mailadmin\Infrastructure\Mailstore\Imap\ProtocolTrace;
use Mailadmin\Infrastructure\Mailstore\MailstoreError;
use Mailadmin\Infrastructure\Mailstore\Net\Endpoint;
use Mailadmin\Infrastructure\Mailstore\Net\StreamTransportFactory;
use Mailadmin\Infrastructure\Persistence\AccountRepository;
use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\DomainRepository;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\OpenConnection;
use Mailadmin\Infrastructure\Persistence\SqlMailingListRepository;
use Mailadmin\Infrastructure\Persistence\VirtualRepository;
use Mailadmin\Infrastructure\Repair\InMemoryRepairQueue;
use Mailadmin\Infrastructure\Security\PasswordHasher;
use Mailadmin\Infrastructure\Security\PasswordHashingOptions;
use Mailadmin\Tests\Support\DomainFixture;
use Mailadmin\Tests\Support\FrozenClock;
use Mailadmin\Tests\Support\ListDeliveryFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A2's Definition of Done, end to end against F5: an account created through
 * the use case — real `accountuser`/`virtual` rows, a real Cyrus mailbox — can
 * log in over IMAP and SMTP-AUTH (`saslauthd` → `pam_mysql` → `crypt(3)`), can
 * be given a new password that works, can be disabled — no IMAP, no SMTP-AUTH,
 * mail still accepted — and enabled again (U-127), and is gone again after
 * deletion.
 *
 * Runs in `leer.test`, which holds no fixture accounts, in both naming schemes
 * (the domain is in `loginrealms`). Everything it creates is removed by name.
 */
#[CoversClass(CreateAccount::class)]
#[CoversClass(SetAccountPassword::class)]
#[CoversClass(DeleteAccount::class)]
#[CoversClass(UpdateAccount::class)]
#[CoversClass(AccountRepository::class)]
final class AccountLifecycleTest extends TestCase
{
    use RequiresTestEnvironment;

    private const string DOMAIN = 'leer.test';
    private const string LOCAL = 'a2probe';
    private const string FIRST_PASSWORD = 'Erstes-Passwort-2026!';
    private const string SECOND_PASSWORD = 'Zweites-Passwort-2026!';

    private ?ConnectionPool $pool = null;
    private ?Connection $connection = null;
    private bool $ready = false;

    protected function setUp(): void
    {
        $this->requireFixtures();

        if (filter_var(TestEnvironment::mailHost(), FILTER_VALIDATE_IP) !== false || TestEnvironment::caFile() === null) {
            self::markTestSkipped('Certificate verification needs the environment’s host name and CA: run `bin/test-env test`.');
        }

        $this->ready = true;
        $this->removeEverything();
    }

    protected function tearDown(): void
    {
        if ($this->ready) {
            $this->removeEverything();
        }

        $this->pool?->closeAll();
        $this->pool = null;
        $this->ready = false;
    }

    /**
     * @return iterable<string, array{NamingScheme}>
     */
    public static function schemes(): iterable
    {
        yield 'dotted' => [NamingScheme::Dotted];
        yield 'fqun' => [NamingScheme::Fqun];
    }

    #[DataProvider('schemes')]
    public function testAnAccountCreatedHereCanLogInAndIsGoneAfterDeletion(NamingScheme $scheme): void
    {
        $actor = DomainFixture::superuser();
        $accounts = new AccountRepository($this->connection());
        $mailstore = new CyrusMailstore($this->pool());
        $locator = new AccountLocator($accounts);
        $hasher = new PasswordHasher(PasswordHashingOptions::of(bcryptCost: 4));
        $policy = new PasswordPolicy(new InMemoryCommonPasswordList());
        $audit = new InMemoryAuditTrail();
        $repairs = new InMemoryRepairQueue();

        $create = new CreateAccount(
            $accounts,
            new DomainRepository($this->connection(), new FrozenClock()),
            new DomainDefaults($scheme),
            $mailstore,
            new DomainAllowance($accounts, $mailstore, new NullLogger()),
            $policy,
            $hasher,
            $repairs,
            $audit,
            new NullLogger(),
        );

        $created = $create->handle(
            $actor,
            DomainName::fromStoredValue(self::DOMAIN),
            new AccountDraft(LocalPart::fromString(self::LOCAL), self::FIRST_PASSWORD, Quota::ofKibibytes(10000), AccountServices::all()),
        );

        $identity = self::identity($scheme);
        $username = $identity->cyrusUsername();

        self::assertSame($username, $created->account->storedUsername);
        self::assertSame([], $created->incompleteSteps);
        self::assertTrue($mailstore->mailboxExists($identity));
        self::assertTrue($repairs->isEmpty());

        $quota = (new GetAccountQuota($locator, $mailstore))->handle($actor, $username);
        self::assertSame(10000, $quota->limitKibibytes());

        self::assertTrue(self::imapLogin($username, self::FIRST_PASSWORD), 'IMAP refused the password set at creation.');
        self::assertSame(235, self::smtpLogin($username, self::FIRST_PASSWORD), 'SMTP-AUTH refused the password set at creation.');

        (new SetAccountPassword($accounts, $locator, $policy, $hasher, $audit))->handle($actor, $username, self::SECOND_PASSWORD);

        self::assertTrue(self::imapLogin($username, self::SECOND_PASSWORD), 'IMAP refused the new password.');
        self::assertFalse(self::imapLogin($username, self::FIRST_PASSWORD), 'IMAP still accepts the old password.');
        self::assertSame(235, self::smtpLogin($username, self::SECOND_PASSWORD), 'SMTP-AUTH refused the new password.');

        $address = $identity->address()->toString();
        $update = new UpdateAccount($accounts, $locator, $audit, new NullLogger());

        $disabled = $update->handle($actor, $username, [], null, enabled: false);
        self::assertFalse($disabled->status->enabled);

        self::assertFalse(self::imapLogin($username, self::SECOND_PASSWORD), 'IMAP accepts a disabled account.');
        self::assertFalse(self::imapLogin($address, self::SECOND_PASSWORD), 'IMAP accepts a disabled account by its address.');
        self::assertNotSame(235, self::smtpLogin($username, self::SECOND_PASSWORD), 'SMTP-AUTH accepts a disabled account.');
        self::assertNotSame(235, self::smtpLogin($address, self::SECOND_PASSWORD), 'SMTP-AUTH accepts a disabled account by its address.');
        self::assertTrue(
            $this->postfixAccepts($address),
            'Postfix refused mail to a disabled account; U-127 says delivery continues.',
        );

        $enabled = $update->handle($actor, $username, [], null, enabled: true);
        self::assertTrue($enabled->status->enabled);

        self::assertTrue(self::imapLogin($username, self::SECOND_PASSWORD), 'IMAP refuses an account enabled again.');
        self::assertSame(235, self::smtpLogin($username, self::SECOND_PASSWORD), 'SMTP-AUTH refuses an account enabled again.');

        $record = $accounts->find($username);
        self::assertNotNull($record);

        $virtual = new VirtualRepository($this->connection());

        (new DeleteAccount(
            $accounts,
            $locator,
            $mailstore,
            $repairs,
            $audit,
            new NullLogger(),
            new DomainRepository($this->connection(), new FrozenClock()),
            $virtual,
            new CatchallCleanup($virtual, ListDeliveryFixture::reader($virtual, new SqlMailingListRepository($this->connection()))),
            new RowWriter($virtual, $audit, $repairs, new NullLogger()),
        ))->handle($actor, $username, AccountView::etag($record));

        self::assertNull($accounts->find($username));
        self::assertFalse($mailstore->mailboxExists($identity));
        self::assertTrue($repairs->isEmpty());
        self::assertSame(
            0,
            (int) $this->connection()->selectValue('SELECT COUNT(*) FROM virtual WHERE username = :u', ['u' => $username]),
        );
        self::assertFalse(self::imapLogin($username, self::SECOND_PASSWORD), 'A deleted account can still log in.');
        self::assertSame(
            0,
            (int) $this->connection()->selectValue(
                'SELECT COUNT(*) FROM mailadmin_account_status WHERE username = :u',
                ['u' => $username],
            ),
            'The status row outlived the account.',
        );
    }

    /**
     * Submits one message to `$address`, authenticated as the administrator
     * (an account of the environment), and reports whether Postfix took it.
     *
     * Acceptance is what U-127 changes nothing about: Postfix decides from
     * `virtual`, not from `allusers`. The final hop into the mailbox is not
     * checked here — in F5, Postfix cannot open Cyrus's LMTP socket (the
     * message stays deferred), which has nothing to do with the account.
     */
    private function postfixAccepts(string $address): bool
    {
        $smtp = SmtpProbe::connect();

        try {
            $smtp->ehlo();
            $smtp->startTls();

            if ($smtp->authPlain(TestEnvironment::adminUser(), TestEnvironment::adminPassword()) !== 235) {
                self::markTestIncomplete('The administrator cannot submit mail in this environment.');
            }

            return $smtp->command('MAIL FROM:<>')[0] === 250
                && $smtp->command(sprintf('RCPT TO:<%s>', $address))[0] === 250
                && $smtp->command('DATA')[0] === 354
                && $smtp->command(implode("\r\n", [
                    sprintf('To: <%s>', $address),
                    'Subject: U-127 delivery probe',
                    '',
                    'Accepted although the account is disabled.',
                    '.',
                ]))[0] === 250;
        } finally {
            $smtp->close();
        }
    }

    private static function identity(NamingScheme $scheme): MailboxIdentity
    {
        return MailboxIdentity::create(LocalPart::fromString(self::LOCAL), DomainName::fromStoredValue(self::DOMAIN), $scheme);
    }

    private static function imapLogin(string $username, string $password): bool
    {
        $imap = ImapProbe::connect();

        try {
            return $imap->authenticatePlain($username, $password);
        } finally {
            $imap->close();
        }
    }

    private static function smtpLogin(string $username, string $password): int
    {
        $smtp = SmtpProbe::connect();

        try {
            $smtp->ehlo();
            $smtp->startTls();

            return $smtp->authPlain($username, $password);
        } finally {
            $smtp->close();
        }
    }

    private function connection(): Connection
    {
        return $this->connection ??= new Connection(new OpenConnection(TestEnvironment::connection()));
    }

    private function pool(): ConnectionPool
    {
        $caFile = TestEnvironment::caFile();
        self::assertNotNull($caFile);

        return $this->pool ??= new ConnectionPool(
            Endpoint::of(host: TestEnvironment::mailHost(), port: TestEnvironment::imapsPort(), caFile: $caFile),
            Credentials::of(TestEnvironment::adminUser(), TestEnvironment::adminPassword()),
            new StreamTransportFactory(),
            ProtocolTrace::disabled(),
        );
    }

    /**
     * Exactly the names this test can produce, in both tables and in Cyrus.
     */
    private function removeEverything(): void
    {
        $store = new CyrusMailstore($this->pool());

        foreach ([NamingScheme::Dotted, NamingScheme::Fqun] as $scheme) {
            $identity = self::identity($scheme);

            $this->connection()->execute('DELETE FROM virtual WHERE username = :u', ['u' => $identity->cyrusUsername()]);
            $this->connection()->execute('DELETE FROM accountuser WHERE username = :u', ['u' => $identity->cyrusUsername()]);
            $this->connection()->execute('DELETE FROM mailadmin_account_status WHERE username = :u', ['u' => $identity->cyrusUsername()]);

            try {
                $store->deleteMailbox($identity);
            } catch (MailstoreError) {
                // Nothing there.
            }
        }
    }
}
