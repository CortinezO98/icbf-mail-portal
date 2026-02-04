<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class EventsRepo
{
    public function __construct(private PDO $pdo) {}

    public function listByCase(int $caseId): array
    {
        $sql = "SELECT
                  e.*,
                  u.full_name AS actor_name
                FROM case_events e
                LEFT JOIN users u ON u.id = e.actor_user_id
                WHERE e.case_id = :cid
                ORDER BY e.created_at ASC";
        $st = $this->pdo->prepare($sql);
        $st->execute([':cid' => $caseId]);
        return $st->fetchAll();
    }

    public function insertAssigned(
        int $caseId,
        ?int $actorUserId,
        int $assignedToUserId,
        ?int $fromStatusId,
        ?int $toStatusId,
        string $ipAddress,
        string $userAgent
    ): void {
        $details = json_encode([
            'assigned_to' => $assignedToUserId,
            'mode' => 'manual',
        ], JSON_UNESCAPED_UNICODE);

        $sql = "INSERT INTO case_events
                (case_id, actor_user_id, source, ip_address, user_agent, event_type,
                 from_status_id, to_status_id, details_json, created_at)
                VALUES
                (:cid, :actor, 'PORTAL', :ip, :ua, 'ASSIGNED',
                 :from_sid, :to_sid, :details, NOW(6))";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':cid' => $caseId,
            ':actor' => $actorUserId,
            ':ip' => $ipAddress !== '' ? $ipAddress : null,
            ':ua' => $userAgent !== '' ? $userAgent : null,
            ':from_sid' => $fromStatusId,
            ':to_sid' => $toStatusId,
            ':details' => $details,
        ]);
    }
}
