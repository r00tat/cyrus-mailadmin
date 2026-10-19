-- ---------------------------------------------------------------------------
-- Database accounts.
--
-- Production runs Postfix, saslauthd, Salt and webcyradm on one single MySQL
-- user (finding S-12). The test environment deliberately does not: the split
-- below is what architecture 10.1 asks for, and having it here means an
-- accidental write from the mail side fails in the test rather than in
-- production.
--
-- ALL PASSWORDS IN THIS FILE ARE TEST VALUES. They are readable in the compose
-- file and in the PAM configuration; nothing here is a secret and nothing here
-- may be reused anywhere else.
-- ---------------------------------------------------------------------------

-- The application (F3). Reads and writes the legacy tables and owns the
-- `mailadmin_` ones, so it needs the DDL rights the migrator uses.
--
-- No DROP: rule one in CLAUDE.md says migrations are additive. Withholding the
-- privilege turns that rule into something the database enforces instead of
-- something a reviewer has to notice.
CREATE USER IF NOT EXISTS 'mailadmin'@'%' IDENTIFIED BY 'testenv-app-pw';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
    ON mail.* TO 'mailadmin'@'%';

-- Reading, not writing, the definition of `allusers`: `bin/db-check.php` reports
-- whether the deployed view honours `mailadmin_account_status` (U-127), and
-- `information_schema.VIEWS` shows the definition only to an account with
-- SHOW VIEW. No CREATE VIEW — the view is Salt's, never the application's.
GRANT SHOW VIEW ON mail.allusers TO 'mailadmin'@'%';

-- Postfix maps and pam_mysql. Read-only, and only the four objects they
-- actually use. `log` is not granted: sqllog is off, and pam_mysql must not be
-- able to write.
CREATE USER IF NOT EXISTS 'mailreader'@'%' IDENTIFIED BY 'testenv-mailreader-pw';
GRANT SELECT ON mail.domain TO 'mailreader'@'%';
GRANT SELECT ON mail.virtual TO 'mailreader'@'%';
GRANT SELECT ON mail.allusers TO 'mailreader'@'%';
GRANT SELECT ON mail.validsenders TO 'mailreader'@'%';

FLUSH PRIVILEGES;
