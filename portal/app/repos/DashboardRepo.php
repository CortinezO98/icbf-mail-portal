<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class DashboardRepo
{
    public function __construct(private PDO $pdo) {}

    public function getDashboardData(): array
    {
        $sql = "
            SELECT
                DATE(c.created_at) as date,
                COUNT(*) as total_cases,
                SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) as responded,
                SUM(CASE WHEN cs.code = 'CERRADO' THEN 1 ELSE 0 END) as closed
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(c.created_at)
            ORDER BY date DESC
            LIMIT 30
        ";
        
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getStatusDistribution(?int $userId = null): array
    {
        $where = $userId ? "AND c.assigned_user_id = :user_id" : "";
        $params = $userId ? [':user_id' => $userId] : [];
        
        $sql = "
            SELECT 
                cs.name as status,
                cs.code,
                COUNT(*) as count,
                ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM cases WHERE 1=1 {$where})), 1) as percentage
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            WHERE 1=1 {$where}
            GROUP BY cs.id, cs.name, cs.code
            ORDER BY COUNT(*) DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getSLACompliance(?int $userId = null): array
    {
        $where = $userId ? "WHERE c.assigned_user_id = :user_id" : "";
        $params = $userId ? [':user_id' => $userId] : [];
        
        $sql = "
            SELECT
                'Cumplidos' as label,
                SUM(CASE WHEN c.is_responded = 1 OR (c.due_at IS NULL OR NOW() <= c.due_at) THEN 1 ELSE 0 END) as value,
                '#10b981' as color
            FROM cases c
            {$where}
            UNION ALL
            SELECT
                'Por Vencer (<24h)' as label,
                SUM(CASE WHEN c.is_responded = 0 AND c.due_at IS NOT NULL 
                     AND NOW() <= c.due_at AND NOW() >= (c.due_at - INTERVAL 1 DAY)
                     THEN 1 ELSE 0 END) as value,
                '#f59e0b' as color
            FROM cases c
            {$where}
            UNION ALL
            SELECT
                'Vencidos' as label,
                SUM(CASE WHEN c.is_responded = 0 AND c.due_at IS NOT NULL AND NOW() > c.due_at
                     THEN 1 ELSE 0 END) as value,
                '#ef4444' as color
            FROM cases c
            {$where}
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getAgentPerformance(): array
    {
        $sql = "
            SELECT
                u.full_name as agent,
                COUNT(c.id) as total_cases,
                SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) as responded,
                ROUND(SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(c.id), 1) as response_rate,
                AVG(TIMESTAMPDIFF(HOUR, c.created_at, COALESCE(c.first_response_at, NOW()))) as avg_response_hours
            FROM cases c
            JOIN users u ON u.id = c.assigned_user_id
            WHERE u.is_active = 1
            GROUP BY u.id, u.full_name
            ORDER BY response_rate DESC
            LIMIT 10
        ";
        
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getDailyCasesTrend(int $days = 7, ?int $userId = null): array
    {
        $where = $userId ? "AND c.assigned_user_id = :user_id" : "";
        $params = $userId ? [':user_id' => $userId] : [];
        
        $sql = "
            SELECT
                DATE(c.created_at) as date,
                COUNT(*) as new_cases,
                SUM(CASE WHEN cs.code = 'CERRADO' THEN 1 ELSE 0 END) as closed_cases,
                SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) as responded_cases
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            {$where}
            GROUP BY DATE(c.created_at)
            ORDER BY date
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$days], $params));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getMetricsByCategory(?int $userId = null): array
    {
        $where = $userId ? "AND c.assigned_user_id = :user_id" : "";
        $params = $userId ? [':user_id' => $userId] : [];
        
        $sql = "
            SELECT
                cat.name as category,
                COUNT(*) as total,
                SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) as responded,
                SUM(CASE WHEN c.is_responded = 0 AND c.due_at IS NOT NULL AND NOW() > c.due_at THEN 1 ELSE 0 END) as overdue,
                ROUND(AVG(TIMESTAMPDIFF(HOUR, c.created_at, COALESCE(c.first_response_at, NOW()))), 1) as avg_hours
            FROM cases c
            LEFT JOIN categories cat ON cat.id = c.category_id
            WHERE 1=1 {$where}
            GROUP BY cat.id, cat.name
            ORDER BY total DESC
            LIMIT 10
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getWeeklyTrends(?int $userId = null): array
    {
        $where = $userId ? "AND assigned_user_id = :user_id" : "";
        $params = $userId ? [':user_id' => $userId] : [];
        
        $sql = "
            SELECT
                DAYNAME(created_at) as day_name,
                DAYOFWEEK(created_at) as day_num,
                COUNT(*) as cases_count,
                SUM(CASE WHEN is_responded = 1 THEN 1 ELSE 0 END) as responded_count
            FROM cases
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            {$where}
            GROUP BY DAYNAME(created_at), DAYOFWEEK(created_at)
            ORDER BY day_num
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}