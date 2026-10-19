-- 0010 — refresh tokens with rotation and reuse detection (S2, range 0010-0019 per U-6).
--
-- One row per issued refresh token, never overwritten in place: a rotation
-- inserts the successor and marks the predecessor consumed. The history within a
-- family is what makes reuse detectable at all — if rotation deleted the old
-- row, a stolen token would come back as "unknown" and be indistinguishable
-- from a typo.
--
-- ## What is stored, and what is not
--
-- The token itself is never stored. The column holds the SHA-256 of it, and
-- SHA-256 rather than Argon2id or bcrypt on purpose: the token is 256 bits of
-- output from a CSPRNG, so there is no dictionary to attack and no work factor
-- worth paying. A slow hash here would also make the lookup a table scan, since
-- a salted hash cannot be looked up by value.
--
-- No IP address and no user agent. Both were tempting for forensics and neither
-- is needed to decide anything; what happened to a family belongs in S4's audit
-- log, which is the place with a retention rule.
--
-- ## Lifetimes
--
-- `expires_at` is the family's absolute deadline (twelve hours, architecture
-- 5.2) and is copied unchanged into every successor. Rotation therefore does not
-- extend a session — an attacker who keeps refreshing still loses access at the
-- twelve-hour mark, which is the whole reason the deadline is absolute rather
-- than sliding.
--
-- `revoked_at` is set on every row of a family at once when a logout happens or
-- when a reuse is detected. It is distinct from `consumed_at`: a consumed token
-- was used legitimately, a revoked one must never work again.
--
-- Rows outlive their usefulness by design so that a late reuse is still caught;
-- `bin/prune-tokens` removes them once `expires_at` has passed.

CREATE TABLE IF NOT EXISTS `mailadmin_refresh_token` (
    `token_hash`  CHAR(64)     NOT NULL COMMENT 'sha256 hex of the token; the token itself is never stored',
    `family`      CHAR(32)     NOT NULL COMMENT 'hex; one family per sign-in, revoked as a whole',
    `subject`     VARCHAR(190) NOT NULL COMMENT 'adminuser.username',
    `issued_at`   DATETIME     NOT NULL COMMENT 'UTC (U-14)',
    `expires_at`  DATETIME     NOT NULL COMMENT 'UTC; the family deadline, never extended by a rotation',
    `consumed_at` DATETIME     NULL     COMMENT 'UTC; set when this token was exchanged. A second exchange is a reuse.',
    `revoked_at`  DATETIME     NULL     COMMENT 'UTC; set for the whole family on logout or on a detected reuse',
    PRIMARY KEY (`token_hash`),
    KEY `mailadmin_refresh_token_family` (`family`),
    KEY `mailadmin_refresh_token_subject` (`subject`, `expires_at`),
    KEY `mailadmin_refresh_token_expiry` (`expires_at`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'mailadmin: refresh tokens, rotating with reuse detection (S2).';
