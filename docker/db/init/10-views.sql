-- ---------------------------------------------------------------------------
-- The two views that are the actual contract between the database and the mail
-- stack. In production they are created by Salt, not by webcyradm
-- (`salt/service/mail/files/{allusers,validsenders}.sql`), which is why they are
-- a separate file here too: they are not part of the webcyradm schema and a
-- migrator must not assume it owns them.
--
--   allusers      -> authentication source for pam_mysql (IMAP, POP, SMTP-AUTH)
--   validsenders  -> smtpd_sender_login_maps, i.e. anti sender spoofing
--
-- ASSUMPTION: the exact statements were not captured in the analysis; only the
-- shape of `allusers` and a one-line description of `validsenders` were. What
-- follows is reconstructed to produce the same columns and the same row counts
-- in kind. Both views must be re-read from production before anything relies on
-- a column that is not `username` or `password`.
--
-- E-1 forbids changing them. In particular the `REPLACE` below stays as it is,
-- bug included — see the note at the end of this file.
--
-- ONE AUTHORISED CHANGE (U-127): the project owner allowed `allusers` to leave
-- out the accounts marked disabled in `mailadmin_account_status`. Every row and
-- every column the view returned before is returned unchanged for an account
-- that is not disabled — `AccountStatusViewTest` compares the old and the new
-- definition on the fixtures. `validsenders` is not touched.
--
-- The table comes from 05-account-status.sql (migration 0060). In production the
-- order is the same and it is mandatory: migration first, then the new view —
-- a view over a missing table fails every login. The Salt template is
-- docs/deployment/allusers.sql.example and must stay identical to the
-- statement below; `AccountStatusViewTest` checks that too.
-- ---------------------------------------------------------------------------

-- Every login spelling a user may authenticate with, and the hash to check it
-- against. Two branches:
--
--   1. the address form, derived from the stored Cyrus name
--   2. the stored Cyrus name itself
--
-- so `anna.example.test` and `anna@example.test` both authenticate.
--
-- Both branches leave out an account whose row in `mailadmin_account_status`
-- says `disabled = 1` (U-127). No row, or `disabled = 0`, is an enabled
-- account — webcyradm's accounts have no row and keep working.
CREATE OR REPLACE VIEW allusers AS
SELECT CONCAT(REPLACE(accountuser.username, CONCAT('.', accountuser.domain_name), ''), '@', accountuser.domain_name) AS username,
       accountuser.password                                                                                          AS password,
       accountuser.domain_name                                                                                       AS domain_name,
       accountuser.imap                                                                                              AS imap,
       accountuser.pop                                                                                               AS pop,
       accountuser.sieve                                                                                             AS sieve,
       accountuser.smtpauth                                                                                          AS smtpauth
FROM accountuser
         LEFT JOIN mailadmin_account_status ON mailadmin_account_status.username = accountuser.username
WHERE mailadmin_account_status.username IS NULL
   OR mailadmin_account_status.disabled = 0
UNION
SELECT accountuser.username    AS username,
       accountuser.password    AS password,
       accountuser.domain_name AS domain_name,
       accountuser.imap        AS imap,
       accountuser.pop         AS pop,
       accountuser.sieve       AS sieve,
       accountuser.smtpauth    AS smtpauth
FROM accountuser
         LEFT JOIN mailadmin_account_status ON mailadmin_account_status.username = accountuser.username
WHERE mailadmin_account_status.username IS NULL
   OR mailadmin_account_status.disabled = 0;

-- Which login may put which address into MAIL FROM. One row per (address,
-- login spelling) pair, so that a user who authenticated as `anna.example.test`
-- and one who authenticated as `anna@example.test` are both allowed to send as
-- the addresses of that account.
CREATE OR REPLACE VIEW validsenders AS
SELECT virtual.alias      AS alias,
       accountuser.username AS username
FROM virtual
         JOIN accountuser ON accountuser.username = virtual.username
WHERE virtual.status = 1
  AND virtual.username <> ''
UNION
SELECT virtual.alias AS alias,
       CONCAT(
               REPLACE(accountuser.username, CONCAT('.', accountuser.domain_name), ''),
               '@',
               accountuser.domain_name
       )             AS username
FROM virtual
         JOIN accountuser ON accountuser.username = virtual.username
WHERE virtual.status = 1
  AND virtual.username <> '';

-- ---------------------------------------------------------------------------
-- The known fragility, kept on purpose (architecture 3.3).
--
-- `REPLACE` replaces *every* occurrence. For an account whose local part
-- contains the domain name — `news.example.test` in `example.test`, stored as
-- `news.example.test.example.test` — the first branch of `allusers` yields
-- `news@example.test` instead of `news.example.test@example.test`.
--
-- The fixtures contain exactly that account so the effect is visible in a test
-- rather than in production. We do not fix the view (E-1); the application
-- reads `accountuser` directly and derives the address from (local part,
-- domain, scheme) instead.
-- ---------------------------------------------------------------------------
