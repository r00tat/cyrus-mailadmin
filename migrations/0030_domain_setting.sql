-- 0030 — per-domain settings mailadmin owns (A1, range 0030-0039 per U-6).
--
-- The legacy `domain` table is frozen (CLAUDE.md rule 1): Postfix reads it at
-- delivery time, so a column may not be added to it and nothing in it may change
-- meaning. Everything A1 needs beyond webcyradm's nine columns therefore lives
-- here, in a table with the `mailadmin_` prefix and one row per domain.
--
-- ## What is in it, and what is deliberately not
--
-- `naming_scheme` is the domain-level step of the three-step resolution in
-- architecture 3.3: the domain setting wins over `MAILADMIN_NAMING_SCHEME`,
-- which wins over `dotted`. `NULL` means "not set for this domain" and is the
-- default — it is a different fact from `dotted`, and collapsing the two would
-- make a global default impossible to change afterwards.
--
-- It decides what **new** accounts get. The scheme of an account that already
-- exists is recognised from its stored user name and may well differ (U-22, and
-- `NamingScheme::detect()`); moving an account between schemes is a
-- `renamemailbox` plus new credentials for its owner (E-14) and is never a side
-- effect of writing this column.
--
-- No quota, no account limit, no folder list: those are `domain.quota`,
-- `domain.domainquota`, `domain.maxaccounts` and `domain.folders`, they are read
-- by other consumers, and a second copy here would be a second answer.
--
-- ## The row is optional
--
-- A domain webcyradm created has no row here and must keep working, so every
-- read is a `LEFT` lookup that tolerates absence, and `DomainRepository` writes
-- a row only when there is something to say. That also means this migration
-- needs no backfill: an empty table is the correct state for every existing
-- domain.
--
-- `domain_name` carries the same value as `domain.domain_name`, which is
-- `latin1` in the legacy schema. This table is `utf8mb4` all the same, because a
-- domain name is punycode ASCII (`DomainName` rejects anything else) and the two
-- are never joined — the lookup is a separate statement keyed by name, which
-- also keeps an index usable on both sides.

CREATE TABLE IF NOT EXISTS `mailadmin_domain_setting` (
    `domain_name`   VARCHAR(255) NOT NULL COMMENT 'domain.domain_name; no foreign key, the legacy table is not ours',
    `naming_scheme` VARCHAR(16)  NULL     COMMENT 'dotted | fqun | NULL = follow the global default',
    `created_at`    DATETIME     NOT NULL COMMENT 'UTC (U-14)',
    `updated_at`    DATETIME     NOT NULL COMMENT 'UTC (U-14)',
    PRIMARY KEY (`domain_name`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'mailadmin: per-domain settings that do not fit the frozen legacy table (A1).';
