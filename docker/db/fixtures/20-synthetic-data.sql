-- ---------------------------------------------------------------------------
-- Synthetic fixture data.
--
-- EVERY value in this file is invented. The domains use the reserved TLDs
-- `.test` and `.invalid` (RFC 2606/6761), which can never resolve. The
-- passwords are printed in the clear a few lines below each hash. Nothing here
-- comes from the production system — E-5 treats a production value in a fixture
-- as an escalation, because fixtures are committed and committed things get
-- published.
--
-- The set is small on purpose. It is not a load test; it is one row per case
-- that the code has to tell apart.
--
--   * both naming schemes (architecture 3.3)
--   * one account per legacy hash format (architecture 5.5)
--   * a local part containing a dot, which is why a Cyrus name cannot be taken
--     apart
--   * a local part containing the *domain name*, which is where the `allusers`
--     REPLACE breaks
--   * a domain with no accounts
--   * alias, catchall, forward, disabled forward
--   * a `virtual` row that fits no pattern (U-22, UnknownReason)
--
-- Passwords follow one shape so a test does not have to look them up:
--
--     <local part>-secret-2026
--
-- with the exception of the Cyrus administrator, which is
-- `testenv-cyrus-admin-pw`. tests/Fixtures/TestAccounts.php holds the same list
-- for PHP, and tests/Integration/TestEnvironment/FixtureCatalogTest.php fails
-- if the two drift apart.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Domains
-- ---------------------------------------------------------------------------

INSERT INTO domain (domain_name, prefix, maxaccounts, quota, domainquota, transport,
                    freenames, freeaddress, folders)
VALUES
    -- Accounts in the dotted scheme. Default folders set, so M2 has something
    -- to create.
    ('example.test', 'example', 20, 20000, 0, 'cyrus', 'YES', 'NO', 'Sent,Drafts,Trash,Junk'),
    -- Accounts in the fqun scheme. Requires the domain to be in the Cyrus
    -- `loginrealms` — it is, see docker/mail/cyrus/imapd.conf.
    ('beispiel.invalid', 'beispiel', 10, 50000, 100000, 'cyrus', 'YES', 'YES', 'Sent,Drafts'),
    -- A domain with no accounts at all. Lists, quota roll-ups and the
    -- reconciliation view all have to survive it.
    ('leer.test', 'leer', 5, 20000, 0, 'cyrus', 'NO', 'NO', '');

-- ---------------------------------------------------------------------------
-- Mail accounts
--
-- `username` is the stored Cyrus name. Dotted scheme: `local.domain.tld`.
-- Fqun scheme: `local@domain.tld`. The presence of an `@` is the only thing
-- that distinguishes them, and it is unambiguous (architecture 3.3).
-- ---------------------------------------------------------------------------

INSERT INTO accountuser (username, password, prefix, domain_name, imap, pop, sieve, smtpauth)
VALUES
    -- bcrypt. The format CLAUDE.md prescribes for new accounts, and the one the
    -- Definition of Done names: this account must be able to log in over IMAP
    -- and over SMTP-AUTH. password: anna-secret-2026
    ('anna.example.test',
     '$2y$10$MtsgY.zwSBi7BLs.It0Gv.SKPLWzFT5.rLqqlLISj/bxadscRwsjO',
     'example', 'example.test', 1, 1, 1, 1),

    -- DES-crypt, 13 characters, salted with the first two characters of the
    -- password — the legacy bug S-2, reproduced deliberately so the backward
    -- path is covered. This account must log in as well.
    -- password: bernd-secret-2026
    ('bernd.example.test', 'beLvwoIkCXtO2', 'example', 'example.test', 1, 1, 1, 1),

    -- md5crypt. Not present in production, but crypt(3) understands it and so
    -- must PasswordHasher::verify(). password: clara-secret-2026
    ('clara.example.test', '$1$tEsTsAlT$lBmTDiPteMNjcJNEFHcwq0',
     'example', 'example.test', 1, 1, 1, 1),

    -- SHA1 hex, 40 characters. The second format that exists in production, and
    -- the reason pam_mysql carries a `crypt=4` line next to `crypt=1`.
    -- password: dieter-secret-2026
    ('dieter.example.test', 'b2cf534d6bc4821836c00b28e7919a7fa7ca4bf5',
     'example', 'example.test', 1, 1, 1, 1),

    -- MySQL PASSWORD(). PasswordHasher::verify() must read it; saslauthd will
    -- NOT authenticate it, because neither pam_mysql line covers it (that would
    -- be `crypt=2`). Production has no such row, so the missing line is
    -- correct — the account is here to make that limit visible instead of
    -- surprising. password: erika-secret-2026
    ('erika.example.test', '*5D1764B993A883A02513C7E15F725E553DB56BD7',
     'example', 'example.test', 1, 1, 1, 1),

    -- Plaintext. Same story as the row above: readable by the application, not
    -- authenticatable through pam_mysql (`crypt=0` is not configured). Services
    -- reduced to IMAP only, so the flag columns are not uniformly 1.
    -- password: frank-secret-2026
    ('frank.example.test', 'frank-secret-2026', 'example', 'example.test', 1, 0, 0, 0),

    -- A local part that contains a dot. `gerd.hoffmann.example.test` cannot be
    -- split into local part and domain by looking at it; only
    -- `domain_name` says where the local part ends. password: gerd-secret-2026
    ('gerd.hoffmann.example.test',
     '$2y$10$/wCqFnXB6l1T1CvQyFmq6es.q6h1NAkg9bEAUQFEdp3OoOb7zJ7x2',
     'example', 'example.test', 1, 1, 1, 1),

    -- A local part that contains the *domain name*: local part
    -- `news.example.test` in `example.test`. The `allusers` view reports
    -- `news@example.test` for this row, because REPLACE removes every
    -- occurrence — the fragility documented in architecture 3.3. Kept, not
    -- fixed (E-1). password: news-secret-2026
    ('news.example.test.example.test',
     '$2y$10$u53f2GBcDFCV301dj6wJNu8wTfQZcaa1pE/DdFA0XOVtSg4Jc3z4u',
     'example', 'example.test', 1, 1, 1, 1),

    -- fqun scheme. The stored name carries the `@`, the mailbox lives in the
    -- virtdomains namespace. password: hans-secret-2026
    ('hans@beispiel.invalid',
     '$2y$10$GNpCUsBZ54gTHm.IWYr84.zixmrtjOELoxraU.v6tesg.e80IuzBq',
     'beispiel', 'beispiel.invalid', 1, 1, 1, 1),

    -- fqun scheme with sha512crypt — crypt(3) reads it, so this one does
    -- authenticate through pam_mysql. password: iris-secret-2026
    ('iris@beispiel.invalid',
     '$6$tEsTsAlTtEsTsAlT$BUgbun3M/CP/KLlYcwvAhoKsur3RKdXbV1JPp7w8meq3mqPziKmcbsiNhkpzgYPa25ZyuNzUrBtWEF/YpET410',
     'beispiel', 'beispiel.invalid', 1, 1, 1, 1),

    -- The Cyrus administrator. webcyradm's own installer creates this row, and
    -- Cyrus authenticates its admins through the same saslauthd as everyone
    -- else — so the admin needs an `accountuser` row, with no domain.
    -- `imapd.conf: admins` names it. password: testenv-cyrus-admin-pw
    ('cyrus', '$2y$10$2q2Hq.pxMOdqf/0VUTOESuKPUmvxLZDArFyf/FXevkin/eMtJlQ2q',
     '', '', 1, 1, 1, 1);

-- ---------------------------------------------------------------------------
-- virtual — the overloaded table. Four meanings plus one row that has none.
-- ---------------------------------------------------------------------------

-- 1. Account addresses. One per account, `alias` = address, `dest` = `username`
--    = the Cyrus name.
INSERT INTO virtual (alias, dest, username, status)
VALUES ('anna@example.test', 'anna.example.test', 'anna.example.test', 1),
       ('bernd@example.test', 'bernd.example.test', 'bernd.example.test', 1),
       ('clara@example.test', 'clara.example.test', 'clara.example.test', 1),
       ('dieter@example.test', 'dieter.example.test', 'dieter.example.test', 1),
       ('erika@example.test', 'erika.example.test', 'erika.example.test', 1),
       ('frank@example.test', 'frank.example.test', 'frank.example.test', 1),
       ('gerd.hoffmann@example.test', 'gerd.hoffmann.example.test',
        'gerd.hoffmann.example.test', 1),
       ('news.example.test@example.test', 'news.example.test.example.test',
        'news.example.test.example.test', 1),
       ('hans@beispiel.invalid', 'hans@beispiel.invalid', 'hans@beispiel.invalid', 1),
       ('iris@beispiel.invalid', 'iris@beispiel.invalid', 'iris@beispiel.invalid', 1);

-- 2. Aliases. Same shape as an account address; what makes them aliases is that
--    the address is not the account's own.
INSERT INTO virtual (alias, dest, username, status)
VALUES ('info@example.test', 'anna.example.test', 'anna.example.test', 1),
       -- Two destinations in one row, comma separated — the legacy encoding
       -- DestinationList::fromLegacyString has to read.
       ('team@example.test', 'anna.example.test,bernd.example.test',
        'anna.example.test', 1),
       -- Switched off. `status` is the only deactivation mechanism the old
       -- schema has, and it exists only on `virtual`.
       ('alt@example.test', 'anna.example.test', 'anna.example.test', 0);

-- 3. Catchall: `alias` is `@domain`.
INSERT INTO virtual (alias, dest, username, status)
VALUES ('@example.test', 'bernd.example.test', 'bernd.example.test', 1);

-- 4. Forwards: `alias` is the Cyrus name, `dest` an outside address, `username`
--    empty.
INSERT INTO virtual (alias, dest, username, status)
VALUES ('clara.example.test', 'clara.extern@beispiel.invalid', '', 1),
       ('dieter.example.test', 'dieter.extern@beispiel.invalid', '', 0);

-- 5. A row that fits nothing. `alias` is neither an address nor `@domain`, and
--    `username` is filled, so it is not a forward either — UnknownReason
--    ::AliasIsNotAnAddress. It is live in Postfix whether we understand it or
--    not, so it is shown rather than skipped (U-22).
INSERT INTO virtual (alias, dest, username, status)
VALUES ('legacy-relay-marker', 'anna.example.test', 'anna.example.test', 1);

-- 6. A second unrecognisable row, this one with no destination at all
--    (UnknownReason::NoDestination).
INSERT INTO virtual (alias, dest, username, status)
VALUES ('ghost@example.test', '', 'anna.example.test', 1);

-- ---------------------------------------------------------------------------
-- Administrators
-- ---------------------------------------------------------------------------

-- Both hashes are DES-crypt, because that is what all 82 production admin
-- accounts are. S1's rehash-on-login path has to start somewhere.
INSERT INTO adminuser (username, password, type, SID, home)
VALUES
    -- Superuser. password: superadmin-secret-2026
    ('superadmin', 'suZC/j3VQsZMY', 0, '', ''),
    -- Domain master, scoped to example.test. password: dmaster-secret-2026
    ('dmaster', 'dmUGpsbubngQY', 1, '', '');

INSERT INTO domainadmin (domain_name, adminuser)
VALUES ('*', 'superadmin'),
       ('example.test', 'dmaster');

INSERT INTO settings (username, style, maxdisplay, warnlevel)
VALUES ('superadmin', 'default', 15, 90),
       ('dmaster', 'default', 25, 80);

-- ---------------------------------------------------------------------------
-- Mailing lists. Written by an external tool in production and invisible in
-- webcyradm; A5 makes them visible.
-- ---------------------------------------------------------------------------

INSERT INTO lists (name, tag, address, members)
VALUES ('team-example', 'team', 'team-list@example.test',
        'anna@example.test,bernd@example.test'),
       ('empty-list', 'test', 'empty-list@example.test', '');

-- ---------------------------------------------------------------------------
-- The legacy `alias` table. One row in production, unused. One row here too, so
-- nothing is surprised by it being non-empty.
-- ---------------------------------------------------------------------------

INSERT INTO alias (alias, dest, username, status)
VALUES ('legacy@example.test', 'anna.example.test', 'anna.example.test', 1);
