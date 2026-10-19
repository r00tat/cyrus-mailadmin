<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Persistence;

use Mailadmin\Application\Account\AccountLocator;
use Mailadmin\Application\Account\AccountView;
use Mailadmin\Application\Account\DeleteAccount;
use Mailadmin\Application\Address\AddressReader;
use Mailadmin\Application\Address\CatchallCleanup;
use Mailadmin\Application\Address\DeleteCatchall;
use Mailadmin\Application\Address\RowWriter;
use Mailadmin\Application\Address\SetCatchall;
use Mailadmin\Domain\Model\AccountServices;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Model\Virtual\VirtualEntryClassifier;
use Mailadmin\Domain\Password\PasswordFormatDetector;
use Mailadmin\Infrastructure\Mailstore\InMemoryMailstore;
use Mailadmin\Infrastructure\Persistence\AccountRepository;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\DomainRepository;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationLoader;
use Mailadmin\Infrastructure\Persistence\VirtualRepository;
use Mailadmin\Infrastructure\Repair\InMemoryRepairQueue;
use Mailadmin\Tests\Support\DomainFixture;
use Mailadmin\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

/**
 * `AccountRepository` against a real MariaDB with the legacy schema.
 *
 * What a double cannot be wrong about: the statements, the password-format
 * recognition in SQL (which must agree with the PHP detector rule for rule),
 * the `virtual` rows a creation writes and a deletion removes, the status row
 * of U-127, the catchall a deletion cleans up (U-128), and the per-domain lock.
 *
 * Skips without a database. It never passes without one.
 */
#[CoversClass(AccountRepository::class)]
#[CoversClass(DeleteAccount::class)]
#[CoversClass(CatchallCleanup::class)]
final class AccountRepositoryIntegrationTest extends DatabaseTestCase
{
    private AccountRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLegacyBaseline();
        $this->applyOwnMigrations();

        $this->repository = new AccountRepository($this->connection);
    }

    public function testTheSqlRecognisesEveryFormatTheWayPhpDoes(): void
    {
        $samples = [
            'argon' => '$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHQ$aGFzaGhhc2hoYXNo',
            'bcrypt' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
            'bcrypta' => '$2a$10$abcdefghijklmnopqrstuuAbCdEfGhIjKlMnOpQrStUvWxYz01234',
            'md5' => '$1$saltsalt$abcdefghijklmnopqrstuv',
            'sha256' => '$5$saltsalt$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG',
            'sha512' => '$6$saltsalt$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG',
            'mysql' => '*' . strtoupper(sha1('x')),
            'mysqllower' => '*' . sha1('x'),
            'sha1' => sha1('x'),
            'des' => 'abJnggxhB/yWI',
            'unknown' => '{SSHA}abcdef',
            'unknown2' => '$7$something',
            'plain' => 'geheim',
            'empty' => '',
        ];

        foreach ($samples as $local => $hash) {
            $this->connection->execute(
                'INSERT INTO accountuser (username, password, prefix, domain_name) VALUES (:u, :p, "", "example.test")',
                ['u' => $local . '.example.test', 'p' => $hash],
            );
        }

        foreach ($samples as $local => $hash) {
            $record = $this->repository->find($local . '.example.test');

            self::assertNotNull($record);
            self::assertSame(
                PasswordFormatDetector::detect($hash),
                $record->passwordFormat,
                sprintf('SQL and PHP disagree about the "%s" sample.', $local),
            );
        }
    }

    public function testAnExistingRowIsReadWithoutItsHash(): void
    {
        $record = $this->repository->find('info.example.test');

        self::assertNotNull($record);
        self::assertTrue($record->isResolvable());
        self::assertSame('info@example.test', $record->identity?->address()->toString());
        self::assertSame('exam', $record->legacyPrefix);
        self::assertTrue($record->services->imap);
    }

    public function testARowWhoseDomainIsNotADomainNameIsNotFound(): void
    {
        // The Cyrus admin row of a real installation has no domain.
        $this->connection->execute('INSERT INTO accountuser (username, password, domain_name) VALUES ("cyrus", "x", "")');

        self::assertNull($this->repository->find('cyrus'));
    }

    public function testCreationWritesTheAccountAndItsAddressRow(): void
    {
        $identity = $this->identity('anna', NamingScheme::Fqun);
        $hash = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);

        $this->repository->create($identity, $hash, new AccountServices(true, false, true, true), 'exam');

        $row = $this->connection->selectOne('SELECT * FROM accountuser WHERE username = "anna@example.test"');
        self::assertNotNull($row);
        self::assertSame($hash, $row['password']);
        self::assertSame('example.test', $row['domain_name']);
        self::assertSame('exam', $row['prefix']);
        self::assertSame(0, (int) $row['pop']);

        $virtual = $this->connection->select('SELECT alias, dest, username, status FROM virtual WHERE username = "anna@example.test"');
        self::assertSame(
            [['alias' => 'anna@example.test', 'dest' => 'anna@example.test', 'username' => 'anna@example.test', 'status' => 1]],
            array_map(static fn (array $r): array => [...$r, 'status' => (int) $r['status']], $virtual),
        );

        $record = $this->repository->find('anna@example.test');
        self::assertNotNull($record);
        self::assertSame(PasswordFormat::Bcrypt, $record->passwordFormat);
    }

    public function testATakenNameIsSeenInEitherTable(): void
    {
        self::assertTrue($this->repository->isTaken($this->identity('info')));
        self::assertTrue($this->repository->isTaken($this->identity('INFO')));
        self::assertFalse($this->repository->isTaken($this->identity('anna')));

        $this->connection->execute('INSERT INTO virtual (alias, dest, username) VALUES ("anna@example.test", "elsewhere@example.org", "")');

        self::assertTrue($this->repository->isTaken($this->identity('anna')));
    }

    public function testAUserNameAnAliasNamesAsItsSenderIsTaken(): void
    {
        // Review finding on A3: `validsenders` joins `virtual.username` against
        // `accountuser`. A new account under a name some row already carries
        // there would gain that row's sending right — the alias placeholder
        // (U-106) or a leftover of an earlier account.
        self::assertFalse($this->repository->isTaken($this->identity('anna')));

        $this->connection->execute('INSERT INTO virtual (alias, dest, username) VALUES ("team@example.test", "someone@example.org", "Anna.example.test")');

        self::assertTrue($this->repository->isTaken($this->identity('anna')));
    }

    public function testAUserNameIsTakenWhateverItsSpellingWithoutAnAddressRow(): void
    {
        // Review finding on the security follow-up: `username` is `latin1_bin`.
        // LOWER() on both sides still compares without case — a VARCHAR with a
        // binary collation is not a binary string. No `virtual` row, so only
        // the `accountuser` half can answer; the address `info@kunde.example.test`
        // differs from `Info.Kunde@example.test`, the Cyrus name does not.
        $this->connection->execute(
            'INSERT INTO accountuser (username, password, prefix, domain_name) '
            . 'VALUES ("Info.Kunde.example.test", "x", "exam", "example.test")',
        );

        self::assertTrue($this->repository->isTaken(MailboxIdentity::create(
            LocalPart::fromString('info'),
            DomainName::fromStoredValue('kunde.example.test'),
            NamingScheme::Dotted,
        )));
    }

    public function testDeletionRemovesTheAccountRowsAndLeavesOtherAliasesAlone(): void
    {
        // An alias that delivers to the account, and one that does not.
        $this->connection->execute('INSERT INTO virtual (alias, dest, username) VALUES ("kontakt@example.test", "info.example.test", "info.example.test")');
        $this->connection->execute('INSERT INTO virtual (alias, dest, username) VALUES ("team@example.test", "someone@example.org", "")');

        $removed = $this->repository->delete('info.example.test', DomainName::fromStoredValue('example.test'));

        self::assertSame(2, $removed);
        self::assertNull($this->repository->find('info.example.test'));
        self::assertSame(
            ['team@example.test'],
            array_column($this->connection->select('SELECT alias FROM virtual ORDER BY alias'), 'alias'),
        );
    }

    public function testTheListingIsScopedSortedAndFiltered(): void
    {
        $this->connection->execute('INSERT INTO accountuser (username, password, domain_name) VALUES ("anna.example.test", "geheim", "example.test")');
        $this->connection->execute('INSERT INTO accountuser (username, password, domain_name) VALUES ("x.other.test", "geheim", "other.test")');

        $all = $this->repository->list(DomainName::fromStoredValue('example.test'), ListCriteria::fromQuery([]));
        self::assertSame(['anna.example.test', 'info.example.test'], array_map(static fn ($r): string => $r->storedUsername, $all->items));
        self::assertSame(2, $all->total);

        $plain = $this->repository->list(
            DomainName::fromStoredValue('example.test'),
            ListCriteria::fromQuery(['password_format' => 'plaintext']),
        );
        self::assertSame(['anna.example.test'], array_map(static fn ($r): string => $r->storedUsername, $plain->items));

        $byName = $this->repository->list(DomainName::fromStoredValue('example.test'), ListCriteria::fromQuery(['name' => 'inf']));
        self::assertSame(['info.example.test'], array_map(static fn ($r): string => $r->storedUsername, $byName->items));

        self::assertSame(2, $this->repository->countIn(DomainName::fromStoredValue('example.test')));
    }

    public function testTheDomainLockIsHeldForTheWorkAndReleasedAfterwards(): void
    {
        $domain = DomainName::fromStoredValue('example.test');
        $name = 'mailadmin.account.' . sha1('example.test');

        $held = $this->repository->withDomainLock($domain, fn (): mixed => $this->connection->selectValue('SELECT IS_USED_LOCK(:n) IS NOT NULL', ['n' => $name]));

        self::assertSame(1, (int) $held);
        self::assertNull($this->connection->selectValue('SELECT IS_USED_LOCK(:n)', ['n' => $name]));
    }

    public function testUpdatingFlagsAndHashTouchesOnlyThatRow(): void
    {
        $this->repository->updateServices('info.example.test', AccountServices::none());
        $this->repository->updatePasswordHash('info.example.test', 'neues-klartext');

        $record = $this->repository->find('info.example.test');
        self::assertNotNull($record);
        self::assertFalse($record->services->imap);
        self::assertSame(PasswordFormat::Plaintext, $record->passwordFormat);
    }

    // ------------------------------------------------- status (U-127)

    public function testAnAccountWithoutAStatusRowIsEnabled(): void
    {
        $record = $this->repository->find('info.example.test');

        self::assertNotNull($record);
        self::assertTrue($record->status->enabled);
        self::assertSame(0, $this->statusRows());
    }

    public function testDisablingAndEnablingAreReadBack(): void
    {
        $this->repository->setStatus('info.example.test', false, 'anna');

        $disabled = $this->repository->find('info.example.test');
        self::assertNotNull($disabled);
        self::assertFalse($disabled->status->enabled);
        self::assertSame('anna', $disabled->status->disabledBy);
        self::assertNotNull($disabled->status->disabledAt);
        self::assertSame('UTC', $disabled->status->disabledAt->getTimezone()->getName());

        $listed = $this->repository->list(DomainName::fromStoredValue('example.test'), ListCriteria::fromQuery([]));
        self::assertFalse($listed->items[0]->status->enabled);

        $this->repository->setStatus('info.example.test', true, 'bert');

        $enabled = $this->repository->find('info.example.test');
        self::assertNotNull($enabled);
        self::assertTrue($enabled->status->enabled);
        self::assertNull($enabled->status->disabledAt);
        self::assertSame('', $enabled->status->disabledBy);
        self::assertSame('bert', (string) $this->connection->selectValue('SELECT updated_by FROM mailadmin_account_status'));

        // The legacy row is untouched either way.
        self::assertSame(1, (int) $this->connection->selectValue('SELECT imap FROM accountuser WHERE username = "info.example.test"'));
    }

    public function testDeletionTakesTheStatusRowWithIt(): void
    {
        $this->repository->setStatus('info.example.test', false, 'anna');

        $this->repository->delete('info.example.test', DomainName::fromStoredValue('example.test'));

        self::assertSame(0, $this->statusRows());
    }

    public function testCreationClearsAStatusRowLeftUnderTheName(): void
    {
        // A row left behind by a deletion outside mailadmin (webcyradm).
        $this->connection->execute(
            'INSERT INTO mailadmin_account_status (username, disabled, disabled_at, disabled_by, updated_at, updated_by) '
            . 'VALUES ("anna.example.test", 1, UTC_TIMESTAMP(), "x", UTC_TIMESTAMP(), "x")',
        );

        $this->repository->create($this->identity('anna'), 'geheim', AccountServices::all(), 'exam');

        self::assertSame(0, $this->statusRows());
        self::assertTrue($this->repository->find('anna.example.test')?->status->enabled);
    }

    // ------------------------------------------- the catchall (U-128)

    public function testADeletionRewritesTheCatchallInTheSameStep(): void
    {
        $this->connection->execute('INSERT INTO virtual (alias, dest, username) VALUES ("@example.test", "info@example.test, team@example.org", "info.example.test")');
        $audit = new InMemoryAuditTrail();

        $this->deleteAccount($audit)->handle(DomainFixture::superuser(), 'info.example.test', $this->etagOfInfo());

        self::assertNull($this->repository->find('info.example.test'));
        self::assertSame(
            [['alias' => '@example.test', 'dest' => 'team@example.org', 'username' => '']],
            $this->connection->select('SELECT alias, dest, username FROM virtual ORDER BY alias'),
        );

        $parent = $audit->withAction(DeleteAccount::OPERATION)[0];
        $child = $audit->withAction(SetCatchall::OPERATION);
        self::assertCount(1, $child);
        self::assertSame($parent->id, $child[0]->parentId);
    }

    public function testADeletionRemovesACatchallLeftWithNothing(): void
    {
        $this->connection->execute('INSERT INTO virtual (alias, dest, username, status) VALUES ("@example.test", "INFO@example.test", "", 0)');
        $this->connection->execute('INSERT INTO virtual (alias, dest, username) VALUES ("@other.test", "info@example.test", "")');
        $audit = new InMemoryAuditTrail();

        $this->deleteAccount($audit)->handle(DomainFixture::superuser(), 'info.example.test', $this->etagOfInfo());

        // The catchall of another domain is not ours to change (O-20).
        self::assertSame(
            ['@other.test'],
            array_column($this->connection->select('SELECT alias FROM virtual ORDER BY alias'), 'alias'),
        );
        self::assertCount(1, $audit->withAction(DeleteCatchall::OPERATION));
    }

    public function testADeletionLeavesRowsOfOtherDomainsThatNameTheAccountAsSender(): void
    {
        // Rows of other.test that may name info.example.test as their sender
        // because it is among their destinations (SenderRules::explicit()):
        // the catchall, an alias, and a key naming an account of other.test.
        $this->connection->execute('INSERT INTO accountuser (username, password, prefix, domain_name) VALUES ("bob.other.test", "x", "othe", "other.test")');
        $this->connection->execute('INSERT INTO virtual (alias, dest, username) VALUES ("@other.test", "info.example.test,office@other.test", "info.example.test")');
        $this->connection->execute('INSERT INTO virtual (alias, dest, username) VALUES ("kontakt@OTHER.test", "info.example.test,office@other.test", "info.example.test")');
        $this->connection->execute('INSERT INTO virtual (alias, dest, username) VALUES ("bob.other.test", "info.example.test", "info.example.test")');
        // Rows of the account's own domain still go with it.
        $this->connection->execute('INSERT INTO virtual (alias, dest, username) VALUES ("kontakt@Example.test", "info.example.test", "info.example.test")');
        $this->connection->execute('INSERT INTO virtual (alias, dest, username) VALUES ("nobody.key", "info.example.test", "info.example.test")');
        $audit = new InMemoryAuditTrail();

        $this->deleteAccount($audit)->handle(DomainFixture::superuser(), 'info.example.test', $this->etagOfInfo());

        self::assertNull($this->repository->find('info.example.test'));
        self::assertSame(
            [
                ['alias' => '@other.test', 'dest' => 'info.example.test,office@other.test', 'username' => 'info.example.test'],
                ['alias' => 'bob.other.test', 'dest' => 'info.example.test', 'username' => 'info.example.test'],
                ['alias' => 'kontakt@OTHER.test', 'dest' => 'info.example.test,office@other.test', 'username' => 'info.example.test'],
            ],
            $this->connection->select('SELECT alias, dest, username FROM virtual ORDER BY alias'),
        );
        self::assertSame(3, $audit->withAction(DeleteAccount::OPERATION)[0]->before['virtual_rows_removed'] ?? null);
    }

    private function deleteAccount(InMemoryAuditTrail $audit): DeleteAccount
    {
        $mailstore = new InMemoryMailstore();
        $mailstore->given($this->identity('info'));
        $virtual = new VirtualRepository($this->connection);
        $repairs = new InMemoryRepairQueue();

        return new DeleteAccount(
            $this->repository,
            new AccountLocator($this->repository),
            $mailstore,
            $repairs,
            $audit,
            new NullLogger(),
            new DomainRepository($this->connection, new FrozenClock()),
            $virtual,
            new CatchallCleanup($virtual, new AddressReader($virtual, new VirtualEntryClassifier())),
            new RowWriter($virtual, $audit, $repairs, new NullLogger()),
        );
    }

    private function etagOfInfo(): string
    {
        $record = $this->repository->find('info.example.test');
        self::assertNotNull($record);

        return AccountView::etag($record);
    }

    private function statusRows(): int
    {
        return (int) $this->connection->selectValue('SELECT COUNT(*) FROM mailadmin_account_status');
    }

    private function identity(string $local, NamingScheme $scheme = NamingScheme::Dotted): MailboxIdentity
    {
        return MailboxIdentity::create(LocalPart::fromString($local), DomainName::fromStoredValue('example.test'), $scheme);
    }

    /**
     * 0003 widens `accountuser.password` for bcrypt; 0030 is A1's settings
     * table; 0060 the status table (U-127). Nothing else of ours is needed here.
     */
    private function applyOwnMigrations(): void
    {
        $this->dropIfPresent('mailadmin_domain_setting', 'mailadmin_account_status');
        $this->willCreate('mailadmin_domain_setting', 'mailadmin_account_status');

        foreach ((new MigrationLoader(dirname(__DIR__, 3) . '/migrations'))->load() as $migration) {
            if (!in_array($migration->number, [3, 30, 60], true)) {
                continue;
            }

            foreach ($migration->statements as $statement) {
                $this->pdo->exec($statement);
            }
        }
    }
}
