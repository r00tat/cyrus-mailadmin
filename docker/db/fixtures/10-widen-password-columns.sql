-- ---------------------------------------------------------------------------
-- FINDING, not a fixture.
--
-- webcyradm declares `accountuser.password` and `adminuser.password` as
-- VARCHAR(50). That is wide enough for the two formats that exist in production
-- today — DES-crypt (13 characters) and SHA1 hex (40) — and too narrow for
-- everything the new password handling produces:
--
--   bcrypt      $2y$…   60 characters   accountuser (S1, CLAUDE.md)
--   sha512crypt $6$…   106 characters   accepted legacy format
--   Argon2id    $argon2id$…  ~97        adminuser  (S1, CLAUDE.md)
--
-- MariaDB in its default (strict) mode rejects the INSERT; in a non-strict
-- installation it truncates silently, which would store a hash that can never
-- verify. Either way the chain `password_hash()` -> accountuser -> allusers ->
-- pam_mysql -> crypt(3) cannot be walked without widening the column first.
--
-- WIDENING IS ALLOWED: rule one in CLAUDE.md forbids DROP, RENAME and narrowing
-- a column. Making a VARCHAR longer is none of those, and neither Postfix nor
-- pam_mysql cares about the declared width.
--
-- THIS FILE IS NOT THE MIGRATION. It exists so the test environment can carry
-- the accounts S1 produces before F3 has shipped the real migration. The real
-- one belongs in `migrations/` and is owned by F3/S1; when it exists, this file
-- becomes a no-op (ALTER on an already wide column is accepted and does
-- nothing) and can be deleted.
-- ---------------------------------------------------------------------------

ALTER TABLE accountuser
    MODIFY password VARCHAR(255) COLLATE latin1_bin NOT NULL DEFAULT '';

ALTER TABLE adminuser
    MODIFY password VARCHAR(255) COLLATE latin1_bin NOT NULL DEFAULT '';
