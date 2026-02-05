<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class MetricsRepo
{
    public function __construct(private PDO $pdo) {}

    /**
     * Fuente de tiempo operativa: cases.received_at (no created_at).
     * cases tiene received_at/due_at/sla_state, y además existe case_sla_tracking para tracking rápido.
     */
    private function clockField(): string
    {
        return 'c.received_at';
    }

    private function baseOpenWhere(?int $assignedUserId, array &$params): string
    {
        $w = [];
        // En tu esquema case_statuses tiene is_final/pauses_sla
        $w[] = "cs.is_final = 0"; // abierto
        if ($assignedUserId !== null) {
            $w[] = "c.assigned_user_id = :uid";
            $params[':uid'] = $assignedUserId;
        }
        return $w ? ("WHERE " . implode(" AND ", $w)) : "";
    }

    public function realtimeSummary(?int $assignedUserId = null): array
    {
        $params = [];
        $whereOpen = $this->baseOpenWhere($assignedUserId, $params);

        // Semáforo desde tracking (si existe), fallback a cálculo por received_at
        $sql = "
            SELECT
                COUNT(*) AS total_open,

                -- Distribución por estado (solo abiertos)
                SUM(CASE WHEN cs.code='NUEVO' THEN 1 ELSE 0 END) AS st_nuevo,
                SUM(CASE WHEN cs.code='ASIGNADO' THEN 1 ELSE 0 END) AS st_asignado,
                SUM(CASE WHEN cs.code='EN_PROCESO' THEN 1 ELSE 0 END) AS st_enproceso,
                SUM(CASE WHEN cs.code='RESPONDIDO' THEN 1 ELSE 0 END) AS st_respondido,

                -- Semáforo (solo NO respondidos)
                SUM(CASE
                    WHEN c.is_responded = 0 AND COALESCE(cst.current_sla_state,
                        CASE
                            WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) <= 1 THEN 'VERDE'
                            WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) BETWEEN 2 AND 3 THEN 'AMARILLO'
                            ELSE 'ROJO'
                        END
                    ) = 'VERDE'
                    THEN 1 ELSE 0
                END) AS sla_verde,

                SUM(CASE
                    WHEN c.is_responded = 0 AND COALESCE(cst.current_sla_state,
                        CASE
                            WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) <= 1 THEN 'VERDE'
                            WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) BETWEEN 2 AND 3 THEN 'AMARILLO'
                            ELSE 'ROJO'
                        END
                    ) = 'AMARILLO'
                    THEN 1 ELSE 0
                END) AS sla_amarillo,

                SUM(CASE
                    WHEN c.is_responded = 0 AND COALESCE(cst.current_sla_state,
                        CASE
                            WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) <= 1 THEN 'VERDE'
                            WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) BETWEEN 2 AND 3 THEN 'AMARILLO'
                            ELSE 'ROJO'
                        END
                    ) = 'ROJO'
                    THEN 1 ELSE 0
                END) AS sla_rojo,

                -- Respondidos (en general, no solo abiertos)
                (SELECT COUNT(*) FROM cases c2
                 WHERE c2.is_responded = 1
                 " . ($assignedUserId !== null ? " AND c2.assigned_user_id = :uid " : "") . "
                ) AS responded_total,

                -- Tiempo promedio de primera respuesta (solo respondidos)
                ROUND(AVG(CASE
                    WHEN c.is_responded = 1 AND c.first_response_at IS NOT NULL
                    THEN TIMESTAMPDIFF(HOUR, {$this->clockField()}, c.first_response_at)
                    ELSE NULL
                END), 1) AS avg_response_hours

            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
            {$whereOpen}
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetch() ?: [];
    }

    public function realtimeByAgent(): array
    {
        $sql = "
            SELECT
                u.id AS user_id,
                u.full_name,
                u.email,

                COUNT(*) AS total_open,

                SUM(CASE WHEN cs.code='NUEVO' THEN 1 ELSE 0 END) AS st_nuevo,
                SUM(CASE WHEN cs.code='ASIGNADO' THEN 1 ELSE 0 END) AS st_asignado,
                SUM(CASE WHEN cs.code='EN_PROCESO' THEN 1 ELSE 0 END) AS st_enproceso,

                SUM(CASE WHEN c.is_responded = 0 AND COALESCE(cst.current_sla_state,'VERDE') = 'VERDE' THEN 1 ELSE 0 END) AS verde,
                SUM(CASE WHEN c.is_responded = 0 AND COALESCE(cst.current_sla_state,'VERDE') = 'AMARILLO' THEN 1 ELSE 0 END) AS amarillo,
                SUM(CASE WHEN c.is_responded = 0 AND COALESCE(cst.current_sla_state,'VERDE') = 'ROJO' THEN 1 ELSE 0 END) AS rojo,

                ROUND(
                    SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0),
                    1
                ) AS response_rate

            FROM cases c
            JOIN users u ON u.id = c.assigned_user_id
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
            WHERE u.is_active = 1
              AND cs.is_final = 0
            GROUP BY u.id, u.full_name, u.email
            ORDER BY rojo DESC, amarillo DESC, total_open DESC
        ";

        return $this->pdo->query($sql)->fetchAll() ?: [];
    }

    /**
     * Inicializa tracking para casos abiertos.
     * - days/minutes se calculan desde received_at
     * - sla_due_at = received_at + 5 días (SLA simplificado)
     * - breached = NOW() > sla_due_at
     * - pauses_sla: si el estado pausa SLA, lo dejamos en VERDE y sin breach
     */
    public function initializeSlaTracking(): int
    {
        $sql = "
            INSERT IGNORE INTO case_sla_tracking
                (case_id, current_sla_state, days_since_creation, minutes_since_creation, sla_due_at, breached, last_updated, created_at)
            SELECT
                c.id AS case_id,
                CASE
                    WHEN cs.pauses_sla = 1 THEN 'VERDE'
                    WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) <= 1 THEN 'VERDE'
                    WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) BETWEEN 2 AND 3 THEN 'AMARILLO'
                    ELSE 'ROJO'
                END AS current_sla_state,
                TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) AS days_since_creation,
                TIMESTAMPDIFF(MINUTE, {$this->clockField()}, NOW()) AS minutes_since_creation,
                DATE_ADD({$this->clockField()}, INTERVAL 5 DAY) AS sla_due_at,
                CASE
                    WHEN cs.pauses_sla = 1 THEN 0
                    WHEN NOW() > DATE_ADD({$this->clockField()}, INTERVAL 5 DAY) THEN 1
                    ELSE 0
                END AS breached,
                NOW(6) AS last_updated,
                NOW(6) AS created_at
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            WHERE cs.is_final = 0
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function updateSlaTracking(): int
    {
        $sql = "
            UPDATE case_sla_tracking cst
            JOIN cases c ON c.id = cst.case_id
            JOIN case_statuses cs ON cs.id = c.status_id
            SET
                cst.minutes_since_creation = TIMESTAMPDIFF(MINUTE, {$this->clockField()}, NOW()),
                cst.days_since_creation = TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()),
                cst.sla_due_at = DATE_ADD({$this->clockField()}, INTERVAL 5 DAY),
                cst.current_sla_state =
                    CASE
                        WHEN cs.pauses_sla = 1 THEN 'VERDE'
                        WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) <= 1 THEN 'VERDE'
                        WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) BETWEEN 2 AND 3 THEN 'AMARILLO'
                        ELSE 'ROJO'
                    END,
                cst.breached =
                    CASE
                        WHEN cs.pauses_sla = 1 THEN 0
                        WHEN NOW() > DATE_ADD({$this->clockField()}, INTERVAL 5 DAY) THEN 1
                        ELSE 0
                    END,
                cst.last_updated = NOW(6)
            WHERE cs.is_final = 0
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function getSemaforoDistribution(?int $userId = null): array
    {
        // IMPORTANTÍSIMO: aquí NO metemos "WHERE ..." porque cada bloque ya tiene WHERE.
        $andUser = $userId ? " AND c.assigned_user_id = :user_id " : "";
        $params = $userId ? [':user_id' => $userId] : [];

        $sql = "
            SELECT
                'ROJO' AS estado,
                COUNT(*) AS total,
                '4+ días desde recibido' AS descripcion,
                '#ef4444' AS color,
                'bi-exclamation-octagon-fill' AS icono
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
            WHERE cs.is_final = 0
              AND c.is_responded = 0
              AND COALESCE(cst.current_sla_state,
                    CASE
                        WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) <= 1 THEN 'VERDE'
                        WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) BETWEEN 2 AND 3 THEN 'AMARILLO'
                        ELSE 'ROJO'
                    END
                ) = 'ROJO'
              {$andUser}

            UNION ALL

            SELECT
                'AMARILLO' AS estado,
                COUNT(*) AS total,
                '2-3 días desde recibido' AS descripcion,
                '#f59e0b' AS color,
                'bi-exclamation-triangle-fill' AS icono
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
            WHERE cs.is_final = 0
              AND c.is_responded = 0
              AND COALESCE(cst.current_sla_state,
                    CASE
                        WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) <= 1 THEN 'VERDE'
                        WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) BETWEEN 2 AND 3 THEN 'AMARILLO'
                        ELSE 'ROJO'
                    END
                ) = 'AMARILLO'
              {$andUser}

            UNION ALL

            SELECT
                'VERDE' AS estado,
                COUNT(*) AS total,
                '0-1 días desde recibido' AS descripcion,
                '#10b981' AS color,
                'bi-check-circle-fill' AS icono
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
            WHERE cs.is_final = 0
              AND c.is_responded = 0
              AND COALESCE(cst.current_sla_state,
                    CASE
                        WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) <= 1 THEN 'VERDE'
                        WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) BETWEEN 2 AND 3 THEN 'AMARILLO'
                        ELSE 'ROJO'
                    END
                ) = 'VERDE'
              {$andUser}

            UNION ALL

            SELECT
                'RESPONDIDOS' AS estado,
                COUNT(*) AS total,
                'Casos ya contestados' AS descripcion,
                '#3b82f6' AS color,
                'bi-chat-square-text-fill' AS icono
            FROM cases c
            WHERE c.is_responded = 1
              " . ($userId ? " AND c.assigned_user_id = :user_id " : "") . "

            ORDER BY FIELD(estado, 'ROJO', 'AMARILLO', 'VERDE', 'RESPONDIDOS')
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    public function getCasesBySemaforo(string $semaforo, ?int $userId = null, int $limit = 20): array
    {
        $semaforo = strtoupper(trim($semaforo));
        if (!in_array($semaforo, ['VERDE','AMARILLO','ROJO'], true)) {
            return [];
        }

        $andUser = $userId ? " AND c.assigned_user_id = :user_id " : "";

        $sql = "
            SELECT
                c.id,
                c.case_number,
                c.subject,
                c.requester_name,
                c.requester_email,
                cs.name AS status_name,
                cs.code AS status_code,
                u.full_name AS assigned_to,
                c.received_at,
                c.assigned_at,
                c.first_response_at,
                COALESCE(cst.days_since_creation, TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW())) AS dias_desde_recibido,
                COALESCE(cst.minutes_since_creation, TIMESTAMPDIFF(MINUTE, {$this->clockField()}, NOW())) AS minutos_desde_recibido,
                COALESCE(cst.current_sla_state,
                    CASE
                        WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) <= 1 THEN 'VERDE'
                        WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) BETWEEN 2 AND 3 THEN 'AMARILLO'
                        ELSE 'ROJO'
                    END
                ) AS semaforo_actual,
                COALESCE(cst.breached, 0) AS breached,
                cst.sla_due_at

            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN users u ON u.id = c.assigned_user_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id

            WHERE cs.is_final = 0
              AND c.is_responded = 0
              AND COALESCE(cst.current_sla_state,
                    CASE
                        WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) <= 1 THEN 'VERDE'
                        WHEN TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()) BETWEEN 2 AND 3 THEN 'AMARILLO'
                        ELSE 'ROJO'
                    END
                ) = :semaforo
              {$andUser}

            ORDER BY c.received_at ASC
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':semaforo', $semaforo);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        if ($userId) {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public function getWeeklyTrends(?int $userId = null): array
    {
        $andUser = $userId ? " AND c.assigned_user_id = :user_id " : "";
        $params = $userId ? [':user_id' => $userId] : [];

        $sql = "
            SELECT
                DATE(c.received_at) AS fecha,
                COUNT(*) AS total_casos,
                SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) AS respondidos,
                ROUND(AVG(
                    CASE
                        WHEN c.is_responded = 1 AND c.first_response_at IS NOT NULL
                        THEN TIMESTAMPDIFF(HOUR, c.received_at, c.first_response_at)
                        ELSE NULL
                    END
                ), 1) AS tiempo_promedio_respuesta
            FROM cases c
            WHERE c.received_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            {$andUser}
            GROUP BY DATE(c.received_at)
            ORDER BY fecha ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    public function getExecutiveReport(): array
    {
        $sql = "
            SELECT
                (SELECT COUNT(*) FROM cases) AS total_casos,
                (SELECT COUNT(*) FROM cases c
                  JOIN case_statuses cs ON cs.id = c.status_id
                 WHERE cs.is_final = 0
                ) AS abiertos,
                (SELECT COUNT(*) FROM cases WHERE is_responded = 1) AS respondidos,

                (SELECT JSON_ARRAYAGG(
                    JSON_OBJECT(
                        'agente', u.full_name,
                        'casos_asignados', COUNT(c.id),
                        'casos_resueltos', SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END),
                        'tasa_respuesta', ROUND(SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0), 1)
                    )
                )
                FROM cases c
                JOIN users u ON u.id = c.assigned_user_id
                WHERE u.is_active = 1
                GROUP BY u.id
                ORDER BY COUNT(*) DESC
                LIMIT 5) AS top_agentes_json,

                (SELECT COUNT(*) FROM cases c
                 JOIN case_statuses cs ON cs.id = c.status_id
                 LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
                 WHERE cs.is_final = 0
                   AND c.is_responded = 0
                   AND COALESCE(cst.current_sla_state,'VERDE') = 'VERDE'
                ) AS verde,

                (SELECT COUNT(*) FROM cases c
                 JOIN case_statuses cs ON cs.id = c.status_id
                 LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
                 WHERE cs.is_final = 0
                   AND c.is_responded = 0
                   AND COALESCE(cst.current_sla_state,'VERDE') = 'AMARILLO'
                ) AS amarillo,

                (SELECT COUNT(*) FROM cases c
                 JOIN case_statuses cs ON cs.id = c.status_id
                 LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
                 WHERE cs.is_final = 0
                   AND c.is_responded = 0
                   AND COALESCE(cst.current_sla_state,'VERDE') = 'ROJO'
                ) AS rojo,

                ROUND(AVG(CASE
                    WHEN c.is_responded = 1 AND c.first_response_at IS NOT NULL
                    THEN TIMESTAMPDIFF(HOUR, c.received_at, c.first_response_at)
                    ELSE NULL
                END), 1) AS tiempo_promedio_horas
            FROM cases c
        ";

        $result = $this->pdo->query($sql)->fetch();
        if (!$result) return [];

        if (!empty($result['top_agentes_json'])) {
            $result['top_agentes'] = json_decode((string)$result['top_agentes_json'], true) ?: [];
        } else {
            $result['top_agentes'] = [];
        }
        unset($result['top_agentes_json']);

        return $result;
    }

    public function getDailyMetrics(): array
    {
        $sql = "
            SELECT
                DATE(c.received_at) AS fecha,
                COUNT(*) AS total,
                SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) AS respondidos,
                SUM(CASE WHEN c.is_responded = 0 THEN 1 ELSE 0 END) AS pendientes,
                ROUND(AVG(
                    CASE
                        WHEN c.is_responded = 1 AND c.first_response_at IS NOT NULL
                        THEN TIMESTAMPDIFF(HOUR, c.received_at, c.first_response_at)
                        ELSE NULL
                    END
                ), 1) AS tiempo_promedio
            FROM cases c
            WHERE c.received_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(c.received_at)
            ORDER BY fecha DESC
            LIMIT 10
        ";

        return $this->pdo->query($sql)->fetchAll() ?: [];
    }
}
