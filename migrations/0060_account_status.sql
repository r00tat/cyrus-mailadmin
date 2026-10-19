-- 0060 — account deactivation (A2, range 0060-0069 per U-127).
--
-- An account marked here as `disabled = 1` cannot log in: the view `allusers`,
-- which `saslauthd`/`pam_mysql` authenticates against for IMAP, POP,
-- ManageSieve and SMTP-AUTH, leaves it out (U-127). Mail **delivery** is not
-- affected — Postfix reads `virtual`, not this table.
--
-- ## The view depends on this table — the order of the rollout
--
-- The new `allusers` joins this table. A view that names a table which does not
-- exist is not merely empty, it fails every query — and with it every login on
-- the server. So:
--
--   1. run this migration (`php bin/migrate.php`);
--   2. only then roll out the new view (`docs/deployment/allusers.sql.example`
--      through Salt).
--
-- The old view keeps working next to this table; it simply ignores it. The
-- application refuses to disable an account as long as the deployed view does
-- not honour the table (`account.status_not_enforced`), so the gap between
-- step 1 and step 2 cannot produce a "disabled" account that still logs in.
--
-- ## Shape
--
-- `username` is `accountuser.username` **as stored** — the Cyrus user name, in
-- whichever scheme the account has. It is never re-derived (architecture 3.3).
--
-- Unlike every other `mailadmin_` table, this key column copies the legacy
-- column's character set and collation (`latin1` / `latin1_bin`). The view has
-- to join the two tables in SQL — there is no other place pam_mysql could get
-- the answer from — and with the same collation on both sides the join compares
-- the stored bytes, exactly like the primary key of `accountuser`, and the index
-- stays usable. The rest of the table is utf8mb4 like every other one. If the
-- target installation's `accountuser.username` is no longer `latin1_bin`
-- (O-16), the join still works — MariaDB converts latin1 to utf8mb4 — and
-- `bin/db-check.php` reports the difference.
--
-- A missing row means "enabled", and so does `disabled = 0`: an account
-- webcyradm created has no row and must keep working. Re-enabling keeps the row
-- (with `disabled = 0`) so that the last change and who made it stay visible;
-- the audit trail has the full history.
--
-- Rows are removed with their account (A2's `DeleteAccount`), cleared when an
-- account is created under a name that has a leftover row, and moved along when
-- a domain rename changes the stored user name. No foreign key: `accountuser`
-- is MyISAM (O-11) and has none to point at.

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
