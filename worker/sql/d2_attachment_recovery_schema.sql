-- D2 - Attachment Recovery operational state
-- MariaDB 10.11+
-- Safe rollout order: schema first, then code, then worker, then backfill.
-- Do NOT run the backfill in this file; this creates only the operational table.

CREATE TABLE attachment_recovery (
    message_id BIGINT UNSIGNED NOT NULL,

    status ENUM('pending','verifying','complete','blocked')
        NOT NULL DEFAULT 'pending',

    expected_count INT UNSIGNED DEFAULT NULL,
    downloaded_count INT UNSIGNED NOT NULL DEFAULT 0,
    manifest_hash CHAR(64) DEFAULT NULL,

    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    locked_at DATETIME(6) DEFAULT NULL,

    last_reason VARCHAR(80) DEFAULT NULL,
    last_error VARCHAR(1000) DEFAULT NULL,

    first_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_checked_at DATETIME(6) DEFAULT NULL,
    verified_at DATETIME(6) DEFAULT NULL,
    completed_at DATETIME(6) DEFAULT NULL,

    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),

    PRIMARY KEY (message_id),
    KEY idx_attachment_recovery_claim (status, available_at),

    CONSTRAINT fk_attachment_recovery_message
        FOREIGN KEY (message_id) REFERENCES messages(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
