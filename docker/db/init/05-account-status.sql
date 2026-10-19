-- ---------------------------------------------------------------------------
-- Migration 0060, applied ahead of the migrator — on purpose (U-127).
--
-- The view `allusers` in 10-views.sql joins `mailadmin_account_status`. A view
-- over a table that does not exist cannot even be created, and in production it
-- would fail every login. So the deployment order is: migration first, view
-- second (docs/deployment/README.md). The init scripts run before anybody can
-- run `php bin/migrate.php`, so the table has to come from here.
--
-- The statement is the one in migrations/0060_account_status.sql, character for
-- character; `AccountStatusViewTest` fails if the two drift apart. Both carry
-- `IF NOT EXISTS`, so the migrator finds the table, does nothing and records the
-- version — which is also what it does on an installation where an operator ran
-- the migration by hand.
--
-- Every other `mailadmin_` table still comes from the migrator: this is the one
-- table the mail stack itself depends on.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `mailadmin_account_status` (
    `username`    VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL
                  COMMENT 'accountuser.username, as stored; same collation so the view joins bytes',
    `disabled`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = allusers leaves the account out',
    `disabled_at` DATETIME     NULL COMMENT 'UTC (U-14); NULL while enabled',
    `disabled_by` VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'adminuser.username; empty while enabled',
    `updated_at`  DATETIME     NOT NULL COMMENT 'UTC (U-14); the last change, either way',
    `updated_by`  VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'adminuser.username of the last change',
    PRIMARY KEY (`username`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'mailadmin: disabled accounts; read by the view allusers (U-127).';
