-- 0001 — the migration log itself (F3, range 0001-0009 per U-6).
--
-- The first migration creates the table the migrator records into. There is no
-- bootstrap that creates it beforehand: while it is absent the database counts
-- as unmigrated, which is exactly what the first run of a legacy webcyradm
-- installation finds (architecture 10.2).
--
-- One row per migration, not one row holding a high-water mark. The reserved
-- number ranges in U-6 leave gaps between packages, and a migration that is
-- merged after a higher-numbered one still has to run.
--
-- applied_at is DATETIME and holds UTC (U-14). Not TIMESTAMP: that type converts
-- on the way in and out according to the server's zone, so the history would
-- shift by an hour twice a year and disagree with the audit log.
--
-- utf8mb4 / utf8mb4_unicode_ci and InnoDB are the defaults for every mailadmin_
-- table. The legacy tables were created as MyISAM with the server default
-- charset; `bin/db-check.php` reports what they actually are today. Nothing here
-- joins them, so the collations do not have to agree.

CREATE TABLE IF NOT EXISTS `mailadmin_schema_version` (
    `version`     INT UNSIGNED  NOT NULL COMMENT 'the NNNN from the file name',
    `name`        VARCHAR(190)  NOT NULL COMMENT 'the description from the file name',
    `checksum`    CHAR(64)      NOT NULL COMMENT 'sha256 of the file as it ran',
    `applied_at`  DATETIME      NOT NULL COMMENT 'UTC (U-14)',
    `applied_by`  VARCHAR(32)   NOT NULL DEFAULT '' COMMENT 'cli or auto',
    `duration_ms` INT UNSIGNED  NOT NULL DEFAULT 0,
    PRIMARY KEY (`version`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'mailadmin: applied migrations. Written by bin/migrate.php only.';
