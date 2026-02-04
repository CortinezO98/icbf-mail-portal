<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class CasesRepo
{
    public function __construct(private PDO $pdo) {}

    public function listInbox(?string $statusCode, ?int $assignedUserId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));

        $where = [];
        $params = [];

        $sql = "SELECT
                  c.id, c.case_number, c.subject,
                  c.requester_email, c.requester_name,
                  c.received_at, c.due_at, c.sla_state, c.last_activity_at,
                  cs.code AS status_code, cs.name AS status_name,
                  u.full_name AS assigned_user_name
                FROM cases c
                JOIN case_statuses cs ON cs.id = c.status_id
                LEFT JOIN users u ON u.id = c.assigned_user_id";

        if ($statusCode) {
            $where[] = "cs.code = :scode";
            $params[':scode'] = $statusCode;
        }
        if ($assignedUserId !== null) {
            $where[] = "c.assigned_user_id = :uid";
            $params[':uid'] = $assignedUserId;
        }

        if ($where) $sql .= " WHERE " . implode(" AND ", $where);

        $sql .= " ORDER BY c.last_activity_at DESC, c.received_at DESC LIMIT {$limit}";

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function findCase(int $caseId): ?array
    {
        $sql = "SELECT
                  c.*,
                  cs.code AS status_code, cs.name AS status_name,
                  u.full_name AS assigned_user_name, u.username AS assigned_username
                FROM cases c
                JOIN case_statuses cs ON cs.id = c.status_id
                LEFT JOIN users u ON u.id = c.assigned_user_id
                WHERE c.id = :cid
                LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':cid' => $caseId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function getStatusIdByCode(string $code): ?int
    {
        $st = $this->pdo->prepare("SELECT id FROM case_statuses WHERE code=:c LIMIT 1");
        $st->execute([':c' => $code]);
        $row = $st->fetch();
        return $row ? (int)$row['id'] : null;
    }

    public function assignToUser(int $caseId, int $agentId, int $statusId): void
    {
        $sql = "UPDATE cases
                SET assigned_user_id = :aid,
                    status_id = :sid,
                    assigned_at = NOW(6),
                    last_activity_at = NOW(6),
                    updated_at = NOW(6)
                WHERE id = :cid";
        $st = $this->pdo->prepare($sql);
        $st->execute([':aid' => $agentId, ':sid' => $statusId, ':cid' => $caseId]);
    }
}
