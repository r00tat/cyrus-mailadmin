<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Mailadmin\Application\Account\AccountRecord;
use Mailadmin\Application\Account\AccountStatus;
use Mailadmin\Application\Account\AccountStore;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\AccountServices;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterAllowlist;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterComparison;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterField;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortAllowlist;

/**
 * `accountuser` and the rows of `virtual` that belong to an account.
 *
 * ## The hash never leaves the database on a read
 *
 * The listing shows the **format** of every stored password and filters by it
 * (E-13). The format is recognised in SQL, by {@see PASSWORD_FORMAT}, which
 * mirrors {@see \Mailadmin\Domain\Password\PasswordFormatDetector} rule for
 * rule; `find()` uses the same expression. No read in this class selects
 * `password`, so no hash ever sits in a PHP array that a careless log line
 * could print. The integration test runs both recognisers over the same sample
 * and fails if they disagree.
 *
 * The expression works on `CAST(password AS BINARY)`, so that the regular
 * expressions are case sensitive whatever the column's collation says — the
 * PHP rules distinguish `*ABC…` (MySQL `PASSWORD()`) from lower-case hex.
 *
 * ## Writes
 *
 * Both tables are MyISAM (O-11): a statement that ran is not rolled back. The
 * transaction groups the audit row with the change; the order of architecture
 * 8.1 is what keeps a failure harmless.
 */
final class AccountRepository extends Repository implements AccountStore
{
    /**
     * The format of `a.password`, in SQL. Rule for rule
     * {@see \Mailadmin\Domain\Password\PasswordFormatDetector::detect()}, in the
     * same order.
     *
     * The regular expressions avoid backslashes (`[*]`, `[$]`, `[{]`), so that
     * they mean the same whether or not the server runs with
     * `NO_BACKSLASH_ESCAPES`.
     */
    public const string PASSWORD_FORMAT = 'CASE'
        . " WHEN CAST(COALESCE(a.password, '') AS BINARY) = '' THEN 'unknown'"
        . " WHEN LEFT(CAST(a.password AS BINARY), 10) = '\$argon2id\$' THEN 'argon2id'"
        . " WHEN LEFT(CAST(a.password AS BINARY), 4) IN ('\$2y\$', '\$2a\$', '\$2b\$', '\$2x\$') THEN 'bcrypt'"
        . " WHEN LEFT(CAST(a.password AS BINARY), 3) = '\$1\$' THEN 'md5crypt'"
        . " WHEN LEFT(CAST(a.password AS BINARY), 3) = '\$5\$' THEN 'sha256crypt'"
        . " WHEN LEFT(CAST(a.password AS BINARY), 3) = '\$6\$' THEN 'sha512crypt'"
        . " WHEN CAST(a.password AS BINARY) REGEXP '^[*][0-9A-F]{40}\$' THEN 'mysql_password'"
        . " WHEN CAST(a.password AS BINARY) REGEXP '^[0-9a-fA-F]{40}\$' THEN 'sha1hex'"
        . " WHEN CAST(a.password AS BINARY) REGEXP '^[./0-9A-Za-z]{13}\$' THEN 'descrypt'"
        . " WHEN CAST(a.password AS BINARY) REGEXP '^([\$][A-Za-z0-9-]+[\$]|[{][A-Za-z0-9-]+[}])' THEN 'unknown'"
        . " ELSE 'plaintext' END";

    /** Everything a record needs, and not the hash. */
    private const string COLUMNS = 'a.username, a.prefix, a.domain_name, a.imap, a.pop, a.sieve, a.smtpauth, '
        . self::PASSWORD_FORMAT . ' AS password_format, '
        . 'COALESCE(st.disabled, 0) AS status_disabled, st.disabled_at AS status_disabled_at, '
        . "COALESCE(st.disabled_by, '') AS status_disabled_by";

    /**
     * `accountuser` with its optional status row (U-127). The key column of
     * `mailadmin_account_status` has the legacy column's collation, so this
     * join compares the stored bytes and uses the primary key — the same join
     * the view `allusers` makes.
     */
    private const string FROM = '`accountuser` a LEFT JOIN `mailadmin_account_status` st ON st.username = a.username';

    private const string STATUS_TABLE = 'mailadmin_account_status';

    /** Server-wide `GET_LOCK` names are at most 64 characters. */
    private const string LOCK_PREFIX = 'mailadmin.account.';

    /**
     * The `sort` values this resource offers — the list the contract
     * enumerates. The default is the unique user name, so it doubles as the
     * tiebreaker.
     */
    public static function sortAllowlist(): SortAllowlist
    {
        return new SortAllowlist(
            [
                'name' => 'acc.username',
                'password_format' => 'acc.password_format',
            ],
            default: 'name',
        );
    }

    public static function filterAllowlist(): FilterAllowlist
    {
        return new FilterAllowlist(
            new FilterField('name', 'acc.username', FilterComparison::Contains),
            new FilterField('password_format', 'acc.password_format', FilterComparison::Equals),
        );
    }

    protected function sortable(): SortAllowlist
    {
        return self::sortAllowlist();
    }

    protected function filterable(): FilterAllowlist
    {
        return self::filterAllowlist();
    }

    public function list(DomainName $domain, ListCriteria $criteria): Page
    {
        // A derived table, so that the recognised format is a column the
        // filter and the sort can name like any other.
        $query = new ListQuery(
            select: 'acc.*',
            from: '(SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE a.domain_name = :account_domain) acc',
            bindings: ['account_domain' => $domain->toString()],
        );

        return $this->fetchPage($query, $criteria, fn (array $row): AccountRecord => $this->toRecord($row, $domain));
    }

    public function find(string $storedUsername): ?AccountRecord
    {
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE a.username = :username',
            ['username' => $storedUsername],
        );

        if ($row === null) {
            return null;
        }

        try {
            $domain = DomainName::fromStoredValue(self::text($row, 'domain_name'));
        } catch (DomainError) {
            // The Cyrus administrator's own row has no domain; it is not an
            // account any domain is responsible for.
            return null;
        }

        return $this->toRecord($row, $domain);
    }

    public function countIn(DomainName $domain): int
    {
        $count = $this->connection->selectValue(
            'SELECT COUNT(*) FROM `accountuser` WHERE domain_name = :domain',
            ['domain' => $domain->toString()],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function usernamesIn(DomainName $domain): array
    {
        $rows = $this->connection->select(
            'SELECT username FROM `accountuser` WHERE domain_name = :domain ORDER BY username',
            ['domain' => $domain->toString()],
        );

        return array_values(array_map(static fn (array $row): string => self::text($row, 'username'), $rows));
    }

    public function isTaken(MailboxIdentity $identity): bool
    {
        $taken = $this->connection->selectValue(
            'SELECT EXISTS(SELECT 1 FROM `accountuser` WHERE LOWER(username) = LOWER(:username)) '
            . 'OR EXISTS(SELECT 1 FROM `virtual` WHERE alias IN (:address, :forward_alias)) '
            . 'OR EXISTS(SELECT 1 FROM `virtual` WHERE username = :sender) '
            . "OR EXISTS(SELECT 1 FROM `lists` WHERE LOWER(REPLACE(address, '@', '.')) = :list_key)",
            [
                'username' => $identity->cyrusUsername(),
                'address' => $identity->address()->toString(),
                // A forward row is keyed by the Cyrus user name; one left over
                // from an earlier account would silently apply to the new one.
                'forward_alias' => $identity->cyrusUsername(),
                // `validsenders` joins `virtual.username` against
                // `accountuser`: a new account under a name a row already
                // carries there would gain that row's sending right — an
                // alias placeholder (U-106) or an earlier account's leftover.
                // The collation compares without case, as Postfix does.
                'sender' => $identity->cyrusUsername(),
                // A list's key, `REPLACE(address, '@', '.')`: in alias mode the
                // aliases file sends that name to the list, ahead of the
                // mailbox (U-145). A name with an `@` never matches.
                'list_key' => strtolower($identity->cyrusUsername()),
            ],
        );

        return is_numeric($taken) && (int) $taken === 1;
    }

    public function create(
        MailboxIdentity $identity,
        string $passwordHash,
        AccountServices $services,
        string $legacyPrefix,
    ): void {
        $username = $identity->cyrusUsername();
        $flags = $services->toLegacyFlags();

        $this->transactional(function () use ($identity, $username, $passwordHash, $flags, $legacyPrefix): void {
            $this->connection->execute(
                'INSERT INTO `accountuser` '
                . '(username, password, prefix, domain_name, imap, pop, sieve, smtpauth) '
                . 'VALUES (:username, :password, :prefix, :domain, :imap, :pop, :sieve, :smtpauth)',
                [
                    'username' => $username,
                    'password' => $passwordHash,
                    'prefix' => $legacyPrefix,
                    'domain' => $identity->domain->toString(),
                    'imap' => $flags['imap'],
                    'pop' => $flags['pop'],
                    'sieve' => $flags['sieve'],
                    'smtpauth' => $flags['smtpauth'],
                ],
            );

            $this->connection->execute(
                'INSERT INTO `virtual` (alias, dest, username, status) VALUES (:alias, :dest, :username, 1)',
                [
                    'alias' => $identity->address()->toString(),
                    'dest' => $username,
                    'username' => $username,
                ],
            );

            // A status row left by an account of the same name that webcyradm
            // deleted would lock the new account out from its first minute.
            $this->connection->execute(
                'DELETE FROM `' . self::STATUS_TABLE . '` WHERE username = :username',
                ['username' => $username],
            );
        });
    }

    public function updateServices(string $storedUsername, AccountServices $services): void
    {
        $flags = $services->toLegacyFlags();

        $this->connection->execute(
            'UPDATE `accountuser` SET imap = :imap, pop = :pop, sieve = :sieve, smtpauth = :smtpauth '
            . 'WHERE username = :username',
            [...$flags, 'username' => $storedUsername],
        );
    }

    public function setStatus(string $storedUsername, bool $enabled, string $changedBy): void
    {
        // Native prepares: no placeholder twice in one statement, hence the
        // `VALUES()` references in the update half.
        $this->connection->execute(
            'INSERT INTO `' . self::STATUS_TABLE . '` '
            . '(username, disabled, disabled_at, disabled_by, updated_at, updated_by) '
            . 'VALUES (:username, :disabled, '
            . 'CASE WHEN :disabling = 1 THEN UTC_TIMESTAMP() ELSE NULL END, '
            . ':disabled_by, UTC_TIMESTAMP(), :updated_by) '
            . 'ON DUPLICATE KEY UPDATE disabled = VALUES(disabled), disabled_at = VALUES(disabled_at), '
            . 'disabled_by = VALUES(disabled_by), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [
                'username' => $storedUsername,
                'disabled' => $enabled ? 0 : 1,
                'disabling' => $enabled ? 0 : 1,
                'disabled_by' => $enabled ? '' : $changedBy,
                'updated_by' => $changedBy,
            ],
        );
    }

    public function offersLogin(string $storedUsername, string $address): bool
    {
        $offered = $this->connection->selectValue(
            'SELECT COUNT(*) FROM `allusers` WHERE username IN (:stored, :address)',
            ['stored' => $storedUsername, 'address' => $address],
        );

        return is_numeric($offered) && (int) $offered > 0;
    }

    public function updatePasswordHash(string $storedUsername, string $passwordHash): void
    {
        $this->connection->execute(
            'UPDATE `accountuser` SET password = :password WHERE username = :username',
            ['password' => $passwordHash, 'username' => $storedUsername],
        );
    }

    public function delete(string $storedUsername, DomainName $domain): int
    {
        return $this->transactional(function () use ($storedUsername, $domain): int {
            // Compared as bytes: `virtual` is case-insensitive by collation, and
            // a row of `Anna.example.test` is not a row of `anna.example.test`.
            //
            // A row naming the account as sender goes only when it belongs to
            // the account's domain (U-129, by U-126's rule): an address after
            // its `@`, compared without case as Postfix does; a key without
            // `@` unless it names an account of another domain. A catchall or
            // alias of another domain stays as it is — its other destinations
            // are not the actor's to remove.
            $removed = $this->connection->execute(
                'DELETE FROM `virtual` '
                . 'WHERE (CAST(username AS BINARY) = CAST(:username AS BINARY) AND ('
                . "(LOCATE('@', alias) > 0 AND SUBSTRING_INDEX(alias, '@', -1) = :domain) "
                . "OR (LOCATE('@', alias) = 0 AND NOT EXISTS ("
                . 'SELECT 1 FROM `accountuser` x '
                . 'WHERE LOWER(x.username) = LOWER(`virtual`.alias) AND x.domain_name <> :other_domain)))) '
                . "OR (CAST(alias AS BINARY) = CAST(:alias AS BINARY) AND username = '')",
                [
                    'username' => $storedUsername,
                    'domain' => $domain->toString(),
                    'other_domain' => $domain->toString(),
                    'alias' => $storedUsername,
                ],
            );

            $this->connection->execute(
                'DELETE FROM `accountuser` WHERE username = :username',
                ['username' => $storedUsername],
            );

            // The status goes with the account: left behind, it would lock out
            // the next account created under the same name (U-127).
            $this->connection->execute(
                'DELETE FROM `' . self::STATUS_TABLE . '` WHERE username = :username',
                ['username' => $storedUsername],
            );

            return $removed;
        });
    }

    public function withDomainLock(DomainName $domain, callable $work): mixed
    {
        return NamedLock::hold(
            $this->connection,
            self::LOCK_PREFIX . sha1(strtolower($domain->toString())),
            sprintf('account lock of "%s"', $domain->toString()),
            $work,
        );
    }

    public function atomically(callable $work): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $work());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toRecord(array $row, DomainName $domain): AccountRecord
    {
        return AccountRecord::fromStored(
            self::text($row, 'username'),
            $domain,
            AccountServices::fromLegacyFlags(
                self::integer($row, 'imap'),
                self::integer($row, 'pop'),
                self::integer($row, 'sieve'),
                self::integer($row, 'smtpauth'),
            ),
            PasswordFormat::tryFrom(self::text($row, 'password_format')) ?? PasswordFormat::Unknown,
            self::text($row, 'prefix'),
            self::status($row),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function status(array $row): AccountStatus
    {
        if (self::integer($row, 'status_disabled') !== 1) {
            return AccountStatus::enabled();
        }

        $at = self::text($row, 'status_disabled_at');

        try {
            $moment = $at === '' ? null : new DateTimeImmutable($at, new DateTimeZone('UTC'));
        } catch (\Exception) {
            $moment = null;
        }

        return AccountStatus::disabled($moment, self::text($row, 'status_disabled_by'));
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

    /**
     * @param array<string, mixed> $row
     */
    private static function integer(array $row, string $column): int
    {
        /** @var mixed $value */
        $value = $row[$column] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
