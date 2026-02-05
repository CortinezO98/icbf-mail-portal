<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class MetricsRepo
{
    public function __construct(private PDO $pdo) {}

    public function realtimeSummary(?int $assignedUserId = null): array
    {
        $where = [];
        $params = [];

        if ($assignedUserId !== null) {
            $where[] = "c.assigned_user_id = :uid";
            $params[':uid'] = $assignedUserId;
        }

        $sql = "
            SELECT
                -- Casos abiertos totales
                SUM(CASE WHEN cs.code <> 'CERRADO' THEN 1 ELSE 0 END) AS open_total,
                
                -- Distribución por estado
                SUM(CASE WHEN cs.code='NUEVO' THEN 1 ELSE 0 END) AS st_nuevo,
                SUM(CASE WHEN cs.code='ASIGNADO' THEN 1 ELSE 0 END) AS st_asignado,
                SUM(CASE WHEN cs.code='EN_PROCESO' THEN 1 ELSE 0 END) AS st_enproceso,
                SUM(CASE WHEN cs.code='RESPONDIDO' THEN 1 ELSE 0 END) AS st_respondido,
                SUM(CASE WHEN cs.code='CERRADO' THEN 1 ELSE 0 END) AS st_cerrado,
                
                -- SEMÁFORO BASADO EN DÍAS DESDE CREACIÓN (0-5 días)
                -- VERDE: 0-1 días
                SUM(CASE 
                    WHEN cs.code <> 'CERRADO' 
                    AND c.is_responded = 0
                    AND DATEDIFF(NOW(), c.created_at) <= 1
                    THEN 1 ELSE 0 
                END) AS sla_verde,
                
                -- AMARILLO: 2-3 días
                SUM(CASE 
                    WHEN cs.code <> 'CERRADO'
                    AND c.is_responded = 0
                    AND DATEDIFF(NOW(), c.created_at) BETWEEN 2 AND 3
                    THEN 1 ELSE 0 
                END) AS sla_amarillo,
                
                -- ROJO: 4+ días o vencidos
                SUM(CASE 
                    WHEN cs.code <> 'CERRADO'
                    AND c.is_responded = 0
                    AND DATEDIFF(NOW(), c.created_at) >= 4
                    THEN 1 ELSE 0 
                END) AS sla_rojo,
                
                -- Tiempo promedio de respuesta (solo respondidos)
                ROUND(AVG(CASE 
                    WHEN c.is_responded = 1 AND c.responded_at IS NOT NULL 
                    THEN TIMESTAMPDIFF(HOUR, c.created_at, c.responded_at) 
                    ELSE NULL 
                END), 1) as avg_response_hours

            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
        ";

        if ($where) $sql .= " WHERE " . implode(" AND ", $where);

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
                COUNT(*) AS total,
                SUM(CASE WHEN cs.code <> 'CERRADO' THEN 1 ELSE 0 END) AS open_total,
                
                -- Estados por agente
                SUM(CASE WHEN cs.code='NUEVO' THEN 1 ELSE 0 END) AS st_nuevo,
                SUM(CASE WHEN cs.code='ASIGNADO' THEN 1 ELSE 0 END) AS st_asignado,
                SUM(CASE WHEN cs.code='EN_PROCESO' THEN 1 ELSE 0 END) AS st_enproceso,
                
                -- SEMÁFORO POR AGENTE
                -- VERDE (0-1 días)
                SUM(CASE 
                    WHEN cs.code <> 'CERRADO' 
                    AND c.is_responded = 0
                    AND DATEDIFF(NOW(), c.created_at) <= 1
                    THEN 1 ELSE 0 
                END) AS verde,
                
                -- AMARILLO (2-3 días)
                SUM(CASE 
                    WHEN cs.code <> 'CERRADO'
                    AND c.is_responded = 0
                    AND DATEDIFF(NOW(), c.created_at) BETWEEN 2 AND 3
                    THEN 1 ELSE 0 
                END) AS amarillo,
                
                -- ROJO (4+ días)
                SUM(CASE 
                    WHEN cs.code <> 'CERRADO'
                    AND c.is_responded = 0
                    AND DATEDIFF(NOW(), c.created_at) >= 4
                    THEN 1 ELSE 0 
                END) AS rojo,
                
                -- Tasa de respuesta
                ROUND(SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0), 1) as response_rate

            FROM cases c
            JOIN users u ON u.id = c.assigned_user_id
            JOIN case_statuses cs ON cs.id = c.status_id
            WHERE u.is_active = 1
            GROUP BY u.id, u.full_name, u.email
            ORDER BY rojo DESC, amarillo DESC, open_total DESC
        ";

        return $this->pdo->query($sql)->fetchAll() ?: [];
    }

    public function updateSlaTracking(): int
    {
        $sql = "
            UPDATE case_sla_tracking cst
            JOIN cases c ON c.id = cst.case_id
            SET
                cst.days_since_creation = DATEDIFF(NOW(), c.created_at),
                cst.current_sla_state =
                    CASE
                        WHEN DATEDIFF(NOW(), c.created_at) <= 1 THEN 'VERDE'
                        WHEN DATEDIFF(NOW(), c.created_at) BETWEEN 2 AND 3 THEN 'AMARILLO'
                        ELSE 'ROJO'
                    END,
                cst.last_updated = NOW(6)
            WHERE c.status_id != (SELECT id FROM case_statuses WHERE code = 'CERRADO')
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function initializeSlaTracking(): int
    {
        $sql = "
            INSERT IGNORE INTO case_sla_tracking (case_id, days_since_creation, current_sla_state)
            SELECT 
                c.id,
                DATEDIFF(NOW(), c.created_at),
                CASE
                    WHEN DATEDIFF(NOW(), c.created_at) <= 1 THEN 'VERDE'
                    WHEN DATEDIFF(NOW(), c.created_at) BETWEEN 2 AND 3 THEN 'AMARILLO'
                    ELSE 'ROJO'
                END
            FROM cases c
            WHERE c.status_id != (SELECT id FROM case_statuses WHERE code = 'CERRADO')
            AND NOT EXISTS (
                SELECT 1 FROM case_sla_tracking cst 
                WHERE cst.case_id = c.id
            )
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function getSemaforoDistribution(?int $userId = null): array
    {
        $where = $userId ? "WHERE c.assigned_user_id = :user_id" : "";
        $params = $userId ? [':user_id' => $userId] : [];

        $sql = "
            SELECT
                'VERDE' as estado,
                COUNT(*) as total,
                '0-1 días desde creación' as descripcion,
                '#10b981' as color,
                'bi-check-circle-fill' as icono
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            WHERE cs.code <> 'CERRADO'
            AND c.is_responded = 0
            AND DATEDIFF(NOW(), c.created_at) <= 1
            {$where}
            
            UNION ALL
            
            SELECT
                'AMARILLO' as estado,
                COUNT(*) as total,
                '2-3 días desde creación' as descripcion,
                '#f59e0b' as color,
                'bi-exclamation-triangle-fill' as icono
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            WHERE cs.code <> 'CERRADO'
            AND c.is_responded = 0
            AND DATEDIFF(NOW(), c.created_at) BETWEEN 2 AND 3
            {$where}
            
            UNION ALL
            
            SELECT
                'ROJO' as estado,
                COUNT(*) as total,
                '4+ días desde creación' as descripcion,
                '#ef4444' as color,
                'bi-exclamation-octagon-fill' as icono
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            WHERE cs.code <> 'CERRADO'
            AND c.is_responded = 0
            AND DATEDIFF(NOW(), c.created_at) >= 4
            {$where}
            
            UNION ALL
            
            SELECT
                'RESPONDIDOS' as estado,
                COUNT(*) as total,
                'Casos ya contestados' as descripcion,
                '#3b82f6' as color,
                'bi-chat-square-text-fill' as icono
            FROM cases c
            WHERE c.is_responded = 1
            {$where}
            
            ORDER BY FIELD(estado, 'ROJO', 'AMARILLO', 'VERDE', 'RESPONDIDOS')
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    public function getCasesBySemaforo(string $semaforo, ?int $userId = null, int $limit = 20): array
    {
        $where = $userId ? "AND c.assigned_user_id = :user_id" : "";
        $params = [
            ':semaforo' => $semaforo,
            ':limit' => $limit
        ];
        
        if ($userId) {
            $params[':user_id'] = $userId;
        }

        $sql = "
            SELECT
                c.id,
                c.case_number,
                c.subject,
                c.requester_name,
                c.requester_email,
                cs.name as status_name,
                cs.code as status_code,
                u.full_name as assigned_to,
                c.created_at,
                DATEDIFF(NOW(), c.created_at) as dias_desde_creacion,
                CASE
                    WHEN DATEDIFF(NOW(), c.created_at) <= 1 THEN 'VERDE'
                    WHEN DATEDIFF(NOW(), c.created_at) BETWEEN 2 AND 3 THEN 'AMARILLO'
                    ELSE 'ROJO'
                END as semaforo_actual
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN users u ON u.id = c.assigned_user_id
            WHERE cs.code <> 'CERRADO'
            AND c.is_responded = 0
            AND (
                CASE
                    WHEN DATEDIFF(NOW(), c.created_at) <= 1 THEN 'VERDE'
                    WHEN DATEDIFF(NOW(), c.created_at) BETWEEN 2 AND 3 THEN 'AMARILLO'
                    ELSE 'ROJO'
                END
            ) = :semaforo
            {$where}
            ORDER BY c.created_at ASC
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    public function generateSemaforoReport(?int $userId = null): array
    {
        $where = $userId ? "AND c.assigned_user_id = :user_id" : "";
        $params = $userId ? [':user_id' => $userId] : [];

        $sql = "
            SELECT
                c.case_number,
                c.subject,
                c.requester_name,
                c.requester_email,
                cs.name as estado,
                u.full_name as asignado_a,
                c.created_at,
                DATEDIFF(NOW(), c.created_at) as dias_transcurridos,
                CASE
                    WHEN c.is_responded = 1 THEN 'RESPONDIDO'
                    WHEN DATEDIFF(NOW(), c.created_at) <= 1 THEN 'VERDE'
                    WHEN DATEDIFF(NOW(), c.created_at) BETWEEN 2 AND 3 THEN 'AMARILLO'
                    ELSE 'ROJO'
                END as semaforo,
                TIMESTAMPDIFF(HOUR, c.created_at, COALESCE(c.responded_at, NOW())) as horas_transcurridas
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN users u ON u.id = c.assigned_user_id
            WHERE 1=1 {$where}
            ORDER BY 
                CASE
                    WHEN DATEDIFF(NOW(), c.created_at) <= 1 THEN 1
                    WHEN DATEDIFF(NOW(), c.created_at) BETWEEN 2 AND 3 THEN 2
                    ELSE 3
                END ASC,
                c.created_at DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }
}