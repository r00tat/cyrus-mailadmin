-- 0020 — the audit trail (S4, range 0020-0029 per U-6).
--
-- Architecture 13 and CLAUDE.md ask the same thing in two places: every
-- state-changing operation records who did what, when, from where, and what the
-- object looked like before and after. The legacy system recorded none of it —
-- finding out who had deleted a mailbox meant asking people.
--
-- ## What is **not** here
--
-- The webcyradm table `log` stays untouched: it belongs to `pam_mysql`, which
-- writes it on every IMAP and SMTP authentication (rule 1). This table is a
-- different thing with a different owner, and it is additive to the schema
-- rather than a replacement for anything.
--
-- Reads are not recorded. Sign-ins and refused attempts are, because those are
-- not reads — they are attempts on the system itself.
--
-- ## The columns that are easy to get wrong
--
-- `state_before` and `state_after` are JSON documents of exactly the fields the
-- API shows, with secrets already removed by `Application\Audit\Redactor`
-- **before** they get here: password fields become a placeholder, hashes are
-- dropped entirely (U-62). A creation has no `state_before` and a deletion no
-- `state_after`; both are `NULL` rather than `{}`, so that "there was nothing"
-- and "it was empty" stay distinguishable (U-61).
--
-- `parent_id` is what makes a batch readable. A domain rename walks every
-- account in Cyrus; that is one operation entry plus one entry per account,
-- each pointing at the operation (U-61). Deliberately no foreign key: the
-- parent is written first and committed with the children, and a constraint
-- would add a lock on a table whose only job is to accept inserts.
--
-- `domain_name` is nullable and is written even though only a superuser may
-- read this table today (U-43). It costs one column now; without it, granting a
-- domain master a scoped view later would be impossible for everything already
-- recorded, because the domain of an object cannot be reconstructed after the
-- object is gone (U-65).
--
-- `client_ip` is personal data and is here because architecture 13 requires it.
-- VARCHAR(45) holds an IPv6 address in its longest textual form. Empty when the
-- address is unknown — a CLI run, or a SAPI that reports none.
--
-- **There is no pruning job** (U-61). An audit trail that deletes itself on a
-- schedule is not an audit trail, and the volume of an installation of this size
-- is measured in thousands of rows a year.

CREATE TABLE IF NOT EXISTS `mailadmin_audit` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `recorded_at`  DATETIME        NOT NULL COMMENT 'UTC (U-14)',
    `actor`        VARCHAR(190)    NOT NULL COMMENT 'adminuser.username, or the name that was sent on a failed sign-in',
    `actor_role`   VARCHAR(20)     NOT NULL DEFAULT '' COMMENT 'superuser / domainmaster, empty when not authenticated',
    `client_ip`    VARCHAR(45)     NOT NULL DEFAULT '' COMMENT 'empty when the address is unknown',
    `request_id`   VARCHAR(64)     NOT NULL DEFAULT '' COMMENT 'joins this row to the application log',
    `action`       VARCHAR(64)     NOT NULL COMMENT '<object>.<verb>, for example domain.create',
    `object_type`  VARCHAR(32)     NOT NULL COMMENT 'domain, account, address, admin, list, session, …',
    `object_id`    VARCHAR(320)    NOT NULL DEFAULT '' COMMENT 'the identifier as the API spells it',
    `domain_name`  VARCHAR(255)    NULL COMMENT 'the domain the object belongs to, for a scoped view later',
    `outcome`      VARCHAR(16)     NOT NULL COMMENT 'succeeded / denied / failed',
    `reason`       VARCHAR(190)    NOT NULL DEFAULT '' COMMENT 'why it was denied or failed; never a message meant for a client',
    `state_before` LONGTEXT        NULL COMMENT 'JSON, redacted; NULL when the object did not exist',
    `state_after`  LONGTEXT        NULL COMMENT 'JSON, redacted; NULL when the object no longer exists',
    `parent_id`    BIGINT UNSIGNED NULL COMMENT 'the operation entry this one belongs to',
    PRIMARY KEY (`id`),
    KEY `mailadmin_audit_actor` (`actor`, `recorded_at`),
    KEY `mailadmin_audit_time` (`recorded_at`),
    KEY `mailadmin_audit_object` (`object_type`, `object_id`, `recorded_at`),
    KEY `mailadmin_audit_domain` (`domain_name`, `recorded_at`),
    KEY `mailadmin_audit_parent` (`parent_id`),
    KEY `mailadmin_audit_request` (`request_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'mailadmin: who changed what, when and from where (S4, architecture 13).';
