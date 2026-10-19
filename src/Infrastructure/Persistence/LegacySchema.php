<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

/**
 * The part of the database that is not ours (`CLAUDE.md`, rule 1).
 *
 * Postfix reads `domain`, `virtual` and the view `validsenders` at delivery
 * time. `saslauthd` / `pam_mysql` authenticates against the view `allusers`.
 * Salt creates both views and reads `lists`. A schema change here does not
 * break the interface — it breaks **delivery and login**.
 *
 * The list exists so that {@see Migration\AdditiveOnlyRule} can enforce the rule
 * instead of trusting that everyone remembers it. It names more than the seven
 * tables `CLAUDE.md` mentions: the dead ones (`alias`, `search`, `log`,
 * `users`, `groups`, `sentmails`) are in it too. They are unused today, but
 * "unused" is a fact about the installation we looked at, not a licence, and a
 * migration has no reason to drop them either.
 */
final class LegacySchema
{
    /** Everything the mail stack reads or writes. New tables carry a prefix instead. */
    public const string PREFIX = 'mailadmin_';

    /**
     * Tables webcyradm created. Semantically frozen.
     *
     * @var list<string>
     */
    public const array TABLES = [
        'accountuser',
        'adminuser',
        'alias',
        'domain',
        'domainadmin',
        'groups',
        'lists',
        'log',
        'search',
        'sentmails',
        'settings',
        'users',
        'virtual',
    ];

    /**
     * Views Salt owns. The application reads `accountuser` directly instead of
     * `allusers` (architecture 3.3) and never writes either of them.
     *
     * @var list<string>
     */
    public const array VIEWS = [
        'allusers',
        'validsenders',
    ];

    /**
     * Legacy columns a migration is allowed to make **wider**, with the width
     * webcyradm declared.
     *
     * There is exactly one reason for this list to exist. `VARCHAR(50)` holds
     * the two hash formats webcyradm produced — DES-crypt at 13 characters and
     * SHA1 hex at 40 — and nothing the new password handling produces: bcrypt
     * needs 60, Argon2id about 97, and sha512crypt 106. In MariaDB's strict
     * mode the insert is rejected; in a non-strict installation it is truncated
     * silently, which stores a hash that can never verify again. Either way the
     * chain `password_hash()` -> `accountuser` -> `allusers` -> `pam_mysql` ->
     * `crypt(3)` cannot be walked until the columns are wider.
     *
     * **Widening is not one of the three forbidden things.** `CLAUDE.md` rule 1
     * forbids `DROP`, `RENAME` and narrowing; a longer `VARCHAR` is none of
     * them, and neither Postfix nor `pam_mysql` reads the declared width. The
     * entry is nevertheless enumerated rather than allowed in general, and
     * {@see Migration\AdditiveOnlyRule} additionally insists that the new width
     * exceed the one recorded here — so the exception cannot become a way to
     * shorten a column or to reshape a different one.
     *
     * @var array<string, int> `table.column` => the width webcyradm declared
     */
    public const array WIDENABLE_COLUMNS = [
        'accountuser.password' => 50,
        'adminuser.password' => 50,
    ];

    private function __construct()
    {
    }

    /**
     * The width webcyradm declared for a column that may be widened, or `null`
     * when this column is not one of them.
     */
    public static function declaredWidth(string $table, string $column): ?int
    {
        $key = self::bare($table) . '.' . self::bare($column);

        return self::WIDENABLE_COLUMNS[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function protectedNames(): array
    {
        return [...self::TABLES, ...self::VIEWS];
    }

    public static function isProtected(string $name): bool
    {
        return in_array(self::bare($name), self::protectedNames(), true);
    }

    /**
     * Is this a table this project may create?
     */
    public static function isOwned(string $name): bool
    {
        return str_starts_with(self::bare($name), self::PREFIX);
    }

    /**
     * An identifier as written in SQL, reduced to the name itself.
     */
    private static function bare(string $identifier): string
    {
        return strtolower(trim($identifier, '`" '));
    }
}
