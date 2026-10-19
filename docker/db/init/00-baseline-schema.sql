-- ---------------------------------------------------------------------------
-- webcyradm baseline schema — the state of the database *before* mailadmin.
--
-- This file deliberately contains no `mailadmin_` table. The migrator (F3) is
-- tested against this schema, not against an empty one: a migrator that has
-- only ever run on an empty database says nothing about the case that occurs in
-- practice (architecture 12.3).
--
-- Source of the table definitions: webcyradm 0.5.5
-- (`scripts/create_mysql.sql` plus the three upgrade scripts), which is what the
-- production database was created from. The differences are listed below and
-- are all forced by the server version, not by taste:
--
--   * `TYPE=MyISAM` -> `ENGINE=MyISAM`. The old syntax was removed in MySQL 5.5.
--   * `int(10)` / `varchar(50) binary` -> `INT` / `VARCHAR(50) COLLATE
--     latin1_bin`. Display widths are gone from MariaDB 10.2 onwards; the
--     `binary` attribute becomes an explicit binary collation. The collation is
--     not cosmetic: `password` must compare case-sensitively, otherwise a hash
--     would match with the wrong case.
--   * `timestamp(14)` -> `TIMESTAMP`.
--
-- CHARACTER SET latin1, and that is not a leftover. The dump this comes from was
-- produced by MySQL 3.23, whose default was latin1, and nothing in webcyradm
-- ever converted it. It is also forced by the engine: MyISAM allows a key of at
-- most 1000 bytes, and `PRIMARY KEY (domain_name)` on a VARCHAR(255) needs 1020
-- of them in utf8mb4. A utf8mb4 baseline therefore cannot be created at all
-- without changing the keys — which means the production database is not utf8mb4
-- either.
--
-- TWO CONSEQUENCES, both of which this environment exists to surface:
--
--   1. F3 connects with `charset=utf8mb4` (architecture 10.1). Reading and
--      writing ASCII through it is fine — MariaDB converts — but a character
--      outside latin1 does not survive a round trip into a legacy column.
--   2. New `mailadmin_` tables in utf8mb4 joined against a latin1 legacy column
--      raise "Illegal mix of collations" unless the join is written for it.
--      Better to meet that here than in production.
--
-- ASSUMPTION: latin1 is inferred, not measured. Confirm read-only against
-- production (`SHOW CREATE TABLE accountuser`) before the first migration run;
-- if it differs, this file is the one place to change.
--
-- NOTE ON `password VARCHAR(50)`: this is the width webcyradm ships, and it is
-- wide enough for exactly the two formats that exist in production (DES-crypt,
-- 13 characters, and SHA1 hex, 40). It is **too narrow for bcrypt** (60) and for
-- sha512crypt (106). See docker/db/fixtures/10-widen-password-columns.sql.
-- ---------------------------------------------------------------------------

SET NAMES latin1;

-- Mail accounts. `username` holds the Cyrus form, which is either
-- `local.domain.tld` (dotted) or `local@domain.tld` (fqun) — the two cannot be
-- told apart by taking the name to pieces, only by looking for an `@`
-- (architecture 3.3).
CREATE TABLE IF NOT EXISTS accountuser (
    username    VARCHAR(255) COLLATE latin1_bin NOT NULL DEFAULT '',
    password    VARCHAR(50)  COLLATE latin1_bin NOT NULL DEFAULT '',
    prefix      VARCHAR(50)                      NOT NULL DEFAULT '',
    domain_name VARCHAR(255)                     NOT NULL DEFAULT '',
    imap        INT                              NOT NULL DEFAULT 1,
    pop         INT                              NOT NULL DEFAULT 1,
    sieve       INT                              NOT NULL DEFAULT 1,
    smtpauth    INT                              NOT NULL DEFAULT 1,
    UNIQUE KEY username (username)
) ENGINE = MyISAM DEFAULT CHARSET = latin1;

-- Administrators. `type` 0 = superuser, 1 = domain master.
CREATE TABLE IF NOT EXISTS adminuser (
    username VARCHAR(50)  COLLATE latin1_bin NOT NULL DEFAULT '',
    password VARCHAR(50)  COLLATE latin1_bin NOT NULL DEFAULT '',
    type     INT                              NOT NULL DEFAULT 0,
    SID      VARCHAR(255)                     NOT NULL DEFAULT '',
    home     VARCHAR(255)                     NOT NULL DEFAULT '',
    PRIMARY KEY (username)
) ENGINE = MyISAM DEFAULT CHARSET = latin1;

-- Per-administrator interface preferences.
CREATE TABLE IF NOT EXISTS settings (
    username   VARCHAR(50) COLLATE latin1_bin NOT NULL DEFAULT '',
    style      VARCHAR(50)                     NOT NULL DEFAULT 'default',
    maxdisplay INT                             NOT NULL DEFAULT 15,
    warnlevel  INT                             NOT NULL DEFAULT 90,
    PRIMARY KEY (username)
) ENGINE = MyISAM DEFAULT CHARSET = latin1;

-- Legacy, effectively unused (one row in production). Not to be confused with
-- `virtual`, which is where aliases actually live.
CREATE TABLE IF NOT EXISTS alias (
    alias    VARCHAR(255) NOT NULL DEFAULT '',
    dest     LONGTEXT,
    username VARCHAR(50)  NOT NULL DEFAULT '',
    status   INT          NOT NULL DEFAULT 1,
    PRIMARY KEY (alias)
) ENGINE = MyISAM DEFAULT CHARSET = latin1;

-- Mail domains. `prefix` is NOT NULL UNIQUE and is misused as a de-facto domain
-- key by the legacy code (analysis, section 9).
CREATE TABLE IF NOT EXISTS domain (
    domain_name VARCHAR(255)          NOT NULL DEFAULT '',
    prefix      VARCHAR(50)           NOT NULL DEFAULT '',
    maxaccounts INT                   NOT NULL DEFAULT 20,
    quota       INT                   NOT NULL DEFAULT 20000,
    domainquota INT                   NOT NULL DEFAULT 0,
    transport   VARCHAR(255)          NOT NULL DEFAULT 'cyrus',
    freenames   ENUM ('YES', 'NO')    NOT NULL DEFAULT 'NO',
    freeaddress ENUM ('YES', 'NO')    NOT NULL DEFAULT 'NO',
    folders     VARCHAR(255)          NOT NULL DEFAULT '',
    PRIMARY KEY (domain_name),
    UNIQUE KEY prefix (prefix)
) ENGINE = MyISAM DEFAULT CHARSET = latin1;

-- n:m between domain masters and domains. `domain_name = '*'` means "all".
CREATE TABLE IF NOT EXISTS domainadmin (
    domain_name VARCHAR(255) NOT NULL DEFAULT '',
    adminuser   VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE = MyISAM DEFAULT CHARSET = latin1;

-- Scratch space for the legacy search screen. Empty in production.
CREATE TABLE IF NOT EXISTS search (
    search_id  VARCHAR(255) NOT NULL DEFAULT '',
    search_sql TEXT         NOT NULL,
    perpage    INT          NOT NULL DEFAULT 0,
    timestamp  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (search_id),
    KEY search_id (search_id)
) ENGINE = MyISAM DEFAULT CHARSET = latin1;

-- The overloaded table: account addresses, aliases, catchalls (`alias =
-- '@domain'`) and forwards (empty `username`) all live here, told apart only by
-- which fields are filled (architecture 3.4).
CREATE TABLE IF NOT EXISTS virtual (
    alias    VARCHAR(255) NOT NULL DEFAULT '',
    dest     LONGTEXT,
    username VARCHAR(50)  NOT NULL DEFAULT '',
    status   INT          NOT NULL DEFAULT 1,
    KEY alias (alias)
) ENGINE = MyISAM DEFAULT CHARSET = latin1;

-- Configured by pam_mysql, but `sqllog=0` keeps it empty. It belongs to
-- pam_mysql, not to us — mailadmin writes its audit trail to `mailadmin_audit`
-- and leaves this table alone (architecture 13).
CREATE TABLE IF NOT EXISTS log (
    id   INT          NOT NULL AUTO_INCREMENT,
    msg  TEXT         NOT NULL,
    user VARCHAR(255) NOT NULL DEFAULT '',
    host VARCHAR(255) NOT NULL DEFAULT '',
    time DATETIME     NOT NULL DEFAULT '2000-01-01 00:00:00',
    pid  VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_log_user (user)
) ENGINE = MyISAM DEFAULT CHARSET = latin1;

-- ---------------------------------------------------------------------------
-- Tables that are not part of webcyradm but exist in the production database.
-- `lists` is written by an external script and read by Salt; the other three
-- are dead (analysis, section 4.1). They are here so that the migrator and the
-- reconciliation view meet the same table list they will meet in production.
-- ---------------------------------------------------------------------------

-- Mailing lists. Written by an external tool, read by Salt to generate
-- /etc/aliases-dir/aliases. webcyradm never shows them (A5 will).
-- ASSUMPTION: the column types are reconstructed from the four column names in
-- the analysis (`name`, `tag`, `address`, `members`); the real DDL was not
-- captured. A5 should confirm against production before relying on the widths.
CREATE TABLE IF NOT EXISTS lists (
    name    VARCHAR(255) NOT NULL DEFAULT '',
    tag     VARCHAR(255) NOT NULL DEFAULT '',
    address VARCHAR(255) NOT NULL DEFAULT '',
    members LONGTEXT,
    PRIMARY KEY (name)
) ENGINE = MyISAM DEFAULT CHARSET = latin1;

-- Dead tables. Empty in production, kept so nothing is surprised by their
-- presence.
CREATE TABLE IF NOT EXISTS users (
    username VARCHAR(255) NOT NULL DEFAULT '',
    password VARCHAR(50)  NOT NULL DEFAULT '',
    uid      INT          NOT NULL DEFAULT 0,
    gid      INT          NOT NULL DEFAULT 0,
    homedir  VARCHAR(255) NOT NULL DEFAULT '',
    shell    VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (username)
) ENGINE = MyISAM DEFAULT CHARSET = latin1;

CREATE TABLE IF NOT EXISTS `groups` (
    groupname VARCHAR(255) NOT NULL DEFAULT '',
    gid       INT          NOT NULL DEFAULT 0,
    members   LONGTEXT,
    PRIMARY KEY (groupname)
) ENGINE = MyISAM DEFAULT CHARSET = latin1;

CREATE TABLE IF NOT EXISTS sentmails (
    id       INT          NOT NULL AUTO_INCREMENT,
    username VARCHAR(255) NOT NULL DEFAULT '',
    time     DATETIME     NOT NULL DEFAULT '2000-01-01 00:00:00',
    bytes    BIGINT       NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE = MyISAM DEFAULT CHARSET = latin1;
