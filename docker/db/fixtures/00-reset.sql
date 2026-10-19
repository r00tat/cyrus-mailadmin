-- ---------------------------------------------------------------------------
-- Empty the legacy tables so the fixtures can be loaded again.
--
-- Only the tables the fixtures fill are touched. The `mailadmin_` tables are
-- deliberately left alone: `bin/test-env seed` is meant to be usable between
-- two test runs without undoing the migration, and re-running a migrator
-- against an already migrated schema is itself a case worth testing.
--
-- Use `bin/test-env reset` for the other direction — that drops the volume and
-- comes back at the true baseline.
-- ---------------------------------------------------------------------------

TRUNCATE TABLE accountuser;
TRUNCATE TABLE adminuser;
TRUNCATE TABLE domain;
TRUNCATE TABLE domainadmin;
TRUNCATE TABLE settings;
TRUNCATE TABLE virtual;
TRUNCATE TABLE alias;
TRUNCATE TABLE lists;
TRUNCATE TABLE search;
TRUNCATE TABLE log;
