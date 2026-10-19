-- 0003 — make the two password columns wide enough for a modern hash
--        (F3 range 0001-0009 per U-6; needed by S1 and S2).
--
-- webcyradm declared both columns VARCHAR(50). That fits the two formats it
-- produced — DES-crypt at 13 characters and SHA1 hex at 40 — and nothing the new
-- password handling produces:
--
--     bcrypt       $2y$...          60 characters   accountuser (E-3: crypt(3) readable)
--     Argon2id     $argon2id$...    97 characters   adminuser
--     sha512crypt  $6$...          106 characters   an accepted legacy format
--
-- In MariaDB's strict mode the INSERT is rejected; in a non-strict installation
-- it is truncated silently, which stores a hash that can never verify again.
-- Until this migration has run, an administrator whose password is rehashed on
-- sign-in (E-13) is locked out of their own account.
--
-- ## Why this is allowed at all
--
-- Rule 1 in CLAUDE.md forbids DROP, RENAME and narrowing a column. A longer
-- VARCHAR is none of those, and neither Postfix nor pam_mysql reads the declared
-- width. AdditiveOnlyRule accepts it as the single enumerated exception: the
-- column has to appear in LegacySchema::WIDENABLE_COLUMNS and the new width has
-- to exceed the recorded one. Anything else — a second clause, a different
-- column, a shorter width, a change to TEXT — is still refused.
--
-- ## Why the character set and collation are spelled out
--
-- Measured against MariaDB 11.8: `ALTER TABLE adminuser MODIFY password
-- VARCHAR(255) NOT NULL DEFAULT ''` — with no character set given — silently
-- changed the column from `latin1_bin` to `latin1_swedish_ci`, because MODIFY
-- replaces the whole column definition and an omitted character set falls back
-- to the table default. A case-insensitive collation on a column holding
-- password hashes is not something to acquire by accident, so both parts are
-- written out and match what webcyradm declared.
--
-- The values survive the statement either way: every hash format in play is
-- ASCII, and DEFAULT '' and NOT NULL are restated so that MODIFY does not drop
-- them.
--
-- ## Check before running this on an installation
--
-- These statements assume the columns are latin1 today, as webcyradm created
-- them. If an installation has been converted to utf8mb4 in the meantime, this
-- migration would re-encode the values — harmless for every hash format, but not
-- for a **plaintext** password containing a non-ASCII character, which is one of
-- the legacy formats that exist in the wild. `bin/db-check.php` reports the
-- current definition of both columns and says so before anything runs. Open
-- point O-16.

ALTER TABLE `accountuser`
    MODIFY `password` VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL DEFAULT '';

ALTER TABLE `adminuser`
    MODIFY `password` VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL DEFAULT '';
