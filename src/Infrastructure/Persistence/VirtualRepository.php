<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use Mailadmin\Application\Address\AddressStore;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Infrastructure\Persistence\Criteria\SortAllowlist;

/**
 * `virtual`, for A3 (architecture 3.4).
 *
 * **The legacy table, untouched.** No column is added, none is changed, and
 * every statement writes exactly the four fields Postfix's and Salt's queries
 * read — CLAUDE.md, rule one. The table is MyISAM (O-11): `transactional()`
 * groups the statements, but a statement that ran stays; the use cases set
 * back what an audit failure leaves (U-105).
 *
 * **Matching a row exactly.** The table has no key, so {@see remove()} and
 * {@see count()} compare all four fields. They compare **characters, not
 * bytes of the column**: `CONVERT(… USING utf8mb4) COLLATE utf8mb4_bin` is
 * what the connection hands PHP, whatever charset the table has (latin1 in
 * every installation seen so far). A `CAST(… AS BINARY)` would compare the
 * table's latin1 bytes with the parameter's UTF-8 bytes and miss every row
 * with a character beyond ASCII. The case-insensitive `alias = :…` before it
 * is there for the index.
 *
 * `dest` is nullable (`longtext`); a `NULL` reads as `''` and is matched as
 * `''`, which is what {@see VirtualRow} carries.
 *
 * Native prepares refuse a placeholder used twice (U-60): every occurrence has
 * its own name.
 */
final class VirtualRepository extends Repository implements AddressStore
{
    private const string COLUMNS = "v.alias, COALESCE(v.dest, '') AS dest, v.username, v.status";

    /** How many names go into one `IN (…)` list. */
    private const int CHUNK = 200;

    public function rowsOfDomain(DomainName $domain): array
    {
        $suffix = '@' . $domain->toString();

        // 1. the domain's addresses, aliases, catchall, fqun forwards;
        // 2. a bare key that names an account of the domain — without regard
        //    to case, the way Postfix looks it up, and whatever `username`
        //    holds: the key routes that account's mail (U-126);
        // 3. a bare key that names no account at all, with a sender of the
        //    domain. A key that names an account of another domain belongs
        //    there, under 2., and is not shown here.
        // `accountuser.username` is binary; both sides are converted before
        // LOWER() and compared under a binary collation, so that the match
        // is exactly "the same apart from case".
        return $this->fetchAll(
            new ListQuery(
                select: self::COLUMNS,
                from: '`virtual` v',
                where: '(CHAR_LENGTH(v.alias) >= :min AND RIGHT(v.alias, :tail) = :suffix)'
                    . " OR (LOCATE('@', v.alias) = 0 AND " . self::lowered('v.alias') . ' IN '
                    . '(SELECT ' . self::lowered('a1.username') . ' FROM `accountuser` a1 WHERE a1.domain_name = :key_domain))'
                    . " OR (LOCATE('@', v.alias) = 0 AND v.username <> '' AND CAST(v.username AS BINARY) IN "
                    . '(SELECT CAST(a2.username AS BINARY) FROM `accountuser` a2 WHERE a2.domain_name = :account_domain) '
                    . 'AND NOT EXISTS (SELECT 1 FROM `accountuser` a3 WHERE '
                    . self::lowered('a3.username') . ' = ' . self::lowered('v.alias') . '))',
                bindings: [
                    'min' => strlen($suffix),
                    'tail' => strlen($suffix),
                    'suffix' => $suffix,
                    'key_domain' => $domain->toString(),
                    'account_domain' => $domain->toString(),
                ],
            ),
            self::toRow(...),
        );
    }

    public function rowsWithAlias(string $alias): array
    {
        return $this->fetchAll(
            new ListQuery(
                select: self::COLUMNS,
                from: '`virtual` v',
                where: 'v.alias = :alias',
                bindings: ['alias' => $alias],
            ),
            self::toRow(...),
        );
    }

    public function forwardRowsOf(string $storedUsername): array
    {
        return $this->fetchAll(
            new ListQuery(
                select: self::COLUMNS,
                from: '`virtual` v',
                where: "v.alias = :alias_ci AND CONVERT(v.alias USING utf8mb4) COLLATE utf8mb4_bin = :alias AND v.username = ''",
                bindings: ['alias_ci' => $storedUsername, 'alias' => $storedUsername],
            ),
            self::toRow(...),
        );
    }

    public function accountDomains(array $usernames): array
    {
        $wanted = array_values(array_unique(array_map(
            static fn (string $name): string => strtolower(trim($name)),
            array_filter($usernames, static fn (string $name): bool => trim($name) !== ''),
        )));

        $found = [];

        foreach (array_chunk($wanted, self::CHUNK) as $chunk) {
            $placeholders = [];
            $bindings = [];

            foreach ($chunk as $index => $name) {
                $placeholders[] = ':name' . $index;
                $bindings['name' . $index] = $name;
            }

            $rows = $this->connection->select(
                'SELECT username, domain_name FROM `accountuser` WHERE LOWER(username) IN ('
                . implode(', ', $placeholders) . ')',
                $bindings,
            );

            foreach ($rows as $row) {
                try {
                    $found[self::text($row, 'username')] = DomainName::fromStoredValue(self::text($row, 'domain_name'));
                } catch (DomainError) {
                    // The Cyrus administrator's row: no domain, no account.
                }
            }
        }

        return $found;
    }

    public function addressTaken(string $address): bool
    {
        $taken = $this->connection->selectValue(
            'SELECT EXISTS(SELECT 1 FROM `virtual` WHERE alias = :alias) '
            . 'OR EXISTS(SELECT 1 FROM `accountuser` WHERE LOWER(username) = LOWER(:username))',
            ['alias' => $address, 'username' => $address],
        );

        return is_numeric($taken) && (int) $taken > 0;
    }

    public function isAccountName(string $name): bool
    {
        $taken = $this->connection->selectValue(
            'SELECT EXISTS(SELECT 1 FROM `accountuser` WHERE LOWER(username) = LOWER(:username))',
            ['username' => $name],
        );

        return is_numeric($taken) && (int) $taken > 0;
    }

    public function insert(VirtualRow $row): void
    {
        $this->connection->execute(
            'INSERT INTO `virtual` (alias, dest, username, status) VALUES (:alias, :dest, :username, :status)',
            ['alias' => $row->alias, 'dest' => $row->dest, 'username' => $row->username, 'status' => $row->status],
        );
    }

    public function remove(VirtualRow $row): bool
    {
        return $this->connection->execute(
            'DELETE FROM `virtual` WHERE ' . self::EXACT . ' LIMIT 1',
            self::exactBindings($row),
        ) > 0;
    }

    public function rewrite(VirtualRow $row, string $dest, string $username): bool
    {
        return $this->connection->execute(
            'UPDATE `virtual` SET dest = :new_dest, username = :new_username WHERE ' . self::EXACT . ' LIMIT 1',
            ['new_dest' => $dest, 'new_username' => $username] + self::exactBindings($row),
        ) > 0;
    }

    public function count(VirtualRow $row): int
    {
        $count = $this->connection->selectValue(
            'SELECT COUNT(*) FROM `virtual` WHERE ' . self::EXACT,
            self::exactBindings($row),
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function atomically(callable $work): mixed
    {
        return $this->transactional(static fn (): mixed => $work());
    }

    protected function sortable(): SortAllowlist
    {
        // Nothing is listed through fetchPage(): the type of a row is decided
        // after the read, so ListDomainAddresses sorts and pages in PHP. This
        // is the order the rows are read in.
        return new SortAllowlist(['alias' => 'v.alias'], default: 'alias');
    }

    /**
     * `$column` in lower case, compared as characters and nothing else.
     */
    private static function lowered(string $column): string
    {
        return 'LOWER(CONVERT(' . $column . ' USING utf8mb4)) COLLATE utf8mb4_bin';
    }

    private const string EXACT = 'alias = :alias_ci'
        . ' AND CONVERT(alias USING utf8mb4) COLLATE utf8mb4_bin = :alias'
        . " AND CONVERT(COALESCE(dest, '') USING utf8mb4) COLLATE utf8mb4_bin = :dest"
        . ' AND CONVERT(username USING utf8mb4) COLLATE utf8mb4_bin = :username'
        . ' AND status = :status';

    /**
     * @return array<string, int|string>
     */
    private static function exactBindings(VirtualRow $row): array
    {
        return [
            'alias_ci' => $row->alias,
            'alias' => $row->alias,
            'dest' => $row->dest,
            'username' => $row->username,
            'status' => $row->status,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toRow(array $row): VirtualRow
    {
        /** @var mixed $status */
        $status = $row['status'] ?? 0;

        return new VirtualRow(
            self::text($row, 'alias'),
            self::text($row, 'dest'),
            self::text($row, 'username'),
            is_numeric($status) ? (int) $status : 0,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function text(array $row, string $column): string
    {
        /** @var mixed $value */
        $value = $row[$column] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
