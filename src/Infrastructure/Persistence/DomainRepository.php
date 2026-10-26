<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Application\Domain\DomainSummary;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\AccountLimit;
use Mailadmin\Domain\Model\DefaultFolders;
use Mailadmin\Domain\Model\Domain;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\DomainRewrite;
use Mailadmin\Domain\Model\DottedNamespace;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterAllowlist;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterComparison;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterField;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortAllowlist;
use Mailadmin\Runtime\Clock;

/**
 * `domain`, `mailadmin_domain_setting`, and the four tables a rename drags along.
 *
 * ## Two tables, two statements, no join
 *
 * `domain` is `latin1` (webcyradm created it that way) and
 * `mailadmin_domain_setting` is `utf8mb4`. Joining them means an implicit
 * conversion on every row, which makes the index on one side unusable. Domain
 * names are punycode ASCII in both, so the values are identical either way —
 * the settings are therefore fetched with a second statement keyed by name, once
 * per page rather than once per row.
 *
 * ## Reading tolerates what writing does not
 *
 * Every value that comes back from `domain` went through webcyradm, or through
 * whatever an administrator typed into it in 2009. A folder name with an `@` in
 * it, a negative `maxaccounts`, a name with an underscore: none of them can be
 * *created* through this application, and all of them have to be *shown* (U-22).
 * So the mapping repairs what it can locally — an unusable folder entry is
 * dropped, a negative number is read as zero — and lets a row that cannot be
 * represented at all fail loudly rather than appear as something it is not.
 *
 * ## The rename is here because it is one transaction
 *
 * {@see rename()} is the largest statement group in the application: five tables,
 * and every one of them stores the domain name in a different shape. It belongs
 * to the repository rather than to the use case because it has to be atomic, and
 * because the use case's job is the *order* around it — mailboxes first, then
 * this (architecture 8.1).
 *
 * **The caveat is O-11.** If the legacy tables are still MyISAM, this method's
 * transaction does nothing at all, silently, and a failure part way through
 * leaves the five tables disagreeing. `bin/db-check.php` reports the engine;
 * nothing here can.
 */
final class DomainRepository extends Repository implements DomainStore
{
    private const string SETTINGS = 'mailadmin_domain_setting';

    /** U-127: one row per account that was ever disabled, keyed by the stored user name. */
    private const string ACCOUNT_STATUS = 'mailadmin_account_status';

    /**
     * The one lock for the namespace of Cyrus user names (U-97). Account
     * creation takes it through {@see withNamespaceLock()} as well, so the name
     * lives here and nowhere else.
     */
    public const string NAMESPACE_LOCK = 'mailadmin.namespace';

    /** Every column of `domain`, plus the one number that is not in it. */
    private const string COLUMNS = 'd.domain_name, d.prefix, d.maxaccounts, d.quota, d.domainquota, '
        . 'd.transport, d.freenames, d.freeaddress, d.folders, '
        . '(SELECT COUNT(*) FROM `accountuser` a WHERE a.domain_name = d.domain_name) AS account_count';

    public function __construct(Connection $connection, private readonly Clock $clock)
    {
        parent::__construct($connection);
    }

    /**
     * The `sort` values this resource offers — the same list the contract
     * enumerates, which a contract test compares.
     *
     * Only columns of `domain`. `account_count` is a subquery, and offering it
     * would turn every page into a scan of `accountuser`.
     *
     * The default doubles as the tiebreaker and is the primary key, so a page
     * boundary can never split two equal rows and show one of them twice.
     */
    public static function sortAllowlist(): SortAllowlist
    {
        return new SortAllowlist(
            [
                'name' => 'd.domain_name',
                'max_accounts' => 'd.maxaccounts',
                'default_account_quota' => 'd.quota',
                'domain_quota' => 'd.domainquota',
            ],
            default: 'name',
        );
    }

    public static function filterAllowlist(): FilterAllowlist
    {
        return new FilterAllowlist(
            new FilterField('name', 'd.domain_name', FilterComparison::Contains),
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

    public function list(ActorContext $actor, ListCriteria $criteria): Page
    {
        $query = new ListQuery(select: self::COLUMNS, from: '`domain` d');

        return $this->withSettings($this->fetchPage(
            $this->scopedTo($query, $actor, 'd.domain_name'),
            $criteria,
            $this->toSummary(...),
        ));
    }

    public function find(DomainName $name): ?DomainSummary
    {
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM `domain` d WHERE d.domain_name = :name',
            ['name' => $name->toString()],
        );

        if ($row === null) {
            return null;
        }

        $summary = $this->toSummary($row);
        $schemes = $this->schemesFor([$summary->name()->toString()]);

        return self::withScheme($summary, $schemes);
    }

    public function exists(DomainName $name): bool
    {
        return $this->countOf(
            'SELECT COUNT(*) FROM `domain` WHERE domain_name = :name',
            ['name' => $name->toString()],
        ) > 0;
    }

    public function prefixTaken(string $prefix): bool
    {
        return $this->countOf(
            'SELECT COUNT(*) FROM `domain` WHERE prefix = :prefix',
            ['prefix' => $prefix],
        ) > 0;
    }

    public function create(Domain $domain): void
    {
        $this->transactional(function () use ($domain): void {
            $this->connection->execute(
                'INSERT INTO `domain` '
                . '(domain_name, prefix, maxaccounts, quota, domainquota, transport, '
                . ' freenames, freeaddress, folders) '
                . 'VALUES (:name, :prefix, :maxaccounts, :quota, :domainquota, :transport, '
                . ' :freenames, :freeaddress, :folders)',
                [
                    'name' => $domain->name->toString(),
                    'prefix' => $domain->legacyPrefix,
                    'maxaccounts' => $domain->maxAccounts->toLegacyValue(),
                    'quota' => $domain->defaultAccountQuota->toLegacyKibibytes(),
                    'domainquota' => $domain->domainQuota->toLegacyKibibytes(),
                    'transport' => $domain->transport,
                    'freenames' => self::yesNoValue($domain->freeNames),
                    'freeaddress' => self::yesNoValue($domain->freeAddress),
                    'folders' => $domain->defaultFolders->toLegacyString(),
                ],
            );

            $this->writeSetting($domain);
        });
    }

    public function update(Domain $domain): void
    {
        $this->transactional(function () use ($domain): void {
            // `prefix` is deliberately absent: it is assigned once, at creation,
            // and a rename does not change it either (U-46).
            $this->connection->execute(
                'UPDATE `domain` SET maxaccounts = :maxaccounts, quota = :quota, '
                . 'domainquota = :domainquota, freenames = :freenames, '
                . 'freeaddress = :freeaddress, folders = :folders '
                . 'WHERE domain_name = :name',
                [
                    'name' => $domain->name->toString(),
                    'maxaccounts' => $domain->maxAccounts->toLegacyValue(),
                    'quota' => $domain->defaultAccountQuota->toLegacyKibibytes(),
                    'domainquota' => $domain->domainQuota->toLegacyKibibytes(),
                    'freenames' => self::yesNoValue($domain->freeNames),
                    'freeaddress' => self::yesNoValue($domain->freeAddress),
                    'folders' => $domain->defaultFolders->toLegacyString(),
                ],
            );

            $this->writeSetting($domain);
        });
    }

    public function delete(DomainName $name): array
    {
        return $this->transactional(function () use ($name): array {
            $rows = $this->connection->select(
                'SELECT DISTINCT adminuser FROM `domainadmin` WHERE domain_name = :name ORDER BY adminuser',
                ['name' => $name->toString()],
            );

            // Assignments first (see the interface): a failure after this
            // statement leaves a domain nobody is assigned to, never an
            // assignment without a domain.
            $this->connection->execute(
                'DELETE FROM `domainadmin` WHERE domain_name = :name',
                ['name' => $name->toString()],
            );

            $this->connection->execute(
                'DELETE FROM `domain` WHERE domain_name = :name',
                ['name' => $name->toString()],
            );

            $this->connection->execute(
                'DELETE FROM `' . self::SETTINGS . '` WHERE domain_name = :name',
                ['name' => $name->toString()],
            );

            $admins = [];

            foreach ($rows as $row) {
                $admin = self::text($row, 'adminuser');

                if ($admin !== '') {
                    $admins[] = $admin;
                }
            }

            return $admins;
        });
    }

    public function addressCount(DomainName $name): int
    {
        $suffix = '@' . $name->toString();
        $length = strlen($suffix);

        // RIGHT() rather than LIKE, as in renameAliases(); `alias` is not a
        // binary column, so the comparison is case insensitive. One placeholder
        // per occurrence: native prepares refuse a name used twice.
        return $this->countOf(
            'SELECT COUNT(*) FROM `virtual` WHERE CHAR_LENGTH(alias) >= :min AND RIGHT(alias, :tail) = :suffix',
            ['min' => $length, 'tail' => $length, 'suffix' => $suffix],
        );
    }

    public function namespaceClaimant(DomainName $name, ?DomainName $except = null): ?string
    {
        $parents = array_values(array_filter(
            DottedNamespace::parentsOf($name),
            static fn (DomainName $parent): bool => $except === null || !$parent->equals($except),
        ));

        if ($parents === []) {
            return null;
        }

        $suffix = '.' . strtolower($name->toString());
        $placeholders = [];
        $bindings = ['min' => strlen($suffix), 'tail' => strlen($suffix), 'suffix' => $suffix];

        foreach ($parents as $index => $parent) {
            $placeholders[] = ':parent' . $index;
            $bindings['parent' . $index] = $parent->toString();
        }

        // `username` has a binary collation (`latin1_bin`: it holds the bytes
        // Cyrus knows the mailbox by). Converted before LOWER(), so that the
        // comparison stays case-insensitive even where the column is a true
        // binary type. A name with an `@` is in the fqun scheme and ambiguous
        // with nothing.
        /** @var mixed $username */
        $username = $this->connection->selectValue(
            'SELECT username FROM `accountuser` '
            . 'WHERE domain_name IN (' . implode(', ', $placeholders) . ') '
            . "AND LOCATE('@', username) = 0 "
            . 'AND CHAR_LENGTH(username) > :min '
            . 'AND LOWER(CONVERT(RIGHT(username, :tail) USING utf8mb4)) = :suffix '
            . 'ORDER BY username LIMIT 1',
            $bindings,
        );

        if (is_string($username) && $username !== '') {
            return $username;
        }

        // A list of a parent domain claims the namespace by its key,
        // `REPLACE(address, '@', '.')` (U-145). `lists.address` compares
        // without case; the domain part is everything after the last `@`.
        $listBindings = $bindings;

        foreach ($parents as $index => $parent) {
            $listBindings['parent' . $index] = strtolower($parent->toString());
        }

        /** @var mixed $address */
        $address = $this->connection->selectValue(
            'SELECT address FROM `lists` '
            . "WHERE LOWER(SUBSTRING_INDEX(address, '@', -1)) IN (" . implode(', ', $placeholders) . ') '
            . 'AND CHAR_LENGTH(address) > :min '
            . "AND LOWER(RIGHT(REPLACE(address, '@', '.'), :tail)) = :suffix "
            . 'ORDER BY address LIMIT 1',
            $listBindings,
        );

        return is_string($address) && $address !== '' ? $address : null;
    }

    public function accountUsernameTaken(string $username, DomainName $except): bool
    {
        return $this->countOf(
            'SELECT COUNT(*) FROM `accountuser` '
            . 'WHERE LOWER(CONVERT(username USING utf8mb4)) = :username AND domain_name <> :except',
            ['username' => strtolower($username), 'except' => $except->toString()],
        ) > 0;
    }

    public function aliasTaken(string $alias): bool
    {
        // `alias` is not binary: the collation compares without case, which is
        // also how Postfix's lookup will match it.
        return $this->countOf(
            'SELECT COUNT(*) FROM `virtual` WHERE alias = :alias',
            ['alias' => $alias],
        ) > 0;
    }

    public function senderNameTaken(string $username): bool
    {
        // Not binary either: Postfix's `validsenders` join compares the way the
        // collation does.
        return $this->countOf(
            'SELECT COUNT(*) FROM `virtual` WHERE username = :username',
            ['username' => $username],
        ) > 0;
    }

    public function assignmentCount(DomainName $name): int
    {
        return $this->countOf(
            'SELECT COUNT(*) FROM `domainadmin` WHERE domain_name = :name',
            ['name' => $name->toString()],
        );
    }

    public function withNamespaceLock(callable $work): mixed
    {
        return NamedLock::hold($this->connection, self::NAMESPACE_LOCK, 'namespace lock', $work);
    }

    public function accountUsernames(DomainName $domain): array
    {
        $rows = $this->connection->select(
            'SELECT username FROM `accountuser` WHERE domain_name = :domain ORDER BY username',
            ['domain' => $domain->toString()],
        );

        $names = [];

        foreach ($rows as $row) {
            $name = self::text($row, 'username');

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function rename(DomainName $from, DomainName $to, array $renamedAccounts): void
    {
        $rewrite = new DomainRewrite($from, $to);

        $this->transactional(function () use ($from, $to, $renamedAccounts, $rewrite): void {
            // InnoDB first (U-129): the new names carry the status before any
            // MyISAM row moves. Usually a no-op — the caller carried it
            // already, outside this transaction.
            $this->carryAccountStatus($renamedAccounts);

            $this->connection->execute(
                'UPDATE `domain` SET domain_name = :to WHERE domain_name = :from',
                ['to' => $to->toString(), 'from' => $from->toString()],
            );

            foreach ($renamedAccounts as $old => $new) {
                // `accountuser.username` has a binary collation, so this matches
                // the stored bytes exactly — which is why the caller hands over
                // the stored name rather than a regenerated one.
                $this->connection->execute(
                    'UPDATE `accountuser` SET username = :new, domain_name = :to WHERE username = :old',
                    ['new' => $new, 'to' => $to->toString(), 'old' => $old],
                );

                $this->connection->execute(
                    'UPDATE `virtual` SET username = :new WHERE username = :old',
                    ['new' => $new, 'old' => $old],
                );

                // The status row under the old name goes only now, after the
                // account row moved (U-127, U-129): left there, it would lock
                // out whoever gets the old name next. Should this transaction
                // roll back, it comes back — and both names stay disabled.
                if ($old !== $new) {
                    $this->connection->execute(
                        'DELETE FROM `' . self::ACCOUNT_STATUS . '` WHERE username = :old',
                        ['old' => $old],
                    );
                }

                // The account's forward rows (U-101): keyed by the Cyrus user
                // name with an empty `username` (architecture 3.3). A dotted
                // name does not end in `@<domain>`, so renameAliases() would
                // not see them, and the forward would silently stop — and
                // apply again to whoever gets the old name next. Compared as
                // bytes, like AccountRepository::delete(): `Anna.example.test`
                // is not `anna.example.test`'s forward.
                $this->connection->execute(
                    'UPDATE `virtual` SET alias = :new '
                    . "WHERE CAST(alias AS BINARY) = CAST(:old AS BINARY) AND username = ''",
                    ['new' => $new, 'old' => $old],
                );
            }

            // A sweep for rows the map did not name. It should change nothing —
            // the use case refuses the rename when an account cannot be mapped —
            // but leaving an `accountuser` row pointing at a domain that no
            // longer exists would break the `allusers` view, and the view is not
            // ours to break (CLAUDE.md, rule 1).
            $this->connection->execute(
                'UPDATE `accountuser` SET domain_name = :to WHERE domain_name = :from',
                ['to' => $to->toString(), 'from' => $from->toString()],
            );

            $this->renameAliases($rewrite);
            $this->renameDestinations($rewrite, $renamedAccounts);

            $this->connection->execute(
                'UPDATE `domainadmin` SET domain_name = :to WHERE domain_name = :from',
                ['to' => $to->toString(), 'from' => $from->toString()],
            );

            $this->connection->execute(
                'UPDATE `' . self::SETTINGS . '` SET domain_name = :to WHERE domain_name = :from',
                ['to' => $to->toString(), 'from' => $from->toString()],
            );
        });
    }

    public function carryAccountStatus(array $renamedAccounts): void
    {
        foreach ($renamedAccounts as $old => $new) {
            $old = (string) $old;

            // `username` has the collation latin1_bin: these compare bytes,
            // like the primary key of `accountuser`.
            if ($old === $new) {
                continue;
            }

            $carried = $this->connection->execute(
                'INSERT INTO `' . self::ACCOUNT_STATUS . '` '
                . '(username, disabled, disabled_at, disabled_by, updated_at, updated_by) '
                . 'SELECT :new, disabled, disabled_at, disabled_by, updated_at, updated_by '
                . 'FROM `' . self::ACCOUNT_STATUS . '` WHERE username = :old '
                . 'ON DUPLICATE KEY UPDATE disabled = VALUES(disabled), disabled_at = VALUES(disabled_at), '
                . 'disabled_by = VALUES(disabled_by), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
                ['new' => $new, 'old' => $old],
            );

            if ($carried > 0) {
                continue;
            }

            // No status to carry, but a row under the new name — left by an
            // account deleted outside mailadmin. Left there, it would decide
            // the moving account's status.
            $hasOwn = $this->countOf(
                'SELECT COUNT(*) FROM `' . self::ACCOUNT_STATUS . '` WHERE username = :old',
                ['old' => $old],
            ) > 0;

            if (!$hasOwn) {
                $this->connection->execute(
                    'DELETE FROM `' . self::ACCOUNT_STATUS . '` WHERE username = :new',
                    ['new' => $new],
                );
            }
        }
    }

    public function atomically(callable $work): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $work());
    }

    /**
     * `virtual.alias` — an address or a catchall, both ending in `@<domain>`.
     *
     * Matched with `RIGHT()` rather than `LIKE`, so that a domain name
     * containing `%` or `_` cannot turn into a wildcard. The column is not
     * binary, so the comparison is case insensitive, which is what a mixed-case
     * legacy row needs.
     */
    private function renameAliases(DomainRewrite $rewrite): void
    {
        $suffix = $rewrite->oldAliasSuffix();
        $length = strlen($suffix);

        // One placeholder per occurrence: native prepares refuse a name used twice.
        $this->connection->execute(
            'UPDATE `virtual` SET alias = CONCAT(LEFT(alias, CHAR_LENGTH(alias) - :cut), :new) '
            . 'WHERE CHAR_LENGTH(alias) >= :min AND RIGHT(alias, :tail) = :old',
            [
                'cut' => $length,
                'min' => $length,
                'tail' => $length,
                'new' => $rewrite->newAliasSuffix(),
                'old' => $suffix,
            ],
        );
    }

    /**
     * `virtual.dest` — the one column a rename cannot rewrite in SQL.
     *
     * It is a comma separated list whose entries are either addresses or Cyrus
     * user names, and a `dotted` user name cannot be taken apart (architecture
     * 3.3). {@see DomainRewrite} decides which entries are unambiguous; this
     * method only finds the candidates and writes back the ones that changed.
     *
     * The candidate query is deliberately loose — anything mentioning the old
     * name — and the decision is deliberately strict. Reading a few rows too
     * many costs a scan on a table that is read once per rename; rewriting one
     * row too many delivers somebody's mail to the wrong mailbox.
     *
     * The `UPDATE` matches on the whole old value, so every row that shared it
     * gets the same new one. That is correct by construction: the same input
     * always produces the same output.
     *
     * @param array<string, string> $renamedAccounts
     */
    private function renameDestinations(DomainRewrite $rewrite, array $renamedAccounts): void
    {
        $rows = $this->connection->select(
            'SELECT DISTINCT dest FROM `virtual` WHERE dest LIKE :pattern ESCAPE \'\\\\\'',
            ['pattern' => '%' . self::escapeLike($rewrite->from->toString()) . '%'],
        );

        foreach ($rows as $row) {
            $current = self::text($row, 'dest');

            if ($current === '') {
                continue;
            }

            $rewritten = $rewrite->destinations($current, $renamedAccounts);

            if ($rewritten === null) {
                continue;
            }

            $this->connection->execute(
                'UPDATE `virtual` SET dest = :new WHERE dest = :old',
                ['new' => $rewritten, 'old' => $current],
            );
        }
    }

    private function writeSetting(Domain $domain): void
    {
        if ($domain->namingScheme === null) {
            // "Follow the global default" is the absence of a row, not a row
            // saying nothing. Keeping the table to the domains that actually
            // say something means a reader can tell the two apart at a glance.
            $this->connection->execute(
                'DELETE FROM `' . self::SETTINGS . '` WHERE domain_name = :name',
                ['name' => $domain->name->toString()],
            );

            return;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->connection->execute(
            'INSERT INTO `' . self::SETTINGS . '` (domain_name, naming_scheme, created_at, updated_at) '
            // Native prepares (EMULATE_PREPARES = false) refuse a named
            // placeholder used twice, hence :created and :updated.
            . 'VALUES (:name, :scheme, :created, :updated) '
            . 'ON DUPLICATE KEY UPDATE naming_scheme = VALUES(naming_scheme), updated_at = VALUES(updated_at)',
            [
                'name' => $domain->name->toString(),
                'scheme' => $domain->namingScheme->value,
                'created' => $now,
                'updated' => $now,
            ],
        );
    }

    /**
     * @param Page<DomainSummary> $page
     *
     * @return Page<DomainSummary>
     */
    private function withSettings(Page $page): Page
    {
        if ($page->isEmpty()) {
            return $page;
        }

        $schemes = $this->schemesFor(array_map(
            static fn (DomainSummary $summary): string => $summary->name()->toString(),
            $page->items,
        ));

        return $page->map(static fn (DomainSummary $summary): DomainSummary
            => self::withScheme($summary, $schemes));
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, NamingScheme> lower-cased name => scheme, only for
     *                                     the domains that have one
     */
    private function schemesFor(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $placeholders = [];
        $bindings = [];

        foreach ($names as $index => $name) {
            $placeholder = 'name' . $index;
            $placeholders[] = ':' . $placeholder;
            $bindings[$placeholder] = $name;
        }

        $rows = $this->connection->select(
            'SELECT domain_name, naming_scheme FROM `' . self::SETTINGS . '` '
            . 'WHERE domain_name IN (' . implode(', ', $placeholders) . ')',
            $bindings,
        );

        $schemes = [];

        foreach ($rows as $row) {
            $scheme = NamingScheme::tryFrom(self::text($row, 'naming_scheme'));

            if ($scheme !== null) {
                $schemes[strtolower(self::text($row, 'domain_name'))] = $scheme;
            }
        }

        return $schemes;
    }

    /**
     * @param array<string, NamingScheme> $schemes
     */
    private static function withScheme(DomainSummary $summary, array $schemes): DomainSummary
    {
        $scheme = $schemes[strtolower($summary->name()->toString())] ?? null;

        return $scheme === null ? $summary : $summary->with($summary->domain->withNamingScheme($scheme));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws DomainError when the row cannot be represented at all
     */
    private function toSummary(array $row): DomainSummary
    {
        return new DomainSummary(
            new Domain(
                name: DomainName::fromStoredValue(self::text($row, 'domain_name')),
                maxAccounts: AccountLimit::fromLegacyValue(max(0, self::integer($row, 'maxaccounts'))),
                defaultAccountQuota: Quota::fromLegacyKibibytes(max(0, self::integer($row, 'quota'))),
                domainQuota: Quota::fromLegacyKibibytes(max(0, self::integer($row, 'domainquota'))),
                defaultFolders: self::folders(self::text($row, 'folders')),
                namingScheme: null,
                legacyPrefix: self::text($row, 'prefix'),
                transport: self::text($row, 'transport'),
                freeNames: self::isYes(self::text($row, 'freenames')),
                freeAddress: self::isYes(self::text($row, 'freeaddress')),
            ),
            max(0, self::integer($row, 'account_count')),
        );
    }

    /**
     * `domain.folders`, entry by entry, keeping what is usable.
     *
     * An entry Cyrus could not carry — one with an `@`, a control character or a
     * leading slash — is dropped rather than taken down the whole listing with
     * it. The alternative is a `500` on `GET /domains` because one domain has a
     * folder name somebody typed in 2009, and U-22 is explicit that such data is
     * shown rather than allowed to break the page.
     */
    private static function folders(string $value): DefaultFolders
    {
        $usable = [];

        foreach (explode(DefaultFolders::LEGACY_SEPARATOR, $value) as $candidate) {
            if (trim($candidate) === '') {
                continue;
            }

            try {
                DefaultFolders::of($candidate);
            } catch (DomainError) {
                continue;
            }

            $usable[] = $candidate;
        }

        return DefaultFolders::of(...$usable);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function countOf(string $sql, array $parameters): int
    {
        /** @var mixed $value */
        $value = $this->connection->selectValue($sql, $parameters);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The legacy columns are `enum('YES','NO')`. Anything else is read as "no",
     * which is the column default and the safer half of a flag nothing acts on.
     */
    private static function isYes(string $value): bool
    {
        return strcasecmp(trim($value), 'YES') === 0;
    }

    private static function yesNoValue(bool $flag): string
    {
        return $flag ? 'YES' : 'NO';
    }

    /**
     * Escape the backslash first — doing it last would double the ones this
     * call itself inserted.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function text(array $row, string $column): string
    {
        /** @var mixed $value */
        $value = $row[$column] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function integer(array $row, string $column): int
    {
        /** @var mixed $value */
        $value = $row[$column] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
