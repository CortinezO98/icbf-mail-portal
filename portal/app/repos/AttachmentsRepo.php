<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class AttachmentsRepo
{
    public function __construct(private PDO $pdo) {}

    public function listByCase(int $caseId): array
    {
        $sql = "SELECT
                  a.id, a.message_id, a.filename, a.content_type, a.size_bytes, a.sha256, a.storage_path, a.created_at
                FROM attachments a
                JOIN messages m ON m.id = a.message_id
                WHERE m.case_id = :cid
                ORDER BY a.created_at ASC";
        $st = $this->pdo->prepare($sql);
        $st->execute([':cid' => $caseId]);
        return $st->fetchAll();
    }

    public function findWithCase(int $attachmentId): ?array
    {
        $sql = "SELECT
                  a.*,
                  m.case_id
                FROM attachments a
                JOIN messages m ON m.id = a.message_id
                WHERE a.id = :aid
                LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':aid' => $attachmentId]);
        $row = $st->fetch();
        return $row ?: null;
    }
}
