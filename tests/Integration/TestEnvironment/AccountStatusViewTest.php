<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\TestEnvironment;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The one authorised change to `allusers` (U-127): the view leaves disabled
 * accounts out — and changes **nothing else**.
 *
 * `allusers` is what `pam_mysql` authenticates against. A view that returned
 * one row more, one row fewer or one column spelled differently for an account
 * nobody disabled would change who can log in. So the new view is compared
 * with the old definition, verbatim, on the fixtures: identical without a
 * status row, and without exactly the disabled account's rows with one.
 *
 * The file checks need no environment: the status table exists in two places
 * (the migration, and `docker/db/init/05-account-status.sql`, which runs before
 * the view is created), and the view in two (`10-views.sql` and the Salt
 * template in `docs/deployment/`). Each pair must stay identical.
 */
final class AccountStatusViewTest extends TestCase
{
    use RequiresTestEnvironment;

    /**
     * `allusers` as it was before U-127, character for character from
     * `10-views.sql` at that time. Do not edit: this is the reference.
     */
    private const string OLD_VIEW = <<<'SQL'
        SELECT CONCAT(REPLACE(username, CONCAT('.', domain_name), ''), '@', domain_name) AS username,
               password                                                                  AS password,
               domain_name                                                               AS domain_name,
               imap                                                                      AS imap,
               pop                                                                       AS pop,
               sieve                                                                     AS sieve,
               smtpauth                                                                  AS smtpauth
        FROM accountuser
        UNION
        SELECT username    AS username,
               password    AS password,
               domain_name AS domain_name,
               imap        AS imap,
               pop         AS pop,
               sieve       AS sieve,
               smtpauth    AS smtpauth
        FROM accountuser
        SQL;

    private ?PDO $pdo = null;

    /** @var list<string> status rows this test wrote */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $username) {
            $this->pdo()->prepare('DELETE FROM mailadmin_account_status WHERE username = :u')->execute(['u' => $username]);
        }

        $this->written = [];
        $this->pdo = null;
    }

    public function testWithoutStatusRowsTheViewIsTheOldOne(): void
    {
        $this->requireFixtures();
        $this->requireNoStatusRows();

        self::assertNotSame([], $this->rows(self::OLD_VIEW));
        self::assertSame($this->rows(self::OLD_VIEW), $this->rows('SELECT * FROM allusers'));
    }

    public function testAnEnabledStatusRowChangesNothing(): void
    {
        $this->requireFixtures();
        $this->requireNoStatusRows();

        $account = $this->someAccount();
        $this->writeStatus($account, disabled: false);

        self::assertSame($this->rows(self::OLD_VIEW), $this->rows('SELECT * FROM allusers'));
    }

    public function testADisabledAccountLosesExactlyItsOwnRows(): void
    {
        $this->requireFixtures();
        $this->requireNoStatusRows();

        $account = $this->someAccount();
        $this->writeStatus($account, disabled: true);

        $without = str_replace(
            ['FROM accountuser' . "\n" . 'UNION', "FROM accountuser\n"],
            ['FROM accountuser WHERE username <> :first' . "\n" . 'UNION', "FROM accountuser WHERE username <> :second\n"],
            self::OLD_VIEW . "\n",
        );

        $expected = $this->rows($without, ['first' => $account, 'second' => $account]);
        $actual = $this->rows('SELECT * FROM allusers');

        self::assertSame($expected, $actual);
        self::assertLessThan(count($this->rows(self::OLD_VIEW)), count($actual));
        self::assertNotContains($account, array_column($actual, 'username'));
    }

    public function testTheMigrationAndTheInitScriptCreateTheSameTable(): void
    {
        self::assertSame(
            self::statement(self::file('migrations/0060_account_status.sql'), 'CREATE TABLE'),
            self::statement(self::file('docker/db/init/05-account-status.sql'), 'CREATE TABLE'),
        );
    }

    public function testTheSaltTemplateCarriesTheSameView(): void
    {
        self::assertSame(
            self::statement(self::file('docker/db/init/10-views.sql'), 'CREATE OR REPLACE VIEW allusers'),
            self::statement(self::file('docs/deployment/allusers.sql.example'), 'CREATE OR REPLACE VIEW allusers'),
        );
    }

    private function requireNoStatusRows(): void
    {
        $statement = $this->pdo()->query('SELECT COUNT(*) FROM mailadmin_account_status');
        self::assertNotFalse($statement);
        $count = (int) $statement->fetchColumn();

        if ($count !== 0) {
            self::markTestSkipped('The environment holds status rows from elsewhere; the comparison needs none. Run `bin/test-env reset`.');
        }
    }

    private function someAccount(): string
    {
        $statement = $this->pdo()->query('SELECT username FROM accountuser ORDER BY username LIMIT 1');
        self::assertNotFalse($statement);
        $username = $statement->fetchColumn();
        self::assertIsString($username);

        return $username;
    }

    private function writeStatus(string $username, bool $disabled): void
    {
        $this->written[] = $username;

        $this->pdo()->prepare(
            'INSERT INTO mailadmin_account_status (username, disabled, disabled_at, disabled_by, updated_at, updated_by) '
            . 'VALUES (:username, :disabled, UTC_TIMESTAMP(), :by, UTC_TIMESTAMP(), :changer)',
        )->execute(['username' => $username, 'disabled' => $disabled ? 1 : 0, 'by' => 'test', 'changer' => 'test']);
    }

    /**
     * Every row, as strings, in a fixed order — the comparison is of content,
     * not of the order a UNION happens to produce.
     *
     * @param array<string, string> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM (' . $sql . ') AS v ORDER BY username, password, domain_name');
        $statement->execute($parameters);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => array_map(static fn (mixed $value): mixed => $value === null ? null : (string) $value, $row),
            $rows,
        );
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= TestEnvironment::connection();
    }

    private static function file(string $path): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/' . $path);
        self::assertIsString($content, $path);

        return $content;
    }

    /**
     * The statement that starts with `$start`, without comments, with its
     * whitespace collapsed.
     */
    private static function statement(string $sql, string $start): string
    {
        $code = (string) preg_replace('/--[^\n]*/', '', $sql);
        $begin = strpos($code, $start);
        self::assertNotFalse($begin, sprintf('No "%s" statement found.', $start));

        $end = strpos($code, ';', $begin);
        self::assertNotFalse($end);

        return trim((string) preg_replace('/\s+/', ' ', substr($code, $begin, $end - $begin)));
    }
}
