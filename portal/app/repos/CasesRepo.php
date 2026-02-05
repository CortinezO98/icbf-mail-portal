<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class CasesRepo
{
    public function __construct(private PDO $pdo) {}

    public function listInbox(?string $statusCode, ?int $assignedUserId, int $page = 1, int $perPage = 20)
    {
        $numArgs = func_num_args();
        
        if ($numArgs <= 2) {
            $limit = func_get_arg(2) ?? 200;
            return $this->listInboxLegacy($statusCode, $assignedUserId, $limit);
        }

        return $this->listInboxPaginated($statusCode, $assignedUserId, $page, $perPage);
    }

    private function listInboxLegacy(?string $statusCode, ?int $assignedUserId, int $limit = 200): array
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

    /**
     * Versión nueva con paginación
     */
    private function listInboxPaginated(?string $statusCode, ?int $assignedUserId, int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        // Contar total de registros
        $countSql = "SELECT COUNT(*) as total
                     FROM cases c
                     JOIN case_statuses cs ON cs.id = c.status_id
                     LEFT JOIN users u ON u.id = c.assigned_user_id";

        // Consulta principal
        $sql = "SELECT
                  c.id, c.case_number, c.subject,
                  c.requester_email, c.requester_name,
                  c.received_at, c.due_at, c.sla_state, c.last_activity_at,
                  c.assigned_user_id,
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

        $whereClause = $where ? " WHERE " . implode(" AND ", $where) : "";

        // Contar total
        $countSql .= $whereClause;
        $stCount = $this->pdo->prepare($countSql);
        $stCount->execute($params);
        $totalRows = (int)($stCount->fetchColumn() ?? 0);
        $totalPages = ceil($totalRows / $perPage);

        $sql .= $whereClause;
        $sql .= " ORDER BY c.last_activity_at DESC, c.received_at DESC 
                  LIMIT :limit OFFSET :offset";

        $params[':limit'] = $perPage;
        $params[':offset'] = $offset;

        $st = $this->pdo->prepare($sql);
        
        foreach ($params as $key => $value) {
            if ($key === ':limit' || $key === ':offset') {
                $st->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $st->bindValue($key, $value);
            }
        }
        
        $st->execute();
        $rows = $st->fetchAll();

        return [
            'data' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total_rows' => $totalRows,
                'total_pages' => $totalPages,
                'has_prev' => $page > 1,
                'has_next' => $page < $totalPages,
                'offset' => $offset
            ]
        ];
    }

    public function listInboxData(?string $statusCode, ?int $assignedUserId, int $limit = 200): array
    {
        return $this->listInboxLegacy($statusCode, $assignedUserId, $limit);
    }

    public function getInboxPagination(?string $statusCode, ?int $assignedUserId, int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        // Contar total de registros
        $countSql = "SELECT COUNT(*) as total
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

        $whereClause = $where ? " WHERE " . implode(" AND ", $where) : "";

        // Contar total
        $countSql .= $whereClause;
        $stCount = $this->pdo->prepare($countSql);
        $stCount->execute($params);
        $totalRows = (int)($stCount->fetchColumn() ?? 0);
        $totalPages = ceil($totalRows / $perPage);

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages,
            'offset' => $offset
        ];
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

    public function listPendingUnassignedIds(int $statusNuevoId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));

        $sql = "SELECT id
                FROM cases
                WHERE assigned_user_id IS NULL
                AND status_id = :nuevo
                ORDER BY received_at ASC
                LIMIT {$limit}";
        $st = $this->pdo->prepare($sql);
        $st->execute([':nuevo' => $statusNuevoId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public function assignToUserIfUnassigned(int $caseId, int $agentId, int $statusAsignadoId): bool
    {
        $sql = "UPDATE cases
                SET assigned_user_id = :aid,
                    status_id = :sid,
                    assigned_at = NOW(6),
                    last_activity_at = NOW(6),
                    updated_at = NOW(6)
                WHERE id = :cid
                AND assigned_user_id IS NULL
                LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':aid' => $agentId, ':sid' => $statusAsignadoId, ':cid' => $caseId]);
        return $st->rowCount() > 0;
    }

    public function countUnassignedByStatus(int $statusId): int
    {
        $st = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM cases
            WHERE assigned_user_id IS NULL
            AND status_id = :sid
        ");
        $st->execute([':sid' => $statusId]);
        return (int)$st->fetchColumn();
    }
}