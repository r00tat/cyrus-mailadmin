-- 0002 — application settings that do not belong in the environment (F3, U-6).
--
-- Architecture 10.3 lists `mailadmin_setting` as "application settings, unless
-- they come from the environment". The distinction is deliberate and worth
-- keeping:
--
--   * The environment (U-11) holds what an operator sets before the application
--     starts and what must never be in a file inside the web root: the DSN, the
--     signing key, the mailstore credentials.
--   * This table holds what an administrator changes at runtime through the
--     interface and what has to survive a restart. Nothing in it is a secret.
--
-- The legacy `settings` table is a different thing entirely — per-admin UI
-- preferences, keyed by admin name — and stays untouched (E-1). A4 adds
-- `mailadmin_admin_setting` for the per-admin values it needs (range 0050-0059).
--
-- The value is TEXT rather than a typed column: what a setting means is the
-- reading code's business, and a typed column per kind would be four tables.
-- NULL is a value here — "explicitly unset" — which is why there is a separate
-- row per key rather than a single JSON document.

CREATE TABLE IF NOT EXISTS `mailadmin_setting` (
    `name`       VARCHAR(190) NOT NULL COMMENT 'dotted key, e.g. accounts.default_quota',
    `value`      TEXT         NULL     COMMENT 'NULL means explicitly unset',
    `updated_at` DATETIME     NOT NULL COMMENT 'UTC (U-14)',
    `updated_by` VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'adminuser.username, or empty for the system',
    PRIMARY KEY (`name`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'mailadmin: runtime settings. Never secrets - those come from the environment (U-11).';
