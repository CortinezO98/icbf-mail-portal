-- D2 initial backfill. Execute ONLY after:
--   1) attachment_recovery table exists,
--   2) D2 code is deployed and worker is healthy,
--   3) d2_backfill_preview.sql was re-run and reviewed,
--   4) the operator explicitly authorizes the current counts.
--
-- This intentionally seeds only known 0/N messages. It does NOT touch messages
-- that already contain any legacy attachment with graph_attachment_id IS NULL.

START TRANSACTION;

INSERT INTO attachment_recovery (message_id, status, last_reason, first_seen_at)
SELECT m.id, 'pending', 'LEGACY_ZERO_OF_N_BACKFILL', NOW(6)
FROM messages m
LEFT JOIN attachments a ON a.message_id = m.id
WHERE m.has_attachments = 1
  AND a.id IS NULL
  AND m.received_at < '2026-03-07 00:00:00'
  AND NOT EXISTS (
      SELECT 1
      FROM attachments a2
      WHERE a2.message_id = m.id
        AND a2.graph_attachment_id IS NULL
  )
ON DUPLICATE KEY UPDATE message_id = VALUES(message_id);

SET @legacy_inserted := ROW_COUNT();

INSERT INTO attachment_recovery (message_id, status, last_reason, first_seen_at)
SELECT m.id, 'pending', 'ZERO_OF_N_BACKFILL', NOW(6)
FROM messages m
LEFT JOIN attachments a ON a.message_id = m.id
WHERE m.has_attachments = 1
  AND a.id IS NULL
  AND m.received_at >= '2026-03-07 00:00:00'
  AND NOT EXISTS (
      SELECT 1
      FROM attachments a2
      WHERE a2.message_id = m.id
        AND a2.graph_attachment_id IS NULL
  )
ON DUPLICATE KEY UPDATE message_id = VALUES(message_id);

SET @post_inserted := ROW_COUNT();

SELECT @legacy_inserted AS legacy_affected,
       @post_inserted AS post_affected,
       (@legacy_inserted + @post_inserted) AS total_affected;

SELECT status, last_reason, COUNT(*) AS rows_count
FROM attachment_recovery
GROUP BY status, last_reason
ORDER BY status, last_reason;

-- Operator checkpoint: inspect the result above BEFORE COMMIT.
COMMIT;
