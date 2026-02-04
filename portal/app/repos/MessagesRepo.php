<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class MessagesRepo
{
    public function __construct(private PDO $pdo) {}

    public function listByCase(int $caseId): array
    {
        $sql = "SELECT
                  id, case_id, direction,
                  from_email, to_emails, cc_emails,
                  subject, body_text, body_html,
                  received_at, sent_at, created_at
                FROM messages
                WHERE case_id = :cid
                ORDER BY
                  COALESCE(received_at, sent_at, created_at) ASC";
        $st = $this->pdo->prepare($sql);
        $st->execute([':cid' => $caseId]);
        return $st->fetchAll();
    }
}
