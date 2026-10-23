<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Persistence;

use Mailadmin\Application\Domain\DomainDefaults;
use Mailadmin\Application\Domain\DomainOperationFailed;
use Mailadmin\Application\Domain\DomainSummary;
use Mailadmin\Application\Domain\RenameDomain;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Infrastructure\Mailstore\InMemoryMailstore;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Mailadmin\Infrastructure\Persistence\DomainRepository;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationLoader;
use Mailadmin\Infrastructure\Repair\InMemoryRepairQueue;
use Mailadmin\Tests\Support\DomainFixture;
use Mailadmin\Tests\Support\FrozenClock;
use Mailadmin\Tests\Support\RefusingAuditWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

/**
 * `DomainRepository` against a real MariaDB with a real legacy schema.
 *
 * The in-memory double carries the behavioural tests. This suite exists for what
 * a double cannot be wrong about: the statements themselves, the `enum('YES','NO')`
 * round trip, the two-charset lookup that deliberately avoids a join — and above
 * all {@see DomainRepository::rename()}, which is five tables in one transaction
 * and the one place where a wrong `UPDATE` makes accounts unreachable.
 *
 * Skips without a database. It never passes without one.
 */
#[CoversClass(DomainRepository::class)]
final class DomainRepositoryIntegrationTest extends DatabaseTestCase
{
    private DomainRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLegacyBaseline();
        $this->applyOwnMigrations();

        $this->repository = new DomainRepository($this->connection, new FrozenClock());
    }

    public function testAnExistingLegacyRowIsReadWithItsColumnsIntact(): void
    {
        // `createLegacyBaseline()` inserts example.test with one account and
        // `folders = "Sent,Trash"`.
        $found = $this->repository->find(DomainFixture::name('example.test'));

        self::assertNotNull($found);
        self::assertSame('exam', $found->domain->legacyPrefix);
        self::assertSame(['Sent', 'Trash'], $found->domain->defaultFolders->names());
        self::assertSame(20, $found->domain->maxAccounts->maximum());
        self::assertSame(20000, $found->domain->defaultAccountQuota->limitKibibytes());
        self::assertTrue($found->domain->domainQuota->isUnlimited());
        self::assertFalse($found->domain->freeNames);
        self::assertSame(1, $found->accountCount);
    }

    public function testALegacyZeroMeansUnlimitedAndNotZero(): void
    {
        $this->pdo->exec('UPDATE `domain` SET maxaccounts = 0, quota = 0 WHERE domain_name = "example.test"');

        $found = $this->repository->find(DomainFixture::name('example.test'));

        self::assertNotNull($found);
        self::assertTrue($found->domain->maxAccounts->isUnlimited());
        self::assertTrue($found->domain->defaultAccountQuota->isUnlimited());
    }

    public function testAFolderNameNobodyCouldCreateTodayDoesNotBreakTheListing(): void
    {
        // U-22: existing data is shown, never allowed to take the page down.
        // `bad@name` cannot be a Cyrus folder, and the rest of the list survives.
        $this->pdo->exec('UPDATE `domain` SET folders = "Sent,bad@name,Trash" WHERE domain_name = "example.test"');

        $found = $this->repository->find(DomainFixture::name('example.test'));

        self::assertNotNull($found);
        self::assertSame(['Sent', 'Trash'], $found->domain->defaultFolders->names());
    }

    public function testARoundTripThroughTheTableKeepsEveryValue(): void
    {
        $this->repository->create(DomainFixture::domain(
            'neu.test',
            maxAccounts: 7,
            accountQuotaKib: 12345,
            domainQuotaKib: 99999,
            scheme: NamingScheme::Fqun,
            prefix: 'neu.test',
            freeNames: true,
            freeAddress: false,
            folders: 'Sent',
        ));

        $found = $this->repository->find(DomainFixture::name('neu.test'));

        self::assertNotNull($found);
        self::assertSame(7, $found->domain->maxAccounts->maximum());
        self::assertSame(12345, $found->domain->defaultAccountQuota->limitKibibytes());
        self::assertSame(99999, $found->domain->domainQuota->limitKibibytes());
        self::assertSame(['Sent'], $found->domain->defaultFolders->names());
        self::assertTrue($found->domain->freeNames);
        self::assertFalse($found->domain->freeAddress);
        // The scheme comes from `mailadmin_domain_setting`, in a second
        // statement rather than a join across two charsets.
        self::assertSame(NamingScheme::Fqun, $found->domain->namingScheme);
    }

    public function testClearingTheSchemeRemovesTheSettingsRowRatherThanStoringANull(): void
    {
        $this->repository->create(DomainFixture::domain('neu.test', scheme: NamingScheme::Fqun, prefix: 'neu.test'));

        $found = $this->repository->find(DomainFixture::name('neu.test'));
        self::assertNotNull($found);

        $this->repository->update($found->domain->withNamingScheme(null));

        self::assertSame(
            0,
            (int) $this->connection->selectValue(
                'SELECT COUNT(*) FROM `mailadmin_domain_setting` WHERE domain_name = "neu.test"',
            ),
        );

        $again = $this->repository->find(DomainFixture::name('neu.test'));
        self::assertNotNull($again);
        self::assertNull($again->domain->namingScheme);
    }

    public function testUpdatingNeverTouchesThePrefix(): void
    {
        // U-46: assigned once, at creation. A rename does not change it either.
        $found = $this->repository->find(DomainFixture::name('example.test'));
        self::assertNotNull($found);

        $this->repository->update($found->domain->withAllowances(
            $found->domain->maxAccounts,
            $found->domain->defaultAccountQuota,
            $found->domain->domainQuota,
        ));

        self::assertSame('exam', (string) $this->connection->selectValue(
            'SELECT prefix FROM `domain` WHERE domain_name = "example.test"',
        ));
    }

    public function testAPageIsScopedOrderedAndCounted(): void
    {
        $this->repository->create(DomainFixture::domain('alpha.test', maxAccounts: 30, prefix: 'alpha.test'));
        $this->repository->create(DomainFixture::domain('beta.test', maxAccounts: 10, prefix: 'beta.test'));

        $all = $this->repository->list(DomainFixture::superuser(), new ListCriteria());
        self::assertSame(3, $all->total);

        $scoped = $this->repository->list(
            DomainFixture::domainMaster('anna', 'beta.test'),
            new ListCriteria(),
        );

        // The predicate is in the query, so `total` is the scoped total (U-45).
        self::assertSame(1, $scoped->total);
        self::assertSame(['beta.test'], self::names($scoped->items));

        $sorted = $this->repository->list(
            DomainFixture::superuser(),
            new ListCriteria(sort: 'max_accounts', order: SortDirection::Descending),
        );

        self::assertSame(['alpha.test', 'example.test', 'beta.test'], self::names($sorted->items));
    }

    public function testAnAdministratorWithNoDomainsSeesNothingRatherThanEverything(): void
    {
        // `restrictToDomains()` produces `1 = 0` for an empty set. This asserts
        // the property through the repository, because the failure mode — no
        // predicate at all — looks like success from every other angle.
        $actor = DomainFixture::domainMaster('anna', 'gibtsnicht.test');
        $page = $this->repository->list($actor, new ListCriteria());

        self::assertSame(0, $page->total);
        self::assertSame([], $page->items);
    }

    public function testTheNameFilterEscapesTheWildcardsItIsGiven(): void
    {
        $page = $this->repository->list(
            DomainFixture::superuser(),
            new ListCriteria(filters: ['name' => '%']),
        );

        // A raw `%` would match everything. The allowlist escapes it, so it
        // matches the domains whose name literally contains a per cent sign.
        self::assertSame(0, $page->total);
    }

    public function testDeletingRemovesTheRowAndItsSettings(): void
    {
        $this->repository->create(DomainFixture::domain('neu.test', scheme: NamingScheme::Dotted, prefix: 'neu.test'));
        $this->repository->delete(DomainFixture::name('neu.test'));

        self::assertNull($this->repository->find(DomainFixture::name('neu.test')));
        self::assertSame(0, (int) $this->connection->selectValue(
            'SELECT COUNT(*) FROM `mailadmin_domain_setting` WHERE domain_name = "neu.test"',
        ));
    }

    public function testDeletingRemovesTheAssignmentsOfThatDomainAndReportsThem(): void
    {
        $this->repository->create(DomainFixture::domain('neu.test', prefix: 'neu.test'));
        $this->pdo->exec(
            'INSERT INTO `domainadmin` (domain_name, adminuser) VALUES '
            . '("neu.test", "anna"), ("NEU.test", "bert"), ("example.test", "anna"), ("*", "chef")',
        );

        $removed = $this->repository->delete(DomainFixture::name('neu.test'));

        self::assertSame(['anna', 'bert'], $removed);
        self::assertSame(
            [['example.test', 'anna'], ['*', 'chef']],
            array_map(
                static fn (array $row): array => [$row['domain_name'], $row['adminuser']],
                $this->connection->select('SELECT domain_name, adminuser FROM `domainadmin` ORDER BY adminuser'),
            ),
        );
    }

    public function testTheAddressesOfADomainAreCountedBySuffixWithoutWildcards(): void
    {
        $this->pdo->exec(
            'INSERT INTO `virtual` (alias, dest, username) VALUES '
            . '("@Example.TEST", "info.example.test", ""), '
            . '("weiter@example.test", "x@anderswo.invalid", ""), '
            . '("info@sub.example.test", "x", ""), '
            . '("info@xexample.test", "x", ""), '
            . '("example.test", "x", "")',
        );

        // The baseline's `info@example.test`, the catchall and the forward.
        self::assertSame(3, $this->repository->addressCount(DomainFixture::name('example.test')));

        // `_` is a LIKE wildcard; matched with RIGHT() it is just a character.
        self::assertSame(0, $this->repository->addressCount(DomainFixture::name('exampl_.test')));
    }

    public function testADottedUserNameOfAParentDomainClaimsTheSubdomainsNamespace(): void
    {
        $this->pdo->exec(
            'INSERT INTO `accountuser` (username, password, prefix, domain_name) VALUES '
            . '("Info.Kunde.Example.test", "x", "exam", "example.test"), '
            . '("info.kunde@example.test", "x", "exam", "example.test"), '
            . '("info.xkunde.example.test", "x", "exam", "example.test")',
        );

        self::assertSame(
            'Info.Kunde.Example.test',
            $this->repository->namespaceClaimant(DomainFixture::name('kunde.example.test')),
        );
        self::assertNull($this->repository->namespaceClaimant(DomainFixture::name('other.example.test')));

        // The accounts of a domain that is being renamed move along.
        self::assertNull($this->repository->namespaceClaimant(
            DomainFixture::name('kunde.example.test'),
            except: DomainFixture::name('example.test'),
        ));
    }

    public function testAUserNameIsTakenWhateverItsSpellingButNotByTheDomainBeingRenamed(): void
    {
        self::assertTrue($this->repository->accountUsernameTaken('INFO.example.test', DomainFixture::name('other.test')));
        self::assertFalse($this->repository->accountUsernameTaken('info.example.test', DomainFixture::name('example.test')));
        self::assertFalse($this->repository->accountUsernameTaken('anna.example.test', DomainFixture::name('other.test')));
    }

    public function testAForwardKeyIsTakenWhateverItsSpelling(): void
    {
        // U-101: a forward row keyed by a Cyrus user name, as in the fixtures.
        $this->pdo->exec(
            'INSERT INTO `virtual` (alias, dest, username) VALUES '
            . '("clara.neu.test", "clara.extern@kunde-a.invalid", "")',
        );

        self::assertTrue($this->repository->aliasTaken('Clara.Neu.test'));
        self::assertFalse($this->repository->aliasTaken('clara.other.test'));
    }

    public function testASenderNameIsFoundWhateverItsSpelling(): void
    {
        // U-110: the placeholder sender of an alias (U-106).
        $this->pdo->exec(
            'INSERT INTO `virtual` (alias, dest, username) VALUES '
            . '("team@mail.neu.test", "a@kunde-a.invalid,b@kunde-a.invalid", "mail.neu.test")',
        );

        self::assertTrue($this->repository->senderNameTaken('Mail.Neu.test'));
        self::assertFalse($this->repository->senderNameTaken('team@mail.neu.test'));
    }

    public function testAssignmentsOfANameAreCountedWhateverTheirSpelling(): void
    {
        // U-99: rows that survived a domain of that name.
        $this->pdo->exec(
            'INSERT INTO `domainadmin` (domain_name, adminuser) VALUES '
            . '("kunde.test", "anna"), ("Kunde.TEST", "bert"), ("sub.kunde.test", "carl"), ("*", "chef")',
        );

        self::assertSame(2, $this->repository->assignmentCount(DomainFixture::name('kunde.test')));
        self::assertSame(0, $this->repository->assignmentCount(DomainFixture::name('other.test')));
    }

    public function testARenameTakesTheMovedAccountsForwardsWithIt(): void
    {
        // U-101: `alias` = the dotted user name, `username` empty. It does not
        // end in `@example.test`, so the suffix rewrite never saw it.
        $this->giveARichDomain();
        $this->pdo->exec(
            'INSERT INTO `virtual` (alias, dest, username) VALUES '
            . '("info.example.test", "info.extern@kunde-a.invalid", ""), '
            . '("Info.example.test", "nicht.meins@kunde-b.invalid", ""), '
            . '("info.example.test", "info.example.test", "info.example.test")',
        );

        $this->repository->rename(
            DomainFixture::name('example.test'),
            DomainFixture::name('neu.test'),
            [
                'info.example.test' => 'info.neu.test',
                'first.last.example.test' => 'first.last.neu.test',
            ],
        );

        self::assertSame(
            ['info.neu.test' => 'info.extern@kunde-a.invalid'],
            $this->destinationsOf('info.neu.test', forwardsOnly: true),
        );

        // Another spelling is another user's forward and stays where it was;
        // so does a row that is not a forward (its `username` is filled — and
        // follows the account through the `username` rewrite instead).
        self::assertSame(1, (int) $this->connection->selectValue(
            'SELECT COUNT(*) FROM `virtual` WHERE CAST(alias AS BINARY) = CAST("Info.example.test" AS BINARY)',
        ));
        self::assertSame(1, (int) $this->connection->selectValue(
            'SELECT COUNT(*) FROM `virtual` WHERE alias = "info.example.test" AND username = "info.neu.test"',
        ));
        self::assertSame(0, (int) $this->connection->selectValue(
            'SELECT COUNT(*) FROM `virtual` WHERE CAST(alias AS BINARY) = CAST("info.example.test" AS BINARY) '
            . 'AND username = ""',
        ));
    }

    public function testTheNamespaceLockIsReentrantAndReleased(): void
    {
        $inner = $this->repository->withNamespaceLock(
            fn (): string => $this->repository->withNamespaceLock(static fn (): string => 'nested'),
        );

        self::assertSame('nested', $inner);
        self::assertSame(1, (int) $this->connection->selectValue(
            'SELECT IS_FREE_LOCK(:name)',
            ['name' => DomainRepository::NAMESPACE_LOCK],
        ));
    }

    public function testARenameMovesEveryTableTheDomainNameIsSpelledIn(): void
    {
        $this->giveARichDomain();

        $this->repository->rename(
            DomainFixture::name('example.test'),
            DomainFixture::name('neu.test'),
            [
                'info.example.test' => 'info.neu.test',
                'first.last.example.test' => 'first.last.neu.test',
            ],
        );

        self::assertNull($this->repository->find(DomainFixture::name('example.test')));
        self::assertNotNull($this->repository->find(DomainFixture::name('neu.test')));

        // accountuser: both the user name and the domain of record.
        self::assertSame(
            ['first.last.neu.test', 'info.neu.test'],
            $this->repository->accountUsernames(DomainFixture::name('neu.test')),
        );

        // The local part with a dot in it survived — a `REPLACE` would not have
        // left it alone (architecture 3.3).
        self::assertSame(
            'first.last',
            MailboxIdentity::fromStoredUsername(
                'first.last.neu.test',
                DomainFixture::name('neu.test'),
            )->localPart->toString(),
        );

        self::assertSame(
            ['@neu.test', 'first.last@neu.test', 'info@neu.test', 'weiter@anderswo.invalid'],
            $this->aliases(),
        );

        self::assertSame(
            ['@neu.test' => 'info.neu.test'],
            $this->destinationsOf('@neu.test'),
        );

        // A forward in another domain that pointed *into* this one follows.
        self::assertSame(
            ['weiter@anderswo.invalid' => 'info@neu.test,bleibt@anderswo.invalid'],
            $this->destinationsOf('weiter@anderswo.invalid'),
        );

        self::assertSame('neu.test', (string) $this->connection->selectValue(
            'SELECT domain_name FROM `domainadmin` WHERE adminuser = "anna"',
        ));

        self::assertSame(1, (int) $this->connection->selectValue(
            'SELECT COUNT(*) FROM `mailadmin_domain_setting` WHERE domain_name = "neu.test"',
        ));

        // The status row follows its account's new name (U-127).
        self::assertSame(
            ['info.neu.test'],
            array_column($this->connection->select('SELECT username FROM `mailadmin_account_status`'), 'username'),
        );
    }

    public function testADisabledAccountStaysDisabledWhenTheRenameFailsAfterTheLegacyTables(): void
    {
        $this->giveARichDomain();
        // A row left under a target name by an account webcyradm deleted: it
        // must neither block the rename nor lock out the account that moves in.
        $this->pdo->exec(
            'INSERT INTO `mailadmin_account_status` (username, disabled, disabled_at, disabled_by, updated_at, updated_by) '
            . 'VALUES ("first.last.neu.test", 1, UTC_TIMESTAMP(), "x", UTC_TIMESTAMP(), "x")',
        );

        $mailstore = new InMemoryMailstore();

        foreach (['info', 'first.last'] as $local) {
            $mailstore->given(MailboxIdentity::create(
                LocalPart::fromString($local),
                DomainFixture::name('example.test'),
                NamingScheme::Dotted,
            ));
        }

        // The audit write inside the transaction fails: InnoDB rolls back, the
        // MyISAM tables keep what rename() wrote (O-11).
        $rename = new RenameDomain(
            $this->repository,
            $mailstore,
            new InMemoryRepairQueue(),
            new DomainDefaults(),
            RefusingAuditWriter::successes(new InMemoryAuditTrail()),
            new NullLogger(),
            \Mailadmin\Tests\Support\ListDeliveryFixture::delivery(),
        );

        try {
            $rename->handle(DomainFixture::superuser(), DomainFixture::name('example.test'), DomainFixture::name('neu.test'));
            self::fail('The rename should have failed.');
        } catch (DomainOperationFailed) {
        }

        // Whatever name the account row ended up with, its status is right.
        $stored = array_column($this->connection->select('SELECT username FROM `accountuser` ORDER BY username'), 'username');
        self::assertContains('info.neu.test', $stored, 'precondition: MyISAM kept the renamed account row');

        foreach ($stored as $username) {
            self::assertSame(
                $username === 'info.neu.test' || $username === 'info.example.test' ? 1 : 0,
                (int) $this->connection->selectValue(
                    'SELECT COALESCE(MAX(disabled), 0) FROM `mailadmin_account_status` WHERE username = :u',
                    ['u' => (string) $username],
                ),
                (string) $username,
            );
        }
    }

    public function testARenameLeavesAnotherDomainAlone(): void
    {
        $this->giveARichDomain();
        $this->repository->create(DomainFixture::domain('nachbar.test', prefix: 'nachbar.test'));
        $this->pdo->exec(
            'INSERT INTO `virtual` (alias, dest, username) '
            . 'VALUES ("info@nachbar.test", "info.nachbar.test", "info.nachbar.test")',
        );

        $this->repository->rename(
            DomainFixture::name('example.test'),
            DomainFixture::name('neu.test'),
            ['info.example.test' => 'info.neu.test', 'first.last.example.test' => 'first.last.neu.test'],
        );

        self::assertSame(
            ['info@nachbar.test' => 'info.nachbar.test'],
            $this->destinationsOf('info@nachbar.test'),
        );
    }

    public function testARenameInsideAnOuterTransactionJoinsItAndCommitsOnce(): void
    {
        // What `RenameDomain` does to put the audit entries into the same
        // transaction (U-63): the repository's own `transactional()` must join
        // the outer one rather than commit early or refuse to nest.
        $this->giveARichDomain();

        $result = $this->repository->atomically(function (): string {
            $this->repository->rename(
                DomainFixture::name('example.test'),
                DomainFixture::name('neu.test'),
                ['info.example.test' => 'info.neu.test', 'first.last.example.test' => 'first.last.neu.test'],
            );

            self::assertTrue($this->connection->inTransaction());

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertFalse($this->connection->inTransaction());
        self::assertNotNull($this->repository->find(DomainFixture::name('neu.test')));
        self::assertNull($this->repository->find(DomainFixture::name('example.test')));
    }

    public function testAChangeOfSpellingIsARenameLikeAnyOther(): void
    {
        $this->giveARichDomain();

        $this->repository->rename(
            DomainFixture::name('example.test'),
            DomainFixture::name('Example.Test'),
            ['info.example.test' => 'info.Example.Test', 'first.last.example.test' => 'first.last.Example.Test'],
        );

        // `accountuser.username` is a binary column, so the stored bytes are the
        // ones Cyrus knows the mailbox under.
        self::assertSame(
            ['first.last.Example.Test', 'info.Example.Test'],
            $this->repository->accountUsernames(DomainFixture::name('Example.Test')),
        );
    }

    /**
     * One domain with everything a rename has to follow: two accounts, one of
     * them with a dot in the local part, a catchall, an alias and a forward from
     * outside that points in.
     */
    private function giveARichDomain(): void
    {
        $this->pdo->exec('DELETE FROM `accountuser`');
        $this->pdo->exec('DELETE FROM `virtual`');
        $this->pdo->exec(
            'INSERT INTO `mailadmin_account_status` (username, disabled, disabled_at, disabled_by, updated_at, updated_by) '
            . 'VALUES ("info.example.test", 1, UTC_TIMESTAMP(), "anna", UTC_TIMESTAMP(), "anna")',
        );

        $this->pdo->exec(
            'INSERT INTO `accountuser` (username, password, prefix, domain_name) VALUES '
            . '("info.example.test", "x", "exam", "example.test"), '
            . '("first.last.example.test", "x", "exam", "example.test")',
        );

        $this->pdo->exec(
            'INSERT INTO `virtual` (alias, dest, username) VALUES '
            . '("info@example.test", "info.example.test", "info.example.test"), '
            . '("first.last@example.test", "first.last.example.test", "first.last.example.test"), '
            . '("@example.test", "info.example.test", ""), '
            . '("weiter@anderswo.invalid", "info@example.test,bleibt@anderswo.invalid", "")',
        );

        $this->pdo->exec(
            'INSERT INTO `domainadmin` (domain_name, adminuser) VALUES ("example.test", "anna")',
        );

        $this->repository->update(
            ($this->repository->find(DomainFixture::name('example.test'))?->domain
                ?? DomainFixture::domain('example.test'))->withNamingScheme(NamingScheme::Dotted),
        );
    }

    /**
     * @return list<string>
     */
    private function aliases(): array
    {
        $rows = $this->connection->select('SELECT alias FROM `virtual` ORDER BY alias');

        return array_map(static fn (array $row): string => (string) $row['alias'], $rows);
    }

    /**
     * @return array<string, string>
     */
    private function destinationsOf(string $alias, bool $forwardsOnly = false): array
    {
        $rows = $this->connection->select(
            'SELECT alias, dest FROM `virtual` WHERE alias = :alias'
            . ($forwardsOnly ? " AND username = ''" : ''),
            ['alias' => $alias],
        );

        $found = [];

        foreach ($rows as $row) {
            $found[(string) $row['alias']] = (string) $row['dest'];
        }

        return $found;
    }

    /**
     * @param list<DomainSummary> $items
     *
     * @return list<string>
     */
    private static function names(array $items): array
    {
        return array_map(
            static fn (DomainSummary $summary): string => $summary->domain->name->toString(),
            $items,
        );
    }

    private function applyOwnMigrations(): void
    {
        $this->dropIfPresent('mailadmin_domain_setting', 'mailadmin_account_status');
        $this->willCreate('mailadmin_domain_setting', 'mailadmin_account_status');

        foreach ((new MigrationLoader(dirname(__DIR__, 3) . '/migrations'))->load() as $migration) {
            if (!in_array($migration->number, [30, 60], true)) {
                continue;
            }

            foreach ($migration->statements as $statement) {
                $this->pdo->exec($statement);
            }
        }
    }
}
