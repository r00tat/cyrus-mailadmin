<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Migration;

use Mailadmin\Infrastructure\Persistence\LegacySchema;

/**
 * The additive-only rule (E-1, `CLAUDE.md` rule 1), turned into a check.
 *
 * Postfix, `pam_mysql` and Salt read this database while we work on it. A
 * `DROP`, a `RENAME` or a narrowed column type does not break the interface; it
 * breaks delivery and login. The rule that prevents that is written down in
 * three documents — and a rule that depends on everyone having read them is a
 * rule that holds until the first hurried afternoon. So the migrator refuses to
 * run a migration that breaks it.
 *
 * ## Default deny
 *
 * The check does not hunt for forbidden statements; it accepts a **closed set**
 * and rejects everything else. A blacklist has to anticipate
 * `CREATE OR REPLACE VIEW`, `ALTER TABLE … CONVERT TO CHARACTER SET`,
 * `RENAME TABLE`, `DELIMITER` and whatever the next dialect version adds. An
 * allowlist has to anticipate nothing.
 *
 * | Statement | Accepted when |
 * |---|---|
 * | `CREATE TABLE` | name starts with `mailadmin_`, and `IF NOT EXISTS` is present |
 * | `CREATE INDEX` | on a `mailadmin_` table, and `IF NOT EXISTS` is present |
 * | `ALTER TABLE` (own table) | only `ADD` clauses, each with `IF NOT EXISTS` |
 * | `ALTER TABLE` (legacy table) | only `ADD COLUMN … IF NOT EXISTS`, and the column has a `DEFAULT` or is `NULL` |
 * | `INSERT` | into a `mailadmin_` table, as `INSERT IGNORE` or with `ON DUPLICATE KEY UPDATE` |
 * | `UPDATE`, `DELETE` | on a `mailadmin_` table |
 * | `SELECT` | anywhere — it changes nothing |
 * | `SET` | session scope only |
 *
 * Everything else — `DROP` in any form, `RENAME`, `TRUNCATE`, view and routine
 * definitions, `GRANT`, `LOCK TABLES` — is refused, with `DROP TABLE`,
 * `DROP COLUMN` and `RENAME` named explicitly because those are the three the
 * rule is really about.
 *
 * ## Idempotency
 *
 * The `IF NOT EXISTS` requirements are not politeness. MySQL and MariaDB commit
 * DDL implicitly, so a migration cannot be wrapped in a transaction: if the
 * third of five statements fails, the first two stand and the migration is not
 * recorded. The next run repeats it from the beginning. That is only safe if
 * every statement can run twice — which is why the check insists on it rather
 * than hoping for it.
 *
 * `ADD COLUMN … IF NOT EXISTS` and `ADD INDEX … IF NOT EXISTS` are MariaDB
 * syntax. The target is MariaDB (the `mysql` container in the existing stack);
 * on MySQL 8, which does not have it, these migrations would need rewriting.
 * **Stated as an assumption**, and the reason the check names MariaDB in its
 * message.
 */
final class AdditiveOnlyRule
{
    private function __construct()
    {
    }

    /**
     * @throws MigrationError when the migration breaks the rule
     */
    public static function enforce(Migration $migration): void
    {
        $violations = self::check($migration);

        if ($violations !== []) {
            throw MigrationError::rejected($migration->fileName, $violations);
        }
    }

    /**
     * @return list<string> one message per offending statement; empty when the migration is fine
     */
    public static function check(Migration $migration): array
    {
        $violations = [];

        foreach ($migration->statements as $position => $statement) {
            $problem = self::inspect(self::normalise($statement));

            if ($problem !== null) {
                $violations[] = sprintf('statement %d: %s', $position + 1, $problem);
            }
        }

        return $violations;
    }

    /**
     * @return string|null the reason it is refused, or `null` when it is allowed
     */
    private static function inspect(string $statement): ?string
    {
        $upper = strtoupper($statement);

        return match (true) {
            self::starts($upper, 'SELECT'), self::starts($upper, 'SHOW') => null,
            self::starts($upper, 'SET') => self::inspectSet($upper),
            self::starts($upper, 'CREATE TABLE'),
            self::starts($upper, 'CREATE TEMPORARY TABLE') => self::inspectCreateTable($statement, $upper),
            self::matches($upper, '/^CREATE (UNIQUE |FULLTEXT |SPATIAL )?INDEX /') => self::inspectCreateIndex($statement, $upper),
            self::starts($upper, 'ALTER TABLE') => self::inspectAlterTable($statement, $upper),
            self::starts($upper, 'INSERT') => self::inspectInsert($statement, $upper),
            self::starts($upper, 'UPDATE') => self::inspectWrite($statement, 'UPDATE'),
            self::starts($upper, 'DELETE') => self::inspectWrite($statement, 'DELETE'),
            self::starts($upper, 'DROP TABLE') => 'DROP TABLE is never allowed. Migrations are '
                . 'additive; the way back is the database dump (E-1).',
            self::starts($upper, 'RENAME') => 'RENAME TABLE is never allowed: Postfix, pam_mysql '
                . 'and Salt address these tables by name.',
            self::starts($upper, 'DROP') => 'DROP is never allowed in a migration, in any form.',
            self::starts($upper, 'TRUNCATE') => 'TRUNCATE is never allowed: it destroys data and '
                . 'is not additive.',
            default => sprintf(
                'unsupported statement "%s". The migrator accepts CREATE TABLE, CREATE INDEX, '
                . 'ALTER TABLE … ADD, INSERT, UPDATE, DELETE, SELECT and session-scoped SET; '
                . 'everything else is refused rather than reviewed.',
                self::headKeywords($statement),
            ),
        };
    }

    /**
     * The single exception to "no MODIFY on a legacy table": making one of the
     * password columns longer.
     *
     * Three conditions have to hold together, and each closes a way the
     * exception could otherwise be turned into something else:
     *
     *  - the statement is **one** clause. `[^,]*$` refuses
     *    `MODIFY password VARCHAR(255), DROP COLUMN home` — a shape that would
     *    otherwise pass the check on its first half.
     *  - the column is one of {@see LegacySchema::WIDENABLE_COLUMNS}. Not "any
     *    VARCHAR": a wider `virtual.dest` or `domain.domain_name` changes what
     *    Postfix can be handed, and nobody has decided that.
     *  - the new width is **strictly greater** than the width webcyradm
     *    declared. This is what keeps the rule a widening rule rather than a
     *    MODIFY rule, and it is why the old widths are written down at all.
     *
     * The new type must still be `VARCHAR`. Turning the column into `TEXT` would
     * also be "wider" and would change how `pam_mysql` and the `allusers` view
     * behave; that is not a migration, that is a decision.
     */
    private static function isPermittedWidening(string $statement): bool
    {
        $pattern = '/^ALTER\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s+'
            . 'MODIFY\s+(?:COLUMN\s+)?`?([A-Za-z0-9_]+)`?\s+'
            . 'VARCHAR\s*\(\s*(\d+)\s*\)[^,]*$/i';

        if (preg_match($pattern, $statement, $matches) !== 1) {
            return false;
        }

        $declared = LegacySchema::declaredWidth($matches[1], $matches[2]);

        return $declared !== null && (int) $matches[3] > $declared;
    }

    private static function inspectSet(string $upper): ?string
    {
        if (self::matches($upper, '/^SET (GLOBAL|PERSIST|PERSIST_ONLY)\b/')) {
            return 'SET GLOBAL changes the server, not the schema. The database is shared with '
                . 'Postfix and pam_mysql (CLAUDE.md rule 1).';
        }

        return null;
    }

    private static function inspectCreateTable(string $statement, string $upper): ?string
    {
        $table = self::tableFrom(
            $statement,
            '/^CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([^\s(]+)/i',
        );

        if ($table === null) {
            return 'CREATE TABLE without a recognisable table name.';
        }

        if (!LegacySchema::isOwned($table)) {
            return sprintf(
                'CREATE TABLE %s: new tables carry the prefix "%s" (architecture 10.3). "%s" '
                . 'either belongs to the mail stack or would become a name nobody can attribute.',
                $table,
                LegacySchema::PREFIX,
                $table,
            );
        }

        if (!str_contains($upper, 'IF NOT EXISTS')) {
            return sprintf(
                'CREATE TABLE %s must say IF NOT EXISTS. DDL commits implicitly, so a migration '
                . 'that fails halfway is repeated in full on the next run.',
                $table,
            );
        }

        return null;
    }

    private static function inspectCreateIndex(string $statement, string $upper): ?string
    {
        $table = self::tableFrom(
            $statement,
            '/\bON\s+([^\s(]+)/i',
        );

        if ($table === null) {
            return 'CREATE INDEX without a recognisable table name.';
        }

        if (!LegacySchema::isOwned($table)) {
            return sprintf(
                'CREATE INDEX on %s: only tables with the prefix "%s" may be indexed by a '
                . 'migration. An index on a legacy table changes how the mail stack performs '
                . 'without anyone owning the decision.',
                $table,
                LegacySchema::PREFIX,
            );
        }

        if (!str_contains($upper, 'IF NOT EXISTS')) {
            return sprintf('CREATE INDEX on %s must say IF NOT EXISTS.', $table);
        }

        return null;
    }

    private static function inspectAlterTable(string $statement, string $upper): ?string
    {
        $table = self::tableFrom($statement, '/^ALTER\s+TABLE\s+(?:IF\s+EXISTS\s+)?([^\s(]+)/i');

        if ($table === null) {
            return 'ALTER TABLE without a recognisable table name.';
        }

        if (self::matches($upper, '/\bDROP\s+(COLUMN\b|`|[A-Z_])/')) {
            return sprintf(
                'ALTER TABLE %s … DROP COLUMN is never allowed. A column that is gone is gone '
                . 'for every reader of this database, including Postfix and pam_mysql.',
                $table,
            );
        }

        if (self::matches($upper, '/\bRENAME\b/')) {
            return sprintf('ALTER TABLE %s … RENAME is never allowed.', $table);
        }

        $protected = LegacySchema::isProtected($table);

        if ($protected && self::matches($upper, '/\b(MODIFY|CHANGE|CONVERT TO|ENGINE\s*=)\b/')) {
            if (self::isPermittedWidening($statement)) {
                return null;
            }

            return sprintf(
                'ALTER TABLE %s: MODIFY, CHANGE, CONVERT TO and ENGINE alter an existing column '
                . 'or table of the mail stack. Only ADD COLUMN with a default is additive (E-1) — '
                . 'the one exception is widening a VARCHAR listed in LegacySchema::WIDENABLE_COLUMNS.',
                $table,
            );
        }

        if (!$protected && !LegacySchema::isOwned($table)) {
            return sprintf(
                'ALTER TABLE %s: the table is neither a known legacy table nor one of ours '
                . '(prefix "%s").',
                $table,
                LegacySchema::PREFIX,
            );
        }

        if (!self::matches($upper, '/\bADD\b/')) {
            return sprintf(
                'ALTER TABLE %s: a migration may only ADD. Everything else changes something '
                . 'that already exists.',
                $table,
            );
        }

        if (!str_contains($upper, 'IF NOT EXISTS')) {
            return sprintf(
                'ALTER TABLE %s … ADD must say IF NOT EXISTS (MariaDB syntax), so that a repeated '
                . 'run of an interrupted migration succeeds.',
                $table,
            );
        }

        if ($protected && self::matches($upper, '/\bADD\s+(COLUMN\s+)?/')) {
            return self::inspectLegacyColumn($table, $upper);
        }

        return null;
    }

    /**
     * A new column on a legacy table has to have a value for the rows that are
     * already there — otherwise webcyradm's own `INSERT`, which does not know
     * the column, fails.
     */
    private static function inspectLegacyColumn(string $table, string $upper): ?string
    {
        if (self::matches($upper, '/\bADD\s+(INDEX|KEY|UNIQUE|CONSTRAINT|FOREIGN)\b/')) {
            return sprintf(
                'ALTER TABLE %s: an index or constraint on a legacy table is not additive in '
                . 'effect — a UNIQUE key can make an INSERT that webcyradm performs today fail.',
                $table,
            );
        }

        // "NOT NULL" removed first: it contains the word NULL but says the
        // opposite of what a nullable column says.
        $nullability = str_replace('NOT NULL', '', $upper);

        if (!self::matches($upper, '/\bDEFAULT\b/') && !self::matches($nullability, '/\bNULL\b/')) {
            return sprintf(
                'ALTER TABLE %s … ADD COLUMN needs a DEFAULT or must be nullable. The existing '
                . 'rows were written by webcyradm and it will keep writing them without this '
                . 'column (CLAUDE.md rule 1).',
                $table,
            );
        }

        return null;
    }

    private static function inspectInsert(string $statement, string $upper): ?string
    {
        $table = self::tableFrom(
            $statement,
            '/^INSERT\s+(?:LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+|IGNORE\s+)*(?:INTO\s+)?([^\s(]+)/i',
        );

        if ($table === null) {
            return 'INSERT without a recognisable table name.';
        }

        if (!LegacySchema::isOwned($table)) {
            return sprintf(
                'INSERT into %s: a migration seeds its own tables, never the ones the mail stack '
                . 'writes. Data in a legacy table is webcyradm\'s and the operator\'s business.',
                $table,
            );
        }

        if (!self::matches($upper, '/^INSERT\s+(LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+)*IGNORE\b/')
            && !str_contains($upper, 'ON DUPLICATE KEY UPDATE')
        ) {
            return sprintf(
                'INSERT into %s must be INSERT IGNORE or carry ON DUPLICATE KEY UPDATE, so that a '
                . 'repeated migration does not fail on a row it wrote the first time.',
                $table,
            );
        }

        return null;
    }

    private static function inspectWrite(string $statement, string $verb): ?string
    {
        $pattern = $verb === 'UPDATE'
            ? '/^UPDATE\s+(?:LOW_PRIORITY\s+|IGNORE\s+)*([^\s(]+)/i'
            : '/^DELETE\s+(?:LOW_PRIORITY\s+|QUICK\s+|IGNORE\s+)*FROM\s+([^\s(]+)/i';

        $table = self::tableFrom($statement, $pattern);

        if ($table === null) {
            return sprintf('%s without a recognisable table name.', $verb);
        }

        if (!LegacySchema::isOwned($table)) {
            return sprintf(
                '%s on %s: a migration may only touch data in tables with the prefix "%s".',
                $verb,
                $table,
                LegacySchema::PREFIX,
            );
        }

        return null;
    }

    /**
     * The bare table name: database qualifier and back quotes removed.
     */
    private static function tableFrom(string $statement, string $pattern): ?string
    {
        if (preg_match($pattern, $statement, $matches) !== 1) {
            return null;
        }

        $raw = trim($matches[1], '`" ;');
        $parts = explode('.', $raw);
        $name = trim((string) end($parts), '`" ');

        return $name === '' ? null : $name;
    }

    private static function normalise(string $statement): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $statement));
    }

    private static function starts(string $upper, string $prefix): bool
    {
        return str_starts_with($upper, $prefix . ' ') || $upper === $prefix;
    }

    private static function matches(string $upper, string $pattern): bool
    {
        return preg_match($pattern, $upper) === 1;
    }

    /**
     * The first three words, for the "unsupported statement" message.
     */
    private static function headKeywords(string $statement): string
    {
        $words = preg_split('/\s+/', $statement, 4) ?: [];

        return implode(' ', array_slice($words, 0, 3));
    }
}
