<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class CasesRepo
{
    public function __construct(private PDO $pdo) {}

    /**
     * Compatibilidad:
     * - Si se llama con 3 args o menos, usa el modo legacy (limit).
     * - Si se llama con 4 args, usa paginación.
     */
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
        $page = max(1, $page);
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

        $totalPages = (int)ceil($totalRows / $perPage);
        if ($totalPages < 1) $totalPages = 1;
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $sql .= $whereClause;
        $sql .= " ORDER BY c.last_activity_at DESC, c.received_at DESC
                  LIMIT :limit OFFSET :offset";

        $st = $this->pdo->prepare($sql);

        // bind filters
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value);
        }
        // bind limit/offset
        $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);

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
                'offset' => $offset,
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
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

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

        $countSql .= $whereClause;
        $stCount = $this->pdo->prepare($countSql);
        $stCount->execute($params);
        $totalRows = (int)($stCount->fetchColumn() ?? 0);

        $totalPages = (int)ceil($totalRows / $perPage);
        if ($totalPages < 1) $totalPages = 1;
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

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

    /**
     * ✅ Se mantiene para NO romper: devuelve null si no existe.
     */
    public function getStatusIdByCode(string $code): ?int
    {
        $st = $this->pdo->prepare("SELECT id FROM case_statuses WHERE code=:c LIMIT 1");
        $st->execute([':c' => $code]);
        $row = $st->fetch();
        return $row ? (int)$row['id'] : null;
    }

    /**
     * ✅ Nuevo (estricto): lanza excepción si no existe.
     * Úsalo en flujos críticos como cerrar/escalar.
     */
    public function requireStatusIdByCode(string $code): int
    {
        $id = $this->getStatusIdByCode($code);
        if (!$id) {
            throw new \RuntimeException("No existe status code={$code}");
        }
        return $id;
    }

    /**
     * ✅ Nuevo: carga el caso con lock (concurrencia segura).
     */
    public function findCaseForUpdate(int $caseId): array
    {
        $st = $this->pdo->prepare("
            SELECT c.*, cs.code AS status_code, cs.is_final, cs.pauses_sla
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            WHERE c.id = :id
            LIMIT 1
            FOR UPDATE
        ");
        $st->execute([':id' => $caseId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException("Caso no encontrado");
        return $row;
    }

    /**
     * ✅ Nuevo: escalar con observación obligatoria.
     * Requiere que exista status code ESCALATED.
     */
    public function escalate(int $caseId, int $actorUserId, string $note): int
    {
        $note = trim($note);
        if ($note === '') {
            throw new \InvalidArgumentException("Observación de escalamiento obligatoria");
        }

        $case = $this->findCaseForUpdate($caseId);
        if ((int)($case['is_final'] ?? 0) === 1) {
            throw new \RuntimeException("Caso finalizado");
        }

        $toStatusId = $this->requireStatusIdByCode('ESCALATED');

        $st = $this->pdo->prepare("
            UPDATE cases
            SET
              status_id = :to_status,
              escalated_at = NOW(6),
              escalated_by_user_id = :uid,
              escalated_note = :note,
              last_activity_at = NOW(6),
              updated_at = NOW(6)
            WHERE id = :id
        ");
        $st->execute([
            ':to_status' => $toStatusId,
            ':uid' => $actorUserId,
            ':note' => $note,
            ':id' => $caseId,
        ]);

        return $toStatusId;
    }

    /**
     * ✅ Nuevo: cerrar con observación obligatoria + radicado obligatorio.
     * $closedCode debe ser el code real del cerrado en tu BD (ej: 'CLOSED' o 'CERRADO').
     */
    public function close(int $caseId, int $actorUserId, string $note, string $ticket, string $closedCode): int
    {
        $note = trim($note);
        $ticket = trim($ticket);

        if ($note === '') {
            throw new \InvalidArgumentException("Observación de cierre obligatoria");
        }
        if ($ticket === '') {
            throw new \InvalidArgumentException("Radicado obligatorio");
        }

        // Si lo quieren estrictamente numérico, descomenta:
        // if (!preg_match('/^\d+$/', $ticket)) {
        //     throw new \InvalidArgumentException("El radicado debe ser numérico");
        // }

        $case = $this->findCaseForUpdate($caseId);
        if ((int)($case['is_final'] ?? 0) === 1) {
            throw new \RuntimeException("Caso ya finalizado");
        }

        $toStatusId = $this->requireStatusIdByCode($closedCode);

        $st = $this->pdo->prepare("
            UPDATE cases
            SET
              status_id = :to_status,
              closed_at = NOW(6),
              closed_by_user_id = :uid,
              closed_ticket = :ticket,
              closed_note = :note,
              last_activity_at = NOW(6),
              updated_at = NOW(6)
            WHERE id = :id
        ");
        $st->execute([
            ':to_status' => $toStatusId,
            ':uid' => $actorUserId,
            ':ticket' => $ticket,
            ':note' => $note,
            ':id' => $caseId,
        ]);

        return $toStatusId;
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
