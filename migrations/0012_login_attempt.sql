-- 0012 — failed sign-in attempts, for the rate limiter (S2, range 0010-0019 per U-6).
--
-- Architecture 5.6 asks for a counter per IP **and** per user name. Both, not
-- either: counting only by name lets a botnet spread an attack across addresses,
-- and counting only by address locks out everyone behind one NAT gateway as soon
-- as a single person mistypes their password.
--
-- ## Only failures are recorded
--
-- A successful sign-in deletes this administrator's rows rather than adding one.
-- The table is therefore short — the length of the current attack — and a
-- legitimate user who eventually gets their password right is not one step
-- closer to a lockout the next time.
--
-- ## The IP address is personal data
--
-- It is here because the mechanism cannot work without it, it is stored for as
-- long as the window it is counted in, and `bin/prune-tokens` removes anything
-- older. Nothing else in the application reads this table, and it is not an
-- audit trail: what an administrator *did* goes to `mailadmin_audit` (S4).
--
-- VARCHAR(45) holds an IPv6 address in its longest textual form, including an
-- embedded IPv4 tail. Not VARBINARY(16): the value is compared and never
-- computed with, and a readable column is worth more when something has to be
-- explained to an operator.
--
-- **Behind a reverse proxy every request appears to come from the proxy** (O-6).
-- The limiter then counts the whole world as one address and the per-name
-- counter carries the work alone. Evaluating `X-Forwarded-For` is deliberately
-- not done here, because a forwarded header that is not stripped by a trusted
-- proxy is an attacker-controlled key.

CREATE TABLE IF NOT EXISTS `mailadmin_login_attempt` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`     VARCHAR(190)    NOT NULL COMMENT 'as it was sent, which may be an account that does not exist',
    `client_ip`    VARCHAR(45)     NOT NULL DEFAULT '' COMMENT 'empty when the address is unknown',
    `attempted_at` DATETIME        NOT NULL COMMENT 'UTC (U-14)',
    PRIMARY KEY (`id`),
    KEY `mailadmin_login_attempt_username` (`username`, `attempted_at`),
    KEY `mailadmin_login_attempt_ip` (`client_ip`, `attempted_at`),
    KEY `mailadmin_login_attempt_age` (`attempted_at`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'mailadmin: failed sign-in attempts. Cleared on success, pruned by age (S2).';
