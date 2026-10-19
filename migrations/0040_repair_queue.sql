-- 0040 — the repair queue (A6, range 0040-0049 per U-6).
--
-- MySQL transactions do not reach Cyrus, and on MyISAM legacy tables (O-11)
-- they do not even reach the database. An operation that spans both systems can
-- therefore stop half way and fail to undo itself. CLAUDE.md is explicit that
-- what is left behind belongs here and not in a log: an operator has to be able
-- to list it, see whether the application can finish it, and mark it as done.
--
-- Until this table existed the entries went to a `critical` log line (U-50).
-- That writer stays as the fallback for a row that cannot be inserted (U-117).
--
-- ## The columns that are easy to get wrong
--
-- `intended`, `reached` and `reason` are English prose for a human, written by
-- the operation that failed. They are **not** parsed by anything. What a retry
-- needs is in `parameters`, a JSON object of strings — the stored Cyrus user
-- name, its domain of record, a folder list (U-118). An entry without
-- parameters can still be read and dismissed, it just cannot be retried.
--
-- No column here ever holds a password, a hash or a token (U-28).
--
-- `status` is `open` or `resolved`, and `resolution` says how: `retried` when
-- the application finished the job, `dismissed` when an administrator says it
-- was handled elsewhere. Rows are never deleted — like the audit trail, the
-- history of what went wrong is the point.
--
-- `attempts`, `last_attempt_at` and `last_error` record failed retries. A
-- failed retry leaves the row `open`; `last_error` is a stable error code, never
-- an exception message.
--
-- `request_id` joins the row to the application log and the audit trail of the
-- request whose operation failed.

CREATE TABLE IF NOT EXISTS `mailadmin_repair_queue` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `recorded_at`     DATETIME        NOT NULL COMMENT 'UTC (U-14)',
    `operation`       VARCHAR(64)     NOT NULL COMMENT 'what was being done, for example domain.rename',
    `step`            VARCHAR(64)     NOT NULL COMMENT 'the step that failed, for example mailstore.rename_back',
    `subject`         VARCHAR(320)    NOT NULL DEFAULT '' COMMENT 'a mailbox, a user name or an address',
    `intended`        TEXT            NOT NULL COMMENT 'English, for a human',
    `reached`         TEXT            NOT NULL COMMENT 'English, for a human',
    `reason`          VARCHAR(190)    NOT NULL DEFAULT '' COMMENT 'an error code or a short English reason',
    `parameters`      LONGTEXT        NULL COMMENT 'JSON object of strings; what a retry needs',
    `request_id`      VARCHAR(64)     NOT NULL DEFAULT '' COMMENT 'joins this row to the log and the audit trail',
    `status`          VARCHAR(16)     NOT NULL DEFAULT 'open' COMMENT 'open / resolved',
    `attempts`        INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'failed retries so far',
    `last_attempt_at` DATETIME        NULL COMMENT 'UTC',
    `last_error`      VARCHAR(64)     NOT NULL DEFAULT '' COMMENT 'stable error code of the last failed retry',
    `resolved_at`     DATETIME        NULL COMMENT 'UTC',
    `resolved_by`     VARCHAR(190)    NOT NULL DEFAULT '' COMMENT 'adminuser.username',
    `resolution`      VARCHAR(16)     NOT NULL DEFAULT '' COMMENT 'retried / dismissed, empty while open',
    `note`            VARCHAR(500)    NOT NULL DEFAULT '' COMMENT 'what the administrator wrote when dismissing',
    PRIMARY KEY (`id`),
    KEY `mailadmin_repair_queue_status` (`status`, `recorded_at`),
    KEY `mailadmin_repair_queue_operation` (`operation`, `recorded_at`),
    KEY `mailadmin_repair_queue_request` (`request_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'mailadmin: what a half-finished operation left behind (A6, architecture 8.2).';
