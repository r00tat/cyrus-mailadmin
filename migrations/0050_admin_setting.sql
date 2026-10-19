-- 0050 — per-administrator preferences that belong to mailadmin (A4, range 0050-0059 per U-6).
--
-- ## Why not the legacy `settings` table
--
-- webcyradm has one: `settings(username, style, maxdisplay, warnlevel)`. It is a
-- legacy table (`CLAUDE.md`, rule 1), so it stays semantically frozen — no new
-- column, no new meaning for an old one. It also has exactly the wrong shape for
-- what is coming: E-16 makes multilingualism a requirement, and „which language
-- does this administrator read the interface in" is not a `style`.
--
-- So the old table keeps its four columns and is left alone, and this one takes
-- anything new. Both are keyed by `adminuser.username`, and A4 deletes from both
-- when an administrator is deleted — webcyradm deleted its `settings` row too,
-- and the alternative is that the next administrator who happens to be given the
-- same name inherits somebody else's preferences.
--
-- ## Why it exists before anything reads it
--
-- Only one thing writes to it today: the delete cascade above. That is on
-- purpose. The cascade is the part that is expensive to add late — a table
-- introduced later by W1 or A6 would silently accumulate rows belonging to
-- administrators who no longer exist, and nothing would fail. Creating it with
-- A4, which owns both the deletion and this migration range, closes that before
-- it can open.
--
-- ## Shape
--
-- A row per key rather than one JSON document per administrator, matching
-- `mailadmin_setting` (0002): NULL is a value here — „explicitly unset" — and a
-- document cannot express the difference between that and „never set" without a
-- convention nobody would remember.
--
-- `username` is VARCHAR(190) and not VARCHAR(50): the legacy column is 50, but a
-- utf8mb4 index is limited by bytes and 190 is the width every other
-- `mailadmin_` table uses for a name. It is deliberately **not** a foreign key —
-- `adminuser` is MyISAM (O-11), which has none, and a constraint that exists on
-- one installation and not on another is worse than a delete statement that
-- always runs.

CREATE TABLE IF NOT EXISTS `mailadmin_admin_setting` (
    `username`   VARCHAR(190) NOT NULL COMMENT 'adminuser.username',
    `name`       VARCHAR(190) NOT NULL COMMENT 'dotted key, e.g. ui.locale',
    `value`      TEXT         NULL     COMMENT 'NULL means explicitly unset',
    `updated_at` DATETIME     NOT NULL COMMENT 'UTC (U-14)',
    PRIMARY KEY (`username`, `name`),
    KEY `mailadmin_admin_setting_name` (`name`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'mailadmin: per-administrator preferences. The legacy settings table stays untouched (A4).';
