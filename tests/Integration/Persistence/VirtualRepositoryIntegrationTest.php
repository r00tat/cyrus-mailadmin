<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Persistence;

use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Infrastructure\Persistence\VirtualRepository;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `VirtualRepository` against a real MariaDB with the legacy schema — MyISAM,
 * latin1, no key — and the queries Postfix runs against what A3 writes.
 *
 * **Why the Postfix queries and not a delivery.** The F5 Postfix cannot deliver
 * an alias end to end: a dotted account name in `dest` has no domain, Postfix
 * completes it with `myorigin`, which is not in `mydestination`, and the mail
 * leaves for the relay instead of the LMTP socket. That is the test
 * environment, not the product (see A3, "Tests"). What can be checked exactly
 * is what Postfix asks the database: the `virtual_alias_maps` query of
 * `docker/mail/postfix/mysql-virtual.cf.tmpl` and the `validsenders` view of
 * `docker/db/init/10-views.sql`, read from those files so that a change there
 * changes the test.
 *
 * Skips without a database. It never passes without one.
 */
#[CoversClass(VirtualRepository::class)]
final class VirtualRepositoryIntegrationTest extends DatabaseTestCase
{
    private VirtualRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLegacyBaseline();

        // The baseline's own account and address are replaced by a set this
        // test controls in full.
        $this->connection->execute('DELETE FROM virtual');
        $this->connection->execute('DELETE FROM accountuser');

        $this->connection->execute(
            'INSERT INTO accountuser (username, password, prefix, domain_name) VALUES '
            . '("anna.example.test", "x", "exam", "example.test"), '
            . '("carl@example.test", "x", "exam", "example.test"), '
            . '("bert.other.test", "x", "othe", "other.test")',
        );
        $this->connection->execute(
            'INSERT INTO virtual (alias, dest, username) VALUES '
            . '("anna@example.test", "anna.example.test", "anna.example.test"), '
            . '("carl@example.test", "carl@example.test", "carl@example.test"), '
            . '("bert@other.test", "bert.other.test", "bert.other.test")',
        );

        $this->repository = new VirtualRepository($this->connection);
    }

    // -----------------------------------------------------------------
    // The store
    // -----------------------------------------------------------------

    public function testTheThreeWaysARowBelongsToADomain(): void
    {
        $this->insert('info@Example.test', 'anna.example.test', 'example.test');
        $this->insert('@example.test', 'anna.example.test', '');
        $this->insert('anna.example.test', 'extern@elsewhere.test', '');
        $this->insert('send-only', 'x@elsewhere.test', 'anna.example.test');
        $this->insert('info@notexample.test', 'x@elsewhere.test', '');
        $this->insert('bert.other.test', 'extern@elsewhere.test', '');

        $aliases = array_map(
            static fn (VirtualRow $row): string => $row->alias,
            $this->repository->rowsOfDomain(DomainName::fromStoredValue('example.test')),
        );
        sort($aliases);

        self::assertSame(
            ['@example.test', 'anna.example.test', 'anna@example.test', 'carl@example.test', 'info@Example.test', 'send-only'],
            $aliases,
        );
    }

    public function testABareKeyBelongsToTheDomainOfTheAccountItNames(): void
    {
        // U-126: the key of another domain's account, with a sender of ours.
        $this->insert('bert.other.test', 'extern@elsewhere.test', 'anna.example.test');
        $this->insert('BERT.other.test', 'extern@elsewhere.test', 'anna.example.test');
        // A case variant of our own key, with and without a sender.
        $this->insert('ANNA.example.test', 'extern@elsewhere.test', '');
        $this->insert('Anna.Example.Test', 'extern@elsewhere.test', 'bert.other.test');
        // A key that names no account: the sender's domain.
        $this->insert('send-only', 'x@elsewhere.test', 'anna.example.test');

        $aliasesOf = fn (string $domain): array => array_map(
            static fn (VirtualRow $row): string => $row->alias,
            $this->repository->rowsOfDomain(DomainName::fromStoredValue($domain)),
        );

        $ours = $aliasesOf('example.test');
        sort($ours);
        self::assertSame(
            ['ANNA.example.test', 'Anna.Example.Test', 'anna@example.test', 'carl@example.test', 'send-only'],
            $ours,
        );

        $theirs = $aliasesOf('other.test');
        sort($theirs);
        self::assertSame(['BERT.other.test', 'bert.other.test', 'bert@other.test'], $theirs);
    }

    public function testAliasLookupIgnoresCaseAndForwardsMatchTheStoredName(): void
    {
        $this->insert('INFO@example.test', 'anna.example.test', 'example.test');
        $this->insert('anna.example.test', 'extern@elsewhere.test', '');
        $this->insert('ANNA.example.test', 'other@elsewhere.test', '');

        self::assertCount(1, $this->repository->rowsWithAlias('info@example.test'));
        self::assertTrue($this->repository->addressTaken('Info@Example.Test'));
        self::assertFalse($this->repository->addressTaken('nobody@example.test'));
        self::assertTrue($this->repository->addressTaken('ANNA.EXAMPLE.TEST'), 'an account name is taken as well');

        $forwards = $this->repository->forwardRowsOf('anna.example.test');
        self::assertSame(['anna.example.test'], array_map(static fn (VirtualRow $row): string => $row->alias, $forwards));
    }

    public function testAccountNamesAreFoundWithoutRegardToCaseAndReturnedInTheirStoredSpelling(): void
    {
        $found = $this->repository->accountDomains(['ANNA.example.test', 'carl@EXAMPLE.test', 'nobody', 'extern@elsewhere.test']);

        self::assertSame(['anna.example.test', 'carl@example.test'], array_keys($found));
        self::assertSame('example.test', $found['anna.example.test']->toString());
        self::assertTrue($this->repository->isAccountName('Bert.Other.Test'));
    }

    public function testRemovalIsExactAndOneRowAtATime(): void
    {
        $row = new VirtualRow('info@example.test', 'anna.example.test', 'example.test', 1);
        $this->repository->insert($row);
        $this->repository->insert($row);
        $this->insert('INFO@example.test', 'anna.example.test', 'example.test');

        self::assertSame(2, $this->repository->count($row));
        self::assertTrue($this->repository->remove($row));
        self::assertSame(1, $this->repository->count($row));
        self::assertFalse($this->repository->remove(new VirtualRow('info@example.test', 'ANNA.example.test', 'example.test', 1)));
        self::assertCount(2, $this->repository->rowsWithAlias('info@example.test'), 'the other spelling stays');
    }

    public function testANullDestinationIsReadAsEmptyAndRemovedByThatValue(): void
    {
        $this->connection->execute('INSERT INTO virtual (alias, dest, username) VALUES ("broken@example.test", NULL, "")');

        $rows = $this->repository->rowsWithAlias('broken@example.test');
        self::assertCount(1, $rows);
        self::assertSame('', $rows[0]->dest);

        self::assertSame(1, $this->repository->count($rows[0]));
        self::assertTrue($this->repository->remove($rows[0]));
        self::assertSame([], $this->repository->rowsWithAlias('broken@example.test'));
    }

    public function testLatin1CharactersSurviveTheRoundTripAndMatchExactly(): void
    {
        $row = new VirtualRow("m\u{00fc}ller@example.test", 'x@elsewhere.test', '', 1);
        $this->repository->insert($row);

        $read = $this->repository->rowsWithAlias("m\u{00fc}ller@example.test");
        self::assertCount(1, $read);
        self::assertTrue($read[0]->equals($row));
        self::assertSame(0, $this->repository->count(new VirtualRow('muller@example.test', 'x@elsewhere.test', '', 1)));
        self::assertTrue($this->repository->remove($row));
    }

    // -----------------------------------------------------------------
    // What Postfix reads
    // -----------------------------------------------------------------

    public function testAnAliasAndItsSenderAreWhatPostfixAndValidsendersSee(): void
    {
        $this->repository->insert(new VirtualRow('info@example.test', 'anna.example.test,extern@elsewhere.test', 'anna.example.test', 1));
        $this->repository->insert(new VirtualRow('team@example.test', 'anna.example.test,carl@example.test', 'example.test', 1));

        self::assertSame(['anna.example.test,extern@elsewhere.test'], $this->postfixLookup('info@example.test'));
        self::assertSame(['anna.example.test', 'anna@example.test'], $this->sendersOf('info@example.test'));

        // The placeholder names no account: nobody may send as the alias.
        self::assertSame(['anna.example.test,carl@example.test'], $this->postfixLookup('team@example.test'));
        self::assertSame([], $this->sendersOf('team@example.test'));
    }

    public function testADisabledAliasFallsThroughToTheCatchall(): void
    {
        $this->repository->insert(new VirtualRow('info@example.test', 'anna.example.test', 'anna.example.test', 0));
        $this->repository->insert(new VirtualRow('@example.test', 'carl@example.test', '', 1));

        // Postfix asks for the full address first, then for `@domain`.
        self::assertSame([], $this->postfixLookup('info@example.test'));
        self::assertSame(['carl@example.test'], $this->postfixLookup('@example.test'));
        self::assertSame([], $this->sendersOf('info@example.test'));
        self::assertSame([], $this->sendersOf('@example.test'), 'a catchall without sender grants nothing');
    }

    public function testAForwardIsFollowedByPostfixThroughTheAddressRow(): void
    {
        $this->repository->insert(new VirtualRow('anna.example.test', 'extern@elsewhere.test,anna.example.test', '', 1));

        self::assertSame(['anna.example.test'], $this->postfixLookup('anna@example.test'));
        self::assertSame(['extern@elsewhere.test,anna.example.test'], $this->postfixLookup('anna.example.test'));
    }

    public function testRowsWrittenByTheRepositoryKeepTheViewValid(): void
    {
        $this->repository->insert(new VirtualRow('x@example.test', 'anna.example.test', 'anna.example.test', 1));
        $this->repository->remove(new VirtualRow('x@example.test', 'anna.example.test', 'anna.example.test', 1));

        // Selecting the whole view is what breaks first when the view does.
        $all = $this->connection->select(
            'SELECT alias, username FROM (' . self::validsendersBody() . ') AS validsenders ORDER BY alias, username',
        );
        self::assertNotSame([], $all);
    }

    // -----------------------------------------------------------------

    private function insert(string $alias, ?string $dest, string $username, int $status = 1): void
    {
        $this->connection->execute(
            'INSERT INTO virtual (alias, dest, username, status) VALUES (:alias, :dest, :username, :status)',
            ['alias' => $alias, 'dest' => $dest, 'username' => $username, 'status' => $status],
        );
    }

    /**
     * `virtual_alias_maps`, with the `%s` of the map as a bound parameter.
     *
     * @return list<string>
     */
    private function postfixLookup(string $key): array
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/docker/mail/postfix/mysql-virtual.cf.tmpl');
        self::assertSame(1, preg_match('/^query\s*=\s*(.+)$/m', $template, $match));
        $query = str_replace("'%s'", ':key', trim($match[1] ?? ''));

        return array_map(
            static fn (array $row): string => (string) $row['dest'],
            $this->connection->select($query, ['key' => $key]),
        );
    }

    /**
     * `validsenders` as `docker/db/init/10-views.sql` defines it — its body
     * used as a derived table, because the test user may not create views in
     * the disposable database, and the definition is what is under test.
     *
     * @return list<string>
     */
    private function sendersOf(string $alias): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['username'],
            $this->connection->select(
                'SELECT username FROM (' . self::validsendersBody() . ') AS validsenders '
                . 'WHERE alias = :alias ORDER BY username',
                ['alias' => $alias],
            ),
        );
    }

    private static function validsendersBody(): string
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 3) . '/docker/db/init/10-views.sql');
        self::assertSame(1, preg_match('/CREATE OR REPLACE VIEW validsenders AS(.+?);/s', $sql, $match));

        return trim($match[1] ?? '');
    }
}
