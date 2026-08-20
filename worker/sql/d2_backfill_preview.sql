-- D2 backfill PREVIEW ONLY. Read-only.
-- Expected historical baseline from the August 2026 preflight was 4 legacy + 26 post = 30.
-- Production is live: if these counts changed, STOP and review before inserting anything.

SELECT COUNT(*) AS legacy_zero_of_n
FROM messages m
LEFT JOIN attachments a ON a.message_id = m.id
WHERE m.has_attachments = 1
  AND a.id IS NULL
  AND m.received_at < '2026-03-07 00:00:00';

SELECT COUNT(*) AS post_graph_id_zero_of_n
FROM messages m
LEFT JOIN attachments a ON a.message_id = m.id
WHERE m.has_attachments = 1
  AND a.id IS NULL
  AND m.received_at >= '2026-03-07 00:00:00';

SELECT COUNT(*) AS legacy_identity_unknown_messages
FROM (
    SELECT DISTINCT a.message_id
    FROM attachments a
    WHERE a.graph_attachment_id IS NULL
) x;

-- Safety: these rows MUST NOT be automatically seeded into D2 recovery.
SELECT COUNT(DISTINCT m.id) AS legacy_null_candidates_that_must_stay_excluded
FROM messages m
JOIN attachments a ON a.message_id = m.id
WHERE m.has_attachments = 1
  AND a.graph_attachment_id IS NULL;
