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
                    WHEN c.is_responded = 1 AND c.first_response_at IS NOT NULL 
                    THEN TIMESTAMPDIFF(HOUR, c.created_at, c.first_response_at) 
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
        
        // Enlazar parámetros uno por uno para especificar el tipo de :limit
        $stmt->bindValue(':semaforo', $semaforo);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT); // ← ¡IMPORTANTE!
        
        if ($userId) {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        }
        
        $stmt->execute();
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
                TIMESTAMPDIFF(HOUR, c.created_at, COALESCE(c.first_response_at, NOW())) as horas_transcurridas
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

    public function getWeeklyTrends(?int $userId = null): array
    {
        $where = $userId ? "AND c.assigned_user_id = :user_id" : "";
        $params = $userId ? [':user_id' => $userId] : [];

        $sql = "
            SELECT
                DATE(c.created_at) as fecha,
                COUNT(*) as total_casos,
                SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) as respondidos,
                ROUND(AVG(
                    CASE 
                        WHEN c.is_responded = 1 AND c.first_response_at IS NOT NULL 
                        THEN TIMESTAMPDIFF(HOUR, c.created_at, c.first_response_at)
                        ELSE NULL 
                    END
                ), 1) as tiempo_promedio_respuesta
            FROM cases c
            WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            {$where}
            GROUP BY DATE(c.created_at)
            ORDER BY fecha ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    public function getExecutiveReport(): array
    {
        // Reporte ejecutivo para administradores
        $sql = "
            SELECT
                -- Resumen general
                (SELECT COUNT(*) FROM cases) as total_casos,
                (SELECT COUNT(*) FROM cases WHERE status_id IN 
                    (SELECT id FROM case_statuses WHERE code NOT IN ('CERRADO', 'RESPONDIDO'))) as abiertos,
                (SELECT COUNT(*) FROM cases WHERE is_responded = 1) as respondidos,
                
                -- Por agente (top 5)
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
                LIMIT 5) as top_agentes_json,
                
                -- Semáforo actual
                (SELECT COUNT(*) FROM cases c
                WHERE c.is_responded = 0
                AND DATEDIFF(NOW(), c.created_at) <= 1) as verde,
                (SELECT COUNT(*) FROM cases c
                WHERE c.is_responded = 0
                AND DATEDIFF(NOW(), c.created_at) BETWEEN 2 AND 3) as amarillo,
                (SELECT COUNT(*) FROM cases c
                WHERE c.is_responded = 0
                AND DATEDIFF(NOW(), c.created_at) >= 4) as rojo,
                
                -- Tiempos
                ROUND(AVG(CASE 
                    WHEN c.is_responded = 1 AND c.first_response_at IS NOT NULL 
                    THEN TIMESTAMPDIFF(HOUR, c.created_at, c.first_response_at) 
                    ELSE NULL 
                END), 1) as tiempo_promedio_horas
            FROM cases c
        ";

        $result = $this->pdo->query($sql)->fetch();
        
        if (!$result) {
            return [];
        }
        
        // Parsear JSON si existe
        if (!empty($result['top_agentes_json'])) {
            $result['top_agentes'] = json_decode($result['top_agentes_json'], true) ?: [];
        } else {
            $result['top_agentes'] = [];
        }
        
        unset($result['top_agentes_json']);
        
        return $result;
    }

    // Método auxiliar para reporte
    public function getDailyMetrics(): array
    {
        $sql = "
            SELECT
                DATE(c.created_at) as fecha,
                COUNT(*) as total,
                SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) as respondidos,
                SUM(CASE WHEN c.is_responded = 0 THEN 1 ELSE 0 END) as pendientes,
                ROUND(AVG(
                    CASE 
                        WHEN c.is_responded = 1 AND c.first_response_at IS NOT NULL 
                        THEN TIMESTAMPDIFF(HOUR, c.created_at, c.first_response_at)
                        ELSE NULL 
                    END
                ), 1) as tiempo_promedio
            FROM cases c
            WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(c.created_at)
            ORDER BY fecha DESC
            LIMIT 10
        ";

        return $this->pdo->query($sql)->fetchAll() ?: [];
    }



// REPORTERÍA Y EXPORTACIÓN

    public function generateReport(array $params): array
    {
        $where = [];
        $queryParams = [];
        
        // Filtro por fecha
        if (!empty($params['start_date'])) {
            $where[] = "c.created_at >= :start_date";
            $queryParams[':start_date'] = $params['start_date'] . ' 00:00:00';
        }
        
        if (!empty($params['end_date'])) {
            $where[] = "c.created_at <= :end_date";
            $queryParams[':end_date'] = $params['end_date'] . ' 23:59:59';
        }
        
        // Filtro por estado
        if (!empty($params['status'])) {
            $where[] = "cs.code = :status";
            $queryParams[':status'] = $params['status'];
        }
        
        // Filtro por agente
        if (!empty($params['agent_id']) && $params['agent_id'] > 0) {
            $where[] = "c.assigned_user_id = :agent_id";
            $queryParams[':agent_id'] = $params['agent_id'];
        }
        
        // Filtro por semáforo
        if (!empty($params['semaforo'])) {
            $semaforo = strtoupper($params['semaforo']);
            $semaforoWhere = "";
            
            switch ($semaforo) {
                case 'VERDE':
                    $semaforoWhere = "DATEDIFF(NOW(), c.created_at) <= 1";
                    break;
                case 'AMARILLO':
                    $semaforoWhere = "DATEDIFF(NOW(), c.created_at) BETWEEN 2 AND 3";
                    break;
                case 'ROJO':
                    $semaforoWhere = "DATEDIFF(NOW(), c.created_at) >= 4";
                    break;
            }
            
            if ($semaforoWhere) {
                $where[] = $semaforoWhere;
                $where[] = "c.is_responded = 0";
            }
        }
        
        $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";
        
        $sql = "
            SELECT
                c.id,
                c.case_number,
                c.subject,
                c.requester_email,
                c.requester_name,
                c.created_at,
                c.assigned_at,
                c.first_response_at,
                c.is_responded,
                cs.code as status_code,
                cs.name as status_name,
                u.full_name as assigned_to,
                u.email as assigned_email,
                DATEDIFF(NOW(), c.created_at) as dias_desde_creacion,
                CASE
                    WHEN c.is_responded = 1 THEN 'RESPONDIDO'
                    WHEN DATEDIFF(NOW(), c.created_at) <= 1 THEN 'VERDE'
                    WHEN DATEDIFF(NOW(), c.created_at) BETWEEN 2 AND 3 THEN 'AMARILLO'
                    ELSE 'ROJO'
                END as semaforo,
                CASE 
                    WHEN c.is_responded = 1 AND c.first_response_at IS NOT NULL 
                    THEN TIMESTAMPDIFF(HOUR, c.created_at, c.first_response_at)
                    ELSE NULL 
                END as horas_respuesta
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN users u ON u.id = c.assigned_user_id
            {$whereClause}
            ORDER BY c.created_at DESC
            LIMIT 1000
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($queryParams);
        
        return $stmt->fetchAll() ?: [];
    }

    public function getAgentsForReport(): array
    {
        $sql = "
            SELECT u.id, u.full_name, u.email
            FROM users u
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN roles r ON r.id = ur.role_id AND r.code = 'AGENTE'
            WHERE u.is_active = 1
            ORDER BY u.full_name
        ";
        
        return $this->pdo->query($sql)->fetchAll() ?: [];
    }

    public function getReportSummary(array $params): array
    {
        $data = $this->generateReport($params);
        
        if (empty($data)) {
            return [
                'total_cases' => 0,
                'responded' => 0,
                'pending' => 0,
                'avg_response_hours' => 0,
                'by_status' => [],
                'by_semaforo' => []
            ];
        }
        
        $summary = [
            'total_cases' => count($data),
            'responded' => 0,
            'pending' => 0,
            'avg_response_hours' => 0,
            'by_status' => [],
            'by_semaforo' => []
        ];
        
        $totalResponseHours = 0;
        $responseCount = 0;
        
        foreach ($data as $row) {
            if ($row['is_responded']) {
                $summary['responded']++;
                if ($row['horas_respuesta'] !== null) {
                    $totalResponseHours += $row['horas_respuesta'];
                    $responseCount++;
                }
            } else {
                $summary['pending']++;
            }
            
            // Agrupar por estado
            $status = $row['status_code'];
            if (!isset($summary['by_status'][$status])) {
                $summary['by_status'][$status] = [
                    'name' => $row['status_name'],
                    'count' => 0
                ];
            }
            $summary['by_status'][$status]['count']++;
            
            // Agrupar por semáforo
            $semaforo = $row['semaforo'];
            if (!isset($summary['by_semaforo'][$semaforo])) {
                $summary['by_semaforo'][$semaforo] = [
                    'count' => 0,
                    'color' => $this->getSemaforoColor($semaforo)
                ];
            }
            $summary['by_semaforo'][$semaforo]['count']++;
        }
        
        $summary['avg_response_hours'] = $responseCount > 0 
            ? round($totalResponseHours / $responseCount, 1) 
            : 0;
        
        return $summary;
    }

    private function getSemaforoColor(string $semaforo): string
    {
        return match($semaforo) {
            'VERDE' => 'success',
            'AMARILLO' => 'warning',
            'ROJO' => 'danger',
            'RESPONDIDO' => 'primary',
            default => 'secondary'
        };
    }

    public function saveGeneratedReport(string $reportType, string $filePath, ?int $generatedBy = null): int
    {
        $sql = "
            INSERT INTO generated_reports 
            (report_type, report_date, file_path, generated_by, created_at)
            VALUES (:type, CURDATE(), :path, :generated_by, NOW(6))
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':type' => $reportType,
            ':path' => $filePath,
            ':generated_by' => $generatedBy
        ]);
        
        return (int)$this->pdo->lastInsertId();
    }

    public function incrementReportDownload(int $reportId): void
    {
        $sql = "
            UPDATE generated_reports 
            SET download_count = download_count + 1,
                updated_at = NOW(6)
            WHERE id = :id
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $reportId]);
    }

    public function getRecentReports(int $limit = 10): array
    {
        $sql = "
            SELECT 
                r.*,
                u.full_name as generated_by_name
            FROM generated_reports r
            LEFT JOIN users u ON u.id = r.generated_by
            ORDER BY r.created_at DESC
            LIMIT :limit
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll() ?: [];
    }   



}