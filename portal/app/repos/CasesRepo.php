<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class CasesRepo
{
    public function __construct(private PDO $pdo) {}

    public function listInbox(?string $statusCode, ?int $assignedUserId, mixed $arg3 = '', ?int $arg4 = null, ?int $arg5 = null)
    {
        $numArgs = func_num_args();

        // Firma vieja:
        // listInbox($statusCode, $assignedUserId, $limit)
        if ($numArgs === 3 && is_int($arg3)) {
            $limit = max(1, min(500, (int)$arg3));
            return $this->listInboxLegacy($statusCode, $assignedUserId, '', $limit);
        }

        // Firma nueva:
        // listInbox($statusCode, $assignedUserId, $q, $page, $perPage)
        $q = is_string($arg3) ? trim($arg3) : '';
        $page = max(1, (int)($arg4 ?? 1));
        $perPage = max(1, min(100, (int)($arg5 ?? 20)));

        return $this->listInboxPaginated($statusCode, $assignedUserId, $q, $page, $perPage);
    }

    private function buildInboxFilters(?string $statusCode, ?int $assignedUserId, string $q = ''): array
    {
        $where = [];
        $params = [];

        $statusCode = strtoupper(trim((string)$statusCode));
        $q = trim($q);

        if ($statusCode !== '') {
            if (in_array($statusCode, ['ESCALADO', 'ESCALATED'], true)) {
                $where[] = "cs.code IN ('ESCALADO', 'ESCALATED')";
            } else {
                $where[] = "cs.code = :scode";
                $params[':scode'] = $statusCode;
            }
        }

        if ($assignedUserId !== null) {
            $where[] = "c.assigned_user_id = :uid";
            $params[':uid'] = $assignedUserId;
        }

        if ($q !== '') {
            $where[] = "(
                c.case_number LIKE :q_case_number
                OR c.subject LIKE :q_subject
                OR c.requester_name LIKE :q_requester_name
                OR c.requester_email LIKE :q_requester_email
                OR CONCAT('', c.id) LIKE :q_case_id
            )";

            $search = '%' . $q . '%';
            $params[':q_case_number'] = $search;
            $params[':q_subject'] = $search;
            $params[':q_requester_name'] = $search;
            $params[':q_requester_email'] = $search;
            $params[':q_case_id'] = $search;
        }

        return [$where, $params];
    }

    private function listInboxLegacy(?string $statusCode, ?int $assignedUserId, string $q = '', int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));

        [$where, $params] = $this->buildInboxFilters($statusCode, $assignedUserId, $q);

        $sql = "SELECT
                    c.id, c.case_number, c.subject,
                    c.requester_email, c.requester_name,

                    -- Bogota (asi se guarda en BD - alias '_utc' es
                    -- historicamente inexacto, se conserva por
                    -- compatibilidad; ver auditoria timezone pre-Fase D)
                    c.received_at       AS received_at_utc,
                    c.last_activity_at  AS last_activity_at_utc,

                    -- Ya esta en Bogota, no se resta nada (antes restaba
                    -- 5h de mas por error - doble conversion corregida)
                    c.received_at       AS received_at_bogota,
                    c.last_activity_at  AS last_activity_at_bogota,

                    c.due_at, c.sla_state,
                    c.assigned_user_id,
                    cs.code AS status_code, cs.name AS status_name,
                    u.full_name AS assigned_user_name
                FROM cases c
                JOIN case_statuses cs ON cs.id = c.status_id
                LEFT JOIN users u ON u.id = c.assigned_user_id";

        if ($where) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY c.last_activity_at DESC, c.received_at DESC LIMIT {$limit}";

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Versión nueva con paginación.
     * Devuelve ['data'=>..., 'pagination'=>...]
     */
    private function listInboxPaginated(
        ?string $statusCode,
        ?int $assignedUserId,
        string $q = '',
        int $page = 1,
        int $perPage = 20
    ): array {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        [$where, $params] = $this->buildInboxFilters($statusCode, $assignedUserId, $q);

        $countSql = "SELECT COUNT(*) as total
                     FROM cases c
                     JOIN case_statuses cs ON cs.id = c.status_id
                     LEFT JOIN users u ON u.id = c.assigned_user_id";

        $sql = "SELECT
                    c.id, c.case_number, c.subject,
                    c.requester_email, c.requester_name,

                    -- Bogota (asi se guarda en BD - alias '_utc' es
                    -- historicamente inexacto, se conserva por
                    -- compatibilidad; ver auditoria timezone pre-Fase D)
                    c.received_at       AS received_at_utc,
                    c.last_activity_at  AS last_activity_at_utc,

                    -- Ya esta en Bogota, no se resta nada (antes restaba
                    -- 5h de mas por error - doble conversion corregida)
                    c.received_at       AS received_at_bogota,
                    c.last_activity_at  AS last_activity_at_bogota,

                    c.due_at, c.sla_state,
                    c.assigned_user_id,
                    cs.code AS status_code, cs.name AS status_name,
                    u.full_name AS assigned_user_name
                FROM cases c
                JOIN case_statuses cs ON cs.id = c.status_id
                LEFT JOIN users u ON u.id = c.assigned_user_id";

        $whereClause = $where ? " WHERE " . implode(" AND ", $where) : "";

        $countSql .= $whereClause;
        $stCount = $this->pdo->prepare($countSql);
        $stCount->execute($params);
        $totalRows = (int)($stCount->fetchColumn() ?? 0);

        $totalPages = (int)ceil($totalRows / $perPage);
        if ($totalPages < 1) {
            $totalPages = 1;
        }

        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $sql .= $whereClause;
        $sql .= " ORDER BY c.last_activity_at DESC, c.received_at DESC
                  LIMIT :limit OFFSET :offset";

        $st = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $st->bindValue($key, $value);
        }
        $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);

        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
        return $this->listInboxLegacy($statusCode, $assignedUserId, '', $limit);
    }

    public function getInboxPagination(?string $statusCode, ?int $assignedUserId, int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        [$where, $params] = $this->buildInboxFilters($statusCode, $assignedUserId, '');

        $countSql = "SELECT COUNT(*) as total
                     FROM cases c
                     JOIN case_statuses cs ON cs.id = c.status_id
                     LEFT JOIN users u ON u.id = c.assigned_user_id";

        $whereClause = $where ? " WHERE " . implode(" AND ", $where) : "";

        $countSql .= $whereClause;
        $stCount = $this->pdo->prepare($countSql);
        $stCount->execute($params);
        $totalRows = (int)($stCount->fetchColumn() ?? 0);

        $totalPages = (int)ceil($totalRows / $perPage);
        if ($totalPages < 1) {
            $totalPages = 1;
        }

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
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * NUEVO:
     * Lista casos relacionados por el mismo thread_conversation_id,
     * excluyendo el caso actual.
     */
    public function listRelatedByThread(string $conversationId, int $excludeCaseId): array
    {
        $conversationId = trim($conversationId);
        if ($conversationId === '') {
            return [];
        }

        $sql = "
            SELECT
                c.id,
                c.case_number,
                c.subject,
                c.requester_email,
                c.requester_name,
                c.received_at,
                c.thread_conversation_id,
                c.parent_case_id,
                c.root_internet_message_id,
                c.reply_to_internet_message_id,
                cs.code AS status_code,
                cs.name AS status_name,
                u.full_name AS assigned_user_name
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN users u ON u.id = c.assigned_user_id
            WHERE c.thread_conversation_id = :conversation_id
              AND c.id <> :exclude_case_id
            ORDER BY c.received_at DESC, c.id DESC
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':conversation_id' => $conversationId,
            ':exclude_case_id' => $excludeCaseId,
        ]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Mantener para no romper: devuelve null si no existe.
     */
    public function getStatusIdByCode(string $code): ?int
    {
        $st = $this->pdo->prepare("SELECT id FROM case_statuses WHERE code=:c LIMIT 1");
        $st->execute([':c' => $code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    }

    /**
     * Estricto: lanza excepción si no existe.
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
     * Carga caso con lock (requiere transacción activa para que el lock tenga sentido).
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
        if (!$row) {
            throw new \RuntimeException("Caso no encontrado");
        }
        return $row;
    }

    /**
     * Escalar: EN_PROCESO -> ESCALATED (observación obligatoria)
     * Transacción incluida para que FOR UPDATE sea efectivo y el update sea atómico.
     */
    public function escalate(int $caseId, int $actorUserId, string $note): int
    {
        $note = trim($note);
        if ($note === '') {
            throw new \InvalidArgumentException("Observación de escalamiento obligatoria");
        }

        $this->pdo->beginTransaction();
        try {
            $case = $this->findCaseForUpdate($caseId);

            if ((int)($case['is_final'] ?? 0) === 1) {
                throw new \RuntimeException("Caso finalizado");
            }
            if (($case['status_code'] ?? '') !== 'EN_PROCESO') {
                throw new \RuntimeException("Solo se puede escalar cuando el caso está EN_PROCESO");
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
                LIMIT 1
            ");
            $st->execute([
                ':to_status' => $toStatusId,
                ':uid' => $actorUserId,
                ':note' => $note,
                ':id' => $caseId,
            ]);

            $this->pdo->commit();
            return $toStatusId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Cerrar: RESPONDIDO -> cerrado (observación + radicado obligatorios)
     * $closedCode: code real del estado final en tu BD.
     * Transacción incluida para atomicidad.
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

        $this->pdo->beginTransaction();
        try {
            $case = $this->findCaseForUpdate($caseId);

            if ((int)($case['is_final'] ?? 0) === 1) {
                throw new \RuntimeException("Caso ya finalizado");
            }
            if (($case['status_code'] ?? '') !== 'RESPONDIDO') {
                throw new \RuntimeException("Solo se puede cerrar cuando el caso está RESPONDIDO");
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
                LIMIT 1
            ");
            $st->execute([
                ':to_status' => $toStatusId,
                ':uid' => $actorUserId,
                ':ticket' => $ticket,
                ':note' => $note,
                ':id' => $caseId,
            ]);

            $this->pdo->commit();
            return $toStatusId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function assignToUser(int $caseId, int $agentId, int $statusId): void
    {
        $sql = "UPDATE cases
                SET assigned_user_id = :aid,
                    status_id = :sid,
                    assigned_at = NOW(6),
                    last_activity_at = NOW(6),
                    updated_at = NOW(6)
                WHERE id = :cid
                LIMIT 1";
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

    // R2: autoasignación automática movida al assignment worker.

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

    /**
     * Inicio de gestión: ASIGNADO -> EN_PROCESO
     * Mantiene firma original (incluye ip/ua) por compatibilidad.
     * Transacción incluida.
     */
    public function startProcess(int $caseId, int $actorUserId, string $ip, string $ua): void
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->pdo->prepare("
                SELECT c.id, c.assigned_user_id, c.status_id, cs.code AS status_code, c.in_process_at
                FROM cases c
                JOIN case_statuses cs ON cs.id = c.status_id
                WHERE c.id = :id
                FOR UPDATE
            ");
            $row->execute([':id' => $caseId]);
            $c = $row->fetch(PDO::FETCH_ASSOC);

            if (!$c) {
                throw new \RuntimeException("Caso no existe");
            }
            if ((int)$c['assigned_user_id'] !== $actorUserId) {
                throw new \RuntimeException("No eres el asignado");
            }
            if (($c['status_code'] ?? '') !== 'ASIGNADO') {
                throw new \RuntimeException("El caso no está en ASIGNADO");
            }

            $toStatusId = $this->requireStatusIdByCode('EN_PROCESO');

            $upd = $this->pdo->prepare("
                UPDATE cases
                SET status_id = :to_status,
                    in_process_at = COALESCE(in_process_at, NOW(6)),
                    last_activity_at = NOW(6),
                    updated_at = NOW(6)
                WHERE id = :id
                LIMIT 1
            ");
            $upd->execute([':to_status' => $toStatusId, ':id' => $caseId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Finaliza gestión: EN_PROCESO -> RESPONDIDO
     * Mantiene firma original por compatibilidad.
     * Transacción incluida.
     */
    public function finishProcess(int $caseId, int $actorUserId, string $ip, string $ua): void
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->pdo->prepare("
                SELECT c.id, c.assigned_user_id, c.status_id, cs.code AS status_code, c.first_response_at
                FROM cases c
                JOIN case_statuses cs ON cs.id = c.status_id
                WHERE c.id = :id
                FOR UPDATE
            ");
            $row->execute([':id' => $caseId]);
            $c = $row->fetch(PDO::FETCH_ASSOC);

            if (!$c) {
                throw new \RuntimeException("Caso no existe");
            }
            if ((int)$c['assigned_user_id'] !== $actorUserId) {
                throw new \RuntimeException("No eres el asignado");
            }
            if (($c['status_code'] ?? '') !== 'EN_PROCESO') {
                throw new \RuntimeException("El caso no está en EN_PROCESO");
            }

            $toStatusId = $this->requireStatusIdByCode('RESPONDIDO');

            $upd = $this->pdo->prepare("
                UPDATE cases
                SET status_id = :to_status,
                    first_response_at = COALESCE(first_response_at, NOW(6)),
                    is_responded = 1,
                    last_activity_at = NOW(6),
                    updated_at = NOW(6)
                WHERE id = :id
                LIMIT 1
            ");
            $upd->execute([':to_status' => $toStatusId, ':id' => $caseId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function finishEscalation(int $caseId, int $actorUserId): int
    {
        $this->pdo->beginTransaction();
        try {
            $case = $this->findCaseForUpdate($caseId);

            if ((int)($case['is_final'] ?? 0) === 1) {
                throw new \RuntimeException("Caso finalizado");
            }
            if (($case['status_code'] ?? '') !== 'ESCALATED') {
                throw new \RuntimeException("Solo se puede finalizar escalamiento cuando el caso está ESCALATED");
            }

            $toStatusId = $this->requireStatusIdByCode('EN_PROCESO');

            $st = $this->pdo->prepare("
                UPDATE cases
                SET
                status_id = :to_status,
                escalated_finished_at = NOW(6),
                escalated_finished_by_user_id = :uid,
                last_activity_at = NOW(6),
                updated_at = NOW(6)
                WHERE id = :id
                LIMIT 1
            ");
            $st->execute([
                ':to_status' => $toStatusId,
                ':uid' => $actorUserId,
                ':id' => $caseId,
            ]);

            $this->pdo->commit();
            return $toStatusId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }


    public function listAssignedByAgent(int $agentId, string $q = '', int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [
            "c.assigned_user_id = :agent_id",
            "cs.code = 'ASIGNADO'",
            "cs.is_final = 0",
        ];

        $params = [
            ':agent_id' => $agentId,
        ];

        $q = trim($q);
        if ($q !== '') {
            $where[] = "(
                c.case_number LIKE :q_case_number
                OR c.subject LIKE :q_subject
                OR c.requester_name LIKE :q_requester_name
                OR c.requester_email LIKE :q_requester_email
                OR CONCAT('', c.id) LIKE :q_case_id
            )";

            $search = '%' . $q . '%';
            $params[':q_case_number'] = $search;
            $params[':q_subject'] = $search;
            $params[':q_requester_name'] = $search;
            $params[':q_requester_email'] = $search;
            $params[':q_case_id'] = $search;
        }

        $whereClause = ' WHERE ' . implode(' AND ', $where);

        $countSql = "
            SELECT COUNT(*)
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            {$whereClause}
        ";

        $stCount = $this->pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $stCount->bindValue($key, $value);
        }
        $stCount->execute();
        $totalRows = (int)($stCount->fetchColumn() ?? 0);

        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $sql = "
            SELECT
                c.id,
                c.case_number,
                c.subject,
                c.requester_name,
                c.requester_email,
                c.received_at,
                c.last_activity_at,
                c.assigned_at,
                c.status_id,
                cs.code AS status_code,
                cs.name AS status_name,
                u.full_name AS assigned_user_name
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN users u ON u.id = c.assigned_user_id
            {$whereClause}
            ORDER BY c.assigned_at DESC, c.last_activity_at DESC, c.id DESC
            LIMIT :limit OFFSET :offset
        ";

        $st = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value);
        }
        $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();

        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
            ],
            'summary' => [
                'total_assigned' => $totalRows,
            ],
        ];
    }

    public function lockAssignedCasesForReassign(array $caseIds, int $sourceAgentId): array
    {
        $caseIds = array_values(array_unique(array_filter(array_map('intval', $caseIds), fn($v) => $v > 0)));
        if (empty($caseIds)) {
            return [];
        }

        $placeholders = [];
        $params = [
            ':source_agent_id' => $sourceAgentId,
        ];

        foreach ($caseIds as $index => $caseId) {
            $ph = ':cid_' . $index;
            $placeholders[] = $ph;
            $params[$ph] = $caseId;
        }

        $sql = "
            SELECT
                c.id,
                c.status_id,
                c.assigned_user_id,
                cs.code AS status_code,
                cs.is_final
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            WHERE c.assigned_user_id = :source_agent_id
            AND cs.code = 'ASIGNADO'
            AND cs.is_final = 0
            AND c.id IN (" . implode(',', $placeholders) . ")
            FOR UPDATE
        ";

        $st = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value, PDO::PARAM_INT);
        }
        $st->execute();

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function reassignAssignedCase(int $caseId, int $sourceAgentId, int $targetAgentId, int $statusAsignadoId): bool
    {
        $sql = "
            UPDATE cases
            SET
                assigned_user_id = :target_agent_id,
                status_id = :status_id,
                assigned_at = NOW(6),
                last_activity_at = NOW(6),
                updated_at = NOW(6)
            WHERE id = :case_id
            AND assigned_user_id = :source_agent_id
            AND status_id = :status_id
            LIMIT 1
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':target_agent_id' => $targetAgentId,
            ':status_id' => $statusAsignadoId,
            ':case_id' => $caseId,
            ':source_agent_id' => $sourceAgentId,
        ]);

        return $st->rowCount() > 0;
    }


}