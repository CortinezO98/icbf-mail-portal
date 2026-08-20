<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

/**
 * Estado operacional de presencia de agentes.
 *
 * Separación deliberada:
 * - users.assign_enabled: permiso administrativo para recibir asignaciones.
 * - agent_presence.status: disponibilidad operacional en este instante.
 * - last_seen_at: conexión viva; si expira, el agente se considera DESCONECTADO
 *   para asignación aunque su último estado seleccionado haya sido DISPONIBLE.
 */
final class AgentPresenceRepo
{
    public function __construct(private PDO $pdo)
    {
    }

    public function listSelectableStatuses(): array
    {
        $st = $this->pdo->query("
            SELECT id, code, name, color_hex, is_assignable, sort_order
            FROM agent_presence_statuses
            WHERE is_active = 1
              AND is_selectable = 1
            ORDER BY sort_order ASC, id ASC
        ");
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function getCurrent(int $userId): ?array
    {
        $st = $this->pdo->prepare("
            SELECT
                ap.user_id,
                aps.code,
                aps.name,
                aps.color_hex,
                aps.is_assignable,
                aps.is_selectable,
                ap.status_since,
                ap.last_seen_at,
                CASE WHEN ap.status_since IS NULL THEN NULL
                     ELSE TIMESTAMPDIFF(SECOND, ap.status_since, NOW(6)) END AS status_age_seconds,
                CASE WHEN ap.last_seen_at IS NULL THEN NULL
                     ELSE TIMESTAMPDIFF(SECOND, ap.last_seen_at, NOW(6)) END AS last_seen_age_seconds,
                ap.updated_at
            FROM agent_presence ap
            JOIN agent_presence_statuses aps ON aps.id = ap.status_id
            WHERE ap.user_id = :uid
            LIMIT 1
        ");
        $st->execute([':uid' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Cambia el estado actual y deja trazabilidad. Si el estado no cambia,
     * solo refresca last_seen_at. Por defecto solo permite estados elegibles
     * por el agente; logout usa allowNonSelectable=true para DESCONECTADO.
     */
    public function setStatus(
        int $userId,
        string $statusCode,
        ?int $changedByUserId,
        string $source = 'PORTAL',
        bool $allowNonSelectable = false
    ): array {
        $statusCode = strtoupper(trim($statusCode));
        if ($statusCode === '') {
            throw new \InvalidArgumentException('El estado es obligatorio.');
        }

        $startedTx = !$this->pdo->inTransaction();
        if ($startedTx) {
            $this->pdo->beginTransaction();
        }

        try {
            $sql = "
                SELECT id, code, name, color_hex, is_assignable, is_selectable
                FROM agent_presence_statuses
                WHERE code = :code
                  AND is_active = 1
            ";
            if (!$allowNonSelectable) {
                $sql .= " AND is_selectable = 1";
            }
            $sql .= " LIMIT 1";

            $st = $this->pdo->prepare($sql);
            $st->execute([':code' => $statusCode]);
            $status = $st->fetch(PDO::FETCH_ASSOC);
            if (!$status) {
                throw new \InvalidArgumentException('Estado de agente inválido o no seleccionable.');
            }

            $st = $this->pdo->prepare("
                SELECT status_id
                FROM agent_presence
                WHERE user_id = :uid
                LIMIT 1
                FOR UPDATE
            ");
            $st->execute([':uid' => $userId]);
            $currentStatusId = $st->fetchColumn();

            $newStatusId = (int)$status['id'];
            if ($currentStatusId !== false && (int)$currentStatusId === $newStatusId) {
                $st = $this->pdo->prepare("
                    UPDATE agent_presence
                    SET last_seen_at = NOW(6), updated_at = NOW(6)
                    WHERE user_id = :uid
                    LIMIT 1
                ");
                $st->execute([':uid' => $userId]);
            } else {
                $st = $this->pdo->prepare("
                    UPDATE agent_presence_history
                    SET ended_at = NOW(6)
                    WHERE user_id = :uid
                      AND ended_at IS NULL
                ");
                $st->execute([':uid' => $userId]);

                $st = $this->pdo->prepare("
                    INSERT INTO agent_presence (
                        user_id, status_id, status_since, last_seen_at, updated_at
                    ) VALUES (
                        :uid, :status_id, NOW(6), NOW(6), NOW(6)
                    )
                    ON DUPLICATE KEY UPDATE
                        status_id = VALUES(status_id),
                        status_since = NOW(6),
                        last_seen_at = NOW(6),
                        updated_at = NOW(6)
                ");
                $st->execute([
                    ':uid' => $userId,
                    ':status_id' => $newStatusId,
                ]);

                $st = $this->pdo->prepare("
                    INSERT INTO agent_presence_history (
                        user_id, status_id, started_at,
                        changed_by_user_id, source, created_at
                    ) VALUES (
                        :uid, :status_id, NOW(6),
                        :changed_by, :source, NOW(6)
                    )
                ");
                $st->execute([
                    ':uid' => $userId,
                    ':status_id' => $newStatusId,
                    ':changed_by' => $changedByUserId,
                    ':source' => substr(trim($source) ?: 'PORTAL', 0, 30),
                ]);
            }

            if ($startedTx) {
                $this->pdo->commit();
            }

            return $this->getCurrent($userId) ?? [
                'user_id' => $userId,
                'code' => $status['code'],
                'name' => $status['name'],
                'color_hex' => $status['color_hex'],
            ];
        } catch (\Throwable $e) {
            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Refresca la conexión del agente. Si el heartbeat anterior ya expiró,
     * una reconexión NUNCA reactiva automáticamente un antiguo DISPONIBLE:
     * primero vuelve a EN_LINEA_NO_ACD y exige que el agente seleccione
     * Disponible otra vez.
     */
    public function heartbeat(int $userId, int $staleSeconds = 90): void
    {
        $staleSeconds = max(30, $staleSeconds);
        $startedTx = !$this->pdo->inTransaction();
        if ($startedTx) {
            $this->pdo->beginTransaction();
        }

        try {
            $st = $this->pdo->prepare("
                SELECT
                    ap.status_id,
                    aps.code,
                    TIMESTAMPDIFF(SECOND, ap.last_seen_at, NOW(6)) AS seconds_since_seen
                FROM agent_presence ap
                JOIN agent_presence_statuses aps ON aps.id = ap.status_id
                WHERE ap.user_id = :uid
                LIMIT 1
                FOR UPDATE
            ");
            $st->execute([':uid' => $userId]);
            $current = $st->fetch(PDO::FETCH_ASSOC);

            if (!$current) {
                $this->setStatus(
                    $userId,
                    'EN_LINEA_NO_ACD',
                    $userId,
                    'HEARTBEAT_INIT',
                    false
                );
            } else {
                $age = $current['seconds_since_seen'] === null
                    ? PHP_INT_MAX
                    : (int)$current['seconds_since_seen'];

                if ($age > $staleSeconds) {
                    // Reconexión tras quedar lógicamente desconectado.
                    // Cerramos el tramo anterior y abrimos uno nuevo en NO ACD,
                    // incluso si el estado seleccionado anterior ya era NO ACD.
                    $st = $this->pdo->prepare("
                        SELECT id
                        FROM agent_presence_statuses
                        WHERE code = 'EN_LINEA_NO_ACD'
                          AND is_active = 1
                        LIMIT 1
                    ");
                    $st->execute();
                    $noAcdId = $st->fetchColumn();
                    if ($noAcdId === false) {
                        throw new \RuntimeException('No existe el estado EN_LINEA_NO_ACD.');
                    }

                    $st = $this->pdo->prepare("
                        UPDATE agent_presence_history
                        SET ended_at = NOW(6)
                        WHERE user_id = :uid
                          AND ended_at IS NULL
                    ");
                    $st->execute([':uid' => $userId]);

                    $st = $this->pdo->prepare("
                        UPDATE agent_presence
                        SET status_id = :status_id,
                            status_since = NOW(6),
                            last_seen_at = NOW(6),
                            updated_at = NOW(6)
                        WHERE user_id = :uid
                        LIMIT 1
                    ");
                    $st->execute([
                        ':status_id' => (int)$noAcdId,
                        ':uid' => $userId,
                    ]);

                    $st = $this->pdo->prepare("
                        INSERT INTO agent_presence_history (
                            user_id, status_id, started_at,
                            changed_by_user_id, source, created_at
                        ) VALUES (
                            :uid, :status_id, NOW(6),
                            :uid2, 'HEARTBEAT_RECONNECT', NOW(6)
                        )
                    ");
                    $st->execute([
                        ':uid' => $userId,
                        ':status_id' => (int)$noAcdId,
                        ':uid2' => $userId,
                    ]);
                } else {
                    $st = $this->pdo->prepare("
                        UPDATE agent_presence
                        SET last_seen_at = NOW(6), updated_at = NOW(6)
                        WHERE user_id = :uid
                        LIMIT 1
                    ");
                    $st->execute([':uid' => $userId]);
                }
            }

            if ($startedTx) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function markDisconnected(int $userId, ?int $changedByUserId = null): void
    {
        $this->setStatus(
            $userId,
            'DESCONECTADO',
            $changedByUserId,
            'LOGOUT',
            true
        );
    }

    /**
     * Snapshot para supervisor. La condición de conexión se deriva de
     * last_seen_at; no se reescribe el estado elegido por el agente cuando
     * un navegador simplemente deja de enviar heartbeat.
     */
    public function listAgentStatus(int $staleSeconds, int $maxActiveCases): array
    {
        $staleSeconds = max(30, $staleSeconds);
        $maxActiveCases = max(1, $maxActiveCases);

        $sql = "
            SELECT
                u.id AS user_id,
                u.full_name,
                u.username,
                u.email,
                u.assign_enabled,
                COALESCE(aps.code, 'DESCONECTADO') AS selected_status_code,
                COALESCE(aps.name, 'Desconectado') AS selected_status_name,
                COALESCE(aps.color_hex, '#94a3b8') AS selected_color_hex,
                ap.status_since,
                ap.last_seen_at,
                CASE WHEN ap.status_since IS NULL THEN NULL
                     ELSE TIMESTAMPDIFF(SECOND, ap.status_since, NOW(6)) END AS status_age_seconds,
                CASE WHEN ap.last_seen_at IS NULL THEN NULL
                     ELSE TIMESTAMPDIFF(SECOND, ap.last_seen_at, NOW(6)) END AS last_seen_age_seconds,
                CASE
                    WHEN ap.last_seen_at IS NULL THEN NULL
                    WHEN TIMESTAMPDIFF(SECOND, ap.last_seen_at, NOW(6)) > :stale_seconds_age
                    THEN TIMESTAMPDIFF(SECOND, ap.last_seen_at, NOW(6)) - :stale_seconds_age2
                    WHEN ap.status_since IS NULL THEN NULL
                    ELSE TIMESTAMPDIFF(SECOND, ap.status_since, NOW(6))
                END AS effective_status_age_seconds,
                CASE
                    WHEN ap.last_seen_at IS NULL
                      OR TIMESTAMPDIFF(SECOND, ap.last_seen_at, NOW(6)) > :stale_seconds
                    THEN 'DESCONECTADO'
                    ELSE COALESCE(aps.code, 'DESCONECTADO')
                END AS effective_status_code,
                CASE
                    WHEN ap.last_seen_at IS NULL
                      OR TIMESTAMPDIFF(SECOND, ap.last_seen_at, NOW(6)) > :stale_seconds2
                    THEN 'Desconectado'
                    ELSE COALESCE(aps.name, 'Desconectado')
                END AS effective_status_name,
                CASE
                    WHEN ap.last_seen_at IS NULL
                      OR TIMESTAMPDIFF(SECOND, ap.last_seen_at, NOW(6)) > :stale_seconds3
                    THEN '#94a3b8'
                    ELSE COALESCE(aps.color_hex, '#94a3b8')
                END AS effective_color_hex,
                COALESCE(loads.active_cases, 0) AS active_cases,
                GREATEST(:max_cases - COALESCE(loads.active_cases, 0), 0) AS free_slots,
                CASE
                    WHEN u.is_active = 1
                     AND u.assign_enabled = 1
                     AND aps.is_assignable = 1
                     AND ap.last_seen_at IS NOT NULL
                     AND TIMESTAMPDIFF(SECOND, ap.last_seen_at, NOW(6)) <= :stale_seconds4
                     AND COALESCE(loads.active_cases, 0) < :max_cases2
                    THEN 1 ELSE 0
                END AS is_assignable_now
            FROM users u
            LEFT JOIN agent_presence ap ON ap.user_id = u.id
            LEFT JOIN agent_presence_statuses aps ON aps.id = ap.status_id
            LEFT JOIN (
                SELECT c.assigned_user_id, COUNT(*) AS active_cases
                FROM cases c
                JOIN case_statuses cs ON cs.id = c.status_id
                WHERE c.assigned_user_id IS NOT NULL
                  AND cs.code IN ('ASIGNADO', 'EN_PROCESO')
                GROUP BY c.assigned_user_id
            ) loads ON loads.assigned_user_id = u.id
            WHERE u.is_active = 1
              AND EXISTS (
                    SELECT 1
                    FROM user_roles ur
                    JOIN roles r ON r.id = ur.role_id
                    WHERE ur.user_id = u.id
                      AND UPPER(TRIM(r.code)) IN ('AGENTE', 'AGENT')
              )
            ORDER BY
                is_assignable_now DESC,
                effective_status_name ASC,
                u.full_name ASC,
                u.id ASC
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':stale_seconds_age' => $staleSeconds,
            ':stale_seconds_age2' => $staleSeconds,
            ':stale_seconds' => $staleSeconds,
            ':stale_seconds2' => $staleSeconds,
            ':stale_seconds3' => $staleSeconds,
            ':stale_seconds4' => $staleSeconds,
            ':max_cases' => $maxActiveCases,
            ':max_cases2' => $maxActiveCases,
        ]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }


    /**
     * Debe ejecutarse dentro de una transacción. Bloquea la fila de presencia
     * del agente y vuelve a validar elegibilidad/carga. Comparte el mismo orden
     * de bloqueo que el assignment worker (caso -> presencia) para evitar que
     * una asignación manual pueda superar el límite configurado.
     */
    public function lockAssignableAgent(int $userId, int $staleSeconds, int $maxActiveCases): ?array
    {
        if (!$this->pdo->inTransaction()) {
            throw new \RuntimeException('lockAssignableAgent requiere una transacción activa.');
        }

        $staleSeconds = max(30, $staleSeconds);
        $maxActiveCases = max(1, $maxActiveCases);

        $st = $this->pdo->prepare("
            SELECT
                ap.user_id,
                u.is_active,
                u.assign_enabled,
                aps.code,
                aps.is_assignable,
                TIMESTAMPDIFF(SECOND, ap.last_seen_at, NOW(6)) AS seconds_since_seen
            FROM agent_presence ap
            JOIN users u ON u.id = ap.user_id
            JOIN agent_presence_statuses aps ON aps.id = ap.status_id
            WHERE ap.user_id = :uid
              AND EXISTS (
                    SELECT 1
                    FROM user_roles ur
                    JOIN roles r ON r.id = ur.role_id
                    WHERE ur.user_id = ap.user_id
                      AND UPPER(TRIM(r.code)) IN ('AGENTE', 'AGENT')
              )
            LIMIT 1
            FOR UPDATE
        ");
        $st->execute([':uid' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        if (
            (int)($row['is_active'] ?? 0) !== 1 ||
            (int)($row['assign_enabled'] ?? 0) !== 1 ||
            strtoupper((string)($row['code'] ?? '')) !== 'DISPONIBLE' ||
            (int)($row['is_assignable'] ?? 0) !== 1 ||
            (int)($row['seconds_since_seen'] ?? PHP_INT_MAX) > $staleSeconds
        ) {
            return null;
        }

        $st = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            WHERE c.assigned_user_id = :uid
              AND cs.code IN ('ASIGNADO', 'EN_PROCESO')
        ");
        $st->execute([':uid' => $userId]);
        $activeCases = (int)$st->fetchColumn();
        if ($activeCases >= $maxActiveCases) {
            return null;
        }

        return [
            'user_id' => $userId,
            'active_cases' => $activeCases,
            'free_slots' => max(0, $maxActiveCases - $activeCases),
        ];
    }

    public function getOperationalSummary(int $staleSeconds, int $maxActiveCases): array
    {
        $agents = $this->listAgentStatus($staleSeconds, $maxActiveCases);

        $byStatus = [];
        $availableAgents = 0;
        $capacity = 0;
        foreach ($agents as $agent) {
            $code = (string)($agent['effective_status_code'] ?? 'DESCONECTADO');
            $byStatus[$code] = ($byStatus[$code] ?? 0) + 1;
            if ((int)($agent['is_assignable_now'] ?? 0) === 1) {
                $availableAgents++;
                $capacity += max(0, (int)($agent['free_slots'] ?? 0));
            }
        }

        $pending = 0;
        try {
            $pending = (int)$this->pdo->query("
                SELECT COUNT(*)
                FROM cases c
                JOIN case_statuses cs ON cs.id = c.status_id
                WHERE c.assigned_user_id IS NULL
                  AND cs.code = 'NUEVO'
            ")->fetchColumn();
        } catch (\Throwable) {
            $pending = 0;
        }

        return [
            'total_agents' => count($agents),
            'available_agents' => $availableAgents,
            'available_capacity' => $capacity,
            'pending_queue' => $pending,
            'by_status' => $byStatus,
        ];
    }
}
