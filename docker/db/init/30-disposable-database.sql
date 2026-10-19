-- ---------------------------------------------------------------------------
-- A second, deliberately empty database for the persistence integration tests.
--
-- WHY THIS EXISTS
-- `tests/Integration/Persistence/DatabaseTestCase` creates and drops tables, so
-- it refuses to run against a database whose legacy tables hold rows — pointed
-- at production by accident it would drop `domain` and `accountuser`. That guard
-- is right and stays.
--
-- But it also means those tests skip against `mail`, because `mail` is the
-- seeded environment every other integration test needs. Both suites can only
-- run in one command if there are two databases: `mail` with the fixtures, and
-- this one, which is empty and stays empty. `MigratorIntegrationTest` builds the
-- webcyradm baseline it needs itself, so nothing has to be created here.
--
-- WHY IT GETS `DROP` AND `mail` DOES NOT
-- Withholding `DROP` on `mail` turns rule one of CLAUDE.md — migrations are
-- additive — into something the server enforces rather than something a reviewer
-- has to notice. The tests need the opposite, and giving it to them here is what
-- keeps the two apart instead of weakening the grant that matters.
--
-- `CREATE VIEW` and `SHOW VIEW` are for `AccountStatusEnforcementIntegrationTest`,
-- which builds variants of `allusers` here to test what `bin/db-check.php` says
-- about them (U-129). `mail` gets neither: its views belong to Salt.
--
-- ALL PASSWORDS INVOLVED ARE TEST VALUES. See 20-grants.sql.
-- ---------------------------------------------------------------------------

CREATE DATABASE IF NOT EXISTS mail_test
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP, CREATE VIEW, SHOW VIEW
    ON mail_test.* TO 'mailadmin'@'%';

FLUSH PRIVILEGES;
