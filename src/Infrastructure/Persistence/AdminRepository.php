<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use Mailadmin\Application\Admin\AdminAccount;
use Mailadmin\Application\Admin\AdminStore;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Password\PasswordFormatDetector;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterAllowlist;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterComparison;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterField;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortAllowlist;
use SensitiveParameter;

/**
 * `adminuser`, `domainadmin`, `settings` and `mailadmin_admin_setting` (A4).
 *
 * ## Two queries per listing, not one per row
 *
 * The assignments are fetched for the **whole page** in a single statement with
 * a `LEFT JOIN` onto `domain`, and then distributed. Asking per administrator
 * would be the shape webcyradm had, and it is the same shape that made its
 * account screens slow: a query per row, invisible until the table grows.
 *
 * The join is what answers `orphaned_domains` (U-22) — an assignment naming a
 * domain that has no row in `domain` is shown and marked, not dropped.
 *
 * ## The wildcard row is a scope, not a domain
 *
 * webcyradm writes "all domains" as `domainadmin.domain_name = '*'`
 * ({@see AdminRole::ALL_DOMAINS}). It is reported as `all_domains` and kept out
 * of the name list, and a superuser is written with exactly that row — so an
 * installation still running both interfaces sees the same thing in each.
 *
 * ## Sorting and filtering come from an allowlist
 *
 * `sortable()` and `filterable()` are the only way an `ORDER BY` or a `WHERE`
 * reaches the SQL here. The old system interpolated the sort field from the
 * session directly into the statement (`CLAUDE.md`, rule 2); the base class
 * makes that impossible rather than discouraged.
 *
 * `role` filters on `adminuser.type`, which is an integer. The translation from
 * `superuser`/`domainmaster` happens in the handler, where the contract's
 * vocabulary still exists — a filter allowlist maps names to columns, not values
 * to values.
 *
 * ## The transaction is honest about MyISAM
 *
 * {@see atomically()} opens a real transaction. Whether it rolls anything back
 * depends on the storage engine of the legacy tables, which is **O-11** and not
 * settled: MyISAM knows no transactions and will silently keep half the work.
 * That is a reason to check the engine before going live, not a reason to write
 * the statements in an order that pretends. They are ordered so that the worst
 * half-finished state is a recoverable one — the `adminuser` row is written
 * first when creating, so a failure leaves an administrator without assignments
 * rather than assignments without an administrator.
 */
final class AdminRepository extends Repository implements AdminStore
{
    /** Bound parameter prefix for the `IN` lists this class builds itself. */
    private const string NAME = 'ma_name_';

    protected function sortable(): SortAllowlist
    {
        // The keys are the `enum` of the contract's `sort` parameter. A contract
        // test compares the two lists so they cannot drift.
        return new SortAllowlist(
            ['username' => 'a.username', 'role' => 'a.type'],
            default: 'username',
            tiebreaker: 'username',
        );
    }

    protected function filterable(): FilterAllowlist
    {
        return new FilterAllowlist(
            new FilterField('username', 'a.username', FilterComparison::Contains),
            new FilterField('role', 'a.type'),
        );
    }

    public function list(ListCriteria $criteria): Page
    {
        $page = $this->fetchPage(
            new ListQuery(select: 'a.username, a.password, a.type', from: '`adminuser` a'),
            $criteria,
            static fn (array $row): array => $row,
        );

        $names = [];
        foreach ($page->items as $row) {
            $names[] = self::text($row['username'] ?? null);
        }

        $assignments = $this->assignmentsFor($names);

        return $page->map(fn (array $row): AdminAccount => self::toAccount($row, $assignments));
    }

    public function find(string $username): ?AdminAccount
    {
        $row = $this->connection->selectOne(
            'SELECT `username`, `password`, `type` FROM `adminuser` WHERE `username` = :username',
            ['username' => $username],
        );

        if ($row === null) {
            return null;
        }

        return self::toAccount($row, $this->assignmentsFor([self::text($row['username'] ?? null)]));
    }

    public function exists(string $username): bool
    {
        return (int) $this->connection->selectValue(
            'SELECT COUNT(*) FROM `adminuser` WHERE `username` = :username',
            ['username' => $username],
        ) > 0;
    }

    public function findPasswordHash(string $username): ?string
    {
        /** @var mixed $hash */
        $hash = $this->connection->selectValue(
            'SELECT `password` FROM `adminuser` WHERE `username` = :username',
            ['username' => $username],
        );

        return is_scalar($hash) ? (string) $hash : null;
    }

    public function countSuperusers(): int
    {
        return (int) $this->connection->selectValue(
            'SELECT COUNT(*) FROM `adminuser` WHERE `type` = :type',
            ['type' => AdminRole::Superuser->toLegacyType()],
        );
    }

    public function existingDomains(array $names): array
    {
        if ($names === []) {
            return [];
        }

        [$placeholders, $bindings] = self::inList($names);

        $rows = $this->connection->select(
            sprintf('SELECT `domain_name` FROM `domain` WHERE `domain_name` IN (%s)', $placeholders),
            $bindings,
        );

        $known = [];
        foreach ($rows as $row) {
            $known[] = self::text($row['domain_name'] ?? null);
        }

        // Filtered from the input rather than returned as the database ordered
        // it: the caller diffs against what it asked for, and a reordering there
        // would turn into a confusing "unknown domain" list.
        return array_values(array_filter($names, static fn (string $name): bool => in_array($name, $known, true)));
    }

    public function create(
        string $username,
        #[SensitiveParameter] string $passwordHash,
        AdminRole $role,
        array $domains,
    ): void {
        // `SID` and `home` are webcyradm's session columns. They have defaults,
        // and they are written explicitly as empty so that a non-strict MySQL
        // and a strict one produce the same row.
        $this->connection->execute(
            'INSERT INTO `adminuser` (`username`, `password`, `type`, `SID`, `home`) '
            . "VALUES (:username, :password, :type, '', '')",
            [
                'username' => $username,
                'password' => $passwordHash,
                'type' => $role->toLegacyType(),
            ],
        );

        $this->writeAssignment($username, $role, $domains);
    }

    public function replaceAssignment(string $username, AdminRole $role, array $domains): void
    {
        $this->connection->execute(
            'UPDATE `adminuser` SET `type` = :type WHERE `username` = :username',
            ['type' => $role->toLegacyType(), 'username' => $username],
        );

        $this->connection->execute(
            'DELETE FROM `domainadmin` WHERE `adminuser` = :username',
            ['username' => $username],
        );

        $this->writeAssignment($username, $role, $domains);
    }

    public function replacePasswordHash(string $username, #[SensitiveParameter] string $hash): void
    {
        $this->connection->execute(
            'UPDATE `adminuser` SET `password` = :hash WHERE `username` = :username',
            ['hash' => $hash, 'username' => $username],
        );
    }

    public function delete(string $username): void
    {
        // `domainadmin` first, so that a half-finished delete on a storage engine
        // without transactions (O-11) leaves an administrator with no domains —
        // an account that cannot act — rather than assignments pointing at
        // nobody, which is the row a later administrator of the same name would
        // inherit.
        $this->connection->execute(
            'DELETE FROM `domainadmin` WHERE `adminuser` = :username',
            ['username' => $username],
        );

        $this->connection->execute(
            'DELETE FROM `settings` WHERE `username` = :username',
            ['username' => $username],
        );

        $this->connection->execute(
            'DELETE FROM `mailadmin_admin_setting` WHERE `username` = :username',
            ['username' => $username],
        );

        $this->connection->execute(
            'DELETE FROM `adminuser` WHERE `username` = :username',
            ['username' => $username],
        );
    }

    public function atomically(callable $work): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $work());
    }

    /**
     * @param list<string> $domains
     */
    private function writeAssignment(string $username, AdminRole $role, array $domains): void
    {
        $rows = $role->isSuperuser() ? [AdminRole::ALL_DOMAINS] : $domains;

        foreach ($rows as $domain) {
            $this->connection->execute(
                'INSERT INTO `domainadmin` (`domain_name`, `adminuser`) VALUES (:domain, :username)',
                ['domain' => $domain, 'username' => $username],
            );
        }
    }

    /**
     * The `domainadmin` rows of several administrators at once, each marked with
     * whether the domain still exists.
     *
     * @param list<string> $usernames
     *
     * @return array<string, array{all: bool, domains: list<string>, orphaned: list<string>}>
     */
    private function assignmentsFor(array $usernames): array
    {
        if ($usernames === []) {
            return [];
        }

        [$placeholders, $bindings] = self::inList(array_values(array_unique($usernames)));

        $rows = $this->connection->select(
            'SELECT da.`adminuser`, da.`domain_name`, d.`domain_name` AS `known` '
            . 'FROM `domainadmin` da '
            . 'LEFT JOIN `domain` d ON d.`domain_name` = da.`domain_name` '
            . sprintf('WHERE da.`adminuser` IN (%s)', $placeholders),
            $bindings,
        );

        /** @var array<string, array{all: bool, domains: list<string>, orphaned: list<string>}> $assignments */
        $assignments = [];

        foreach ($rows as $row) {
            $admin = self::text($row['adminuser'] ?? null);
            $domain = trim(self::text($row['domain_name'] ?? null));

            $assignments[$admin] ??= ['all' => false, 'domains' => [], 'orphaned' => []];

            if ($domain === '') {
                continue;
            }

            if ($domain === AdminRole::ALL_DOMAINS) {
                $assignments[$admin]['all'] = true;

                continue;
            }

            if (!in_array($domain, $assignments[$admin]['domains'], true)) {
                $assignments[$admin]['domains'][] = $domain;

                if (($row['known'] ?? null) === null) {
                    $assignments[$admin]['orphaned'][] = $domain;
                }
            }
        }

        foreach ($assignments as $admin => $assignment) {
            sort($assignment['domains']);
            sort($assignment['orphaned']);
            $assignments[$admin] = $assignment;
        }

        return $assignments;
    }

    /**
     * @param array<string, mixed>                                                        $row
     * @param array<string, array{all: bool, domains: list<string>, orphaned: list<string>}> $assignments
     */
    private static function toAccount(array $row, array $assignments): AdminAccount
    {
        $username = self::text($row['username'] ?? null);
        $assignment = $assignments[$username] ?? ['all' => false, 'domains' => [], 'orphaned' => []];

        return new AdminAccount(
            username: $username,
            role: AdminRole::fromLegacyType(self::number($row['type'] ?? null)),
            allDomains: $assignment['all'],
            domains: $assignment['domains'],
            orphanedDomains: $assignment['orphaned'],
            // The hash is turned into a format here and goes no further. It is
            // read because U-22 wants the legacy formats visible, not because
            // anything downstream needs the value.
            passwordFormat: PasswordFormatDetector::detect(self::text($row['password'] ?? null)),
        );
    }

    /**
     * A bound `IN` list. Never interpolated values — only the placeholders, which
     * this class generates and the caller cannot influence.
     *
     * @param list<string> $values
     *
     * @return array{string, array<string, string>}
     */
    private static function inList(array $values): array
    {
        $placeholders = [];
        $bindings = [];

        foreach ($values as $index => $value) {
            $name = self::NAME . $index;
            $placeholders[] = ':' . $name;
            $bindings[$name] = $value;
        }

        return [implode(', ', $placeholders), $bindings];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
