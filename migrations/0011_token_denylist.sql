-- 0011 — revoked access tokens (S2, range 0010-0019 per U-6).
--
-- A JWT is valid until it expires; that is the point of a stateless token and
-- also its one weakness. This table is the exception list, consulted on every
-- authenticated request (architecture 5.4).
--
-- ## Two kinds of row in one table
--
-- `jti` non-empty — one specific access token, put here by `POST /auth/logout`.
-- Sixty minutes later it would have died anyway; the row exists so that "signed
-- out" means now rather than eventually.
--
-- `jti = ''` — **every** token of that administrator issued at or before
-- `issued_before`. This is what a role change and a deleted account need
-- (S2's open points 4 and 6), and it is the reason there is no `sub` -> `jti`
-- index anywhere in the system: revoking a person's tokens does not require
-- knowing which tokens they have. A single row does it, and the check that was
-- going to run per request anyway answers it.
--
-- The primary key is `(subject, jti)` in that order so that both kinds are
-- found by one range read over at most two rows:
--
--     SELECT jti, issued_before FROM mailadmin_token_denylist
--      WHERE subject = ? AND jti IN ('', ?)
--
-- With the columns the other way round the subject-wide row would need a second
-- lookup, and this query runs on every request that carries a token.
--
-- `issued_before` is compared against the token's `iat`. A token minted *after*
-- the revocation — the new one the administrator gets when they sign in again —
-- is not affected, which is what makes a subject-wide row safe to leave lying
-- around until it expires.
--
-- `expires_at` is the latest `exp` the row can still be needed for. After that
-- the token it denies would be rejected on its own merits, so `bin/prune-tokens`
-- deletes the row. Nothing here is an audit trail — that is S4's table, with its
-- own retention.

CREATE TABLE IF NOT EXISTS `mailadmin_token_denylist` (
    `subject`       VARCHAR(190) NOT NULL COMMENT 'adminuser.username; the token sub claim',
    `jti`           VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'one token, or empty for every token of this subject',
    `issued_before` DATETIME     NOT NULL COMMENT 'UTC; denies tokens whose iat is at or before this',
    `expires_at`    DATETIME     NOT NULL COMMENT 'UTC; when this row stops mattering and may be pruned',
    `reason`        VARCHAR(32)  NOT NULL DEFAULT '' COMMENT 'logout | role_change | admin_removed; diagnostics only',
    `created_at`    DATETIME     NOT NULL COMMENT 'UTC (U-14)',
    PRIMARY KEY (`subject`, `jti`),
    KEY `mailadmin_token_denylist_expiry` (`expires_at`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'mailadmin: revoked access tokens, by jti or by subject (S2).';
