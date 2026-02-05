<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class ReportsRepo
{
    public function __construct(private PDO $pdo) {}

    public function dashboard(string $startDate, string $endDate, ?int $mailboxId = null): array
    {
        // Tu fuente de verdad para rangos operativos es cases.received_at:contentReference[oaicite:15]{index=15}
        $whereMailbox = $mailboxId ? " AND c.mailbox_id = :mb " : "";

        // KPIs principales (abiertos/cerrados/semáforo)
        $sql = "
            SELECT
              COUNT(*) AS total_cases,
              SUM(CASE WHEN cs.is_final = 0 THEN 1 ELSE 0 END) AS open_cases,
              SUM(CASE WHEN cs.is_final = 1 THEN 1 ELSE 0 END) AS closed_cases,
              SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) AS responded_cases,
              SUM(CASE WHEN COALESCE(cst.breached,0) = 1 THEN 1 ELSE 0 END) AS breached_cases,
              SUM(CASE WHEN cst.current_sla_state='VERDE' THEN 1 ELSE 0 END) AS sla_verde,
              SUM(CASE WHEN cst.current_sla_state='AMARILLO' THEN 1 ELSE 0 END) AS sla_amarillo,
              SUM(CASE WHEN cst.current_sla_state='ROJO' THEN 1 ELSE 0 END) AS sla_rojo
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
            WHERE DATE(c.received_at) BETWEEN :s AND :e
            $whereMailbox
        ";
        $st = $this->pdo->prepare($sql);
        $st->bindValue(':s', $startDate);
        $st->bindValue(':e', $endDate);
        if ($mailboxId) $st->bindValue(':mb', $mailboxId, PDO::PARAM_INT);
        $st->execute();
        $kpis = $st->fetch() ?: [];

        // Serie diaria (recibidos)
        $sqlDaily = "
            SELECT DATE(c.received_at) AS day, COUNT(*) AS cnt
            FROM cases c
            WHERE DATE(c.received_at) BETWEEN :s AND :e
            $whereMailbox
            GROUP BY DATE(c.received_at)
            ORDER BY day ASC
        ";
        $st = $this->pdo->prepare($sqlDaily);
        $st->bindValue(':s', $startDate);
        $st->bindValue(':e', $endDate);
        if ($mailboxId) $st->bindValue(':mb', $mailboxId, PDO::PARAM_INT);
        $st->execute();
        $daily = $st->fetchAll();

        // “Gaps” de adjuntos: messages.has_attachments=1 pero no existen filas en attachments
        // (sirve para detectar fallas de sync del worker)
        $sqlMissing = "
            SELECT COUNT(*) AS missing_attachments
            FROM (
              SELECT m.id
              FROM messages m
              LEFT JOIN attachments a ON a.message_id = m.id
              WHERE DATE(m.created_at) BETWEEN :s AND :e
                AND m.has_attachments = 1
              GROUP BY m.id
              HAVING COUNT(a.id) = 0
            ) x
        ";
        $st = $this->pdo->prepare($sqlMissing);
        $st->execute([':s'=>$startDate, ':e'=>$endDate]);
        $missing = (int)($st->fetchColumn() ?: 0);

        return [
            'kpis' => $kpis,
            'daily' => $daily,
            'missing_attachments' => $missing,
        ];
    }

    public function agentsMetrics(string $startDate, string $endDate): array
    {
        // Cache pro: agent_daily_metrics ya existe e indexada:contentReference[oaicite:16]{index=16}
        $sql = "
            SELECT
              u.id AS agent_id,
              u.full_name AS agent_name,
              SUM(adm.cases_assigned) AS cases_assigned,
              SUM(adm.cases_resolved) AS cases_resolved,
              SUM(adm.cases_overdue) AS cases_overdue,
              ROUND(AVG(NULLIF(adm.avg_response_hours,0)), 2) AS avg_response_hours,
              ROUND(AVG(NULLIF(adm.sla_compliance_rate,0)), 2) AS sla_compliance_rate
            FROM agent_daily_metrics adm
            JOIN users u ON u.id = adm.agent_id
            WHERE adm.metric_date BETWEEN :s AND :e
            GROUP BY u.id, u.full_name
            ORDER BY cases_overdue DESC, cases_assigned DESC
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([':s'=>$startDate, ':e'=>$endDate]);
        return $st->fetchAll();
    }

    public function exportSlaDataset(string $startDate, string $endDate, ?int $mailboxId = null): array
    {
        $whereMailbox = $mailboxId ? " AND c.mailbox_id = :mb " : "";

        // Dataset alineado a cases.* reales:contentReference[oaicite:17]{index=17}
        $sql = "
            SELECT
              c.id AS case_id,
              c.mailbox_id,
              c.case_number,
              c.subject,
              c.requester_email,
              c.requester_name,
              cs.code AS status_code,
              cs.name AS status_name,
              c.assigned_user_id,
              u.full_name AS assigned_user,
              c.received_at,
              c.assigned_at,
              c.first_response_at,
              c.closed_at,
              c.is_responded,
              c.due_at,
              c.sla_state,
              cst.current_sla_state,
              COALESCE(cst.breached,0) AS breached,
              cst.sla_due_at,
              cst.minutes_since_creation,
              cst.days_since_creation,
              cst.last_updated
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN users u ON u.id = c.assigned_user_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
            WHERE DATE(c.received_at) BETWEEN :s AND :e
            $whereMailbox
            ORDER BY c.received_at DESC
        ";
        $st = $this->pdo->prepare($sql);
        $st->bindValue(':s', $startDate);
        $st->bindValue(':e', $endDate);
        if ($mailboxId) $st->bindValue(':mb', $mailboxId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public function insertGeneratedReport(int $userId, string $reportType, string $filePath, array $params, string $periodStart, string $periodEnd): void
    {
        // generated_reports tiene params/params_hash/period_start/end en tu DDL:contentReference[oaicite:18]{index=18}
        $paramsJson = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $paramsJson ?: '');

        $sql = "
          INSERT INTO generated_reports
            (report_type, report_date, file_path, download_count, generated_by, created_at, params, params_hash, period_start, period_end)
          VALUES
            (:rt, CURDATE(), :fp, 0, :uid, NOW(6), :pj, :ph, :ps, :pe)
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':rt'=>$reportType,
            ':fp'=>$filePath,
            ':uid'=>$userId,
            ':pj'=>$paramsJson,
            ':ph'=>$hash,
            ':ps'=>$periodStart,
            ':pe'=>$periodEnd,
        ]);
    }

    public function getReportById(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM generated_reports WHERE id = :id LIMIT 1");
        $st->execute([':id'=>$id]);
        $r = $st->fetch();
        return $r ?: null;
    }

    public function incrementDownloadCount(int $id): void
    {
        $st = $this->pdo->prepare("UPDATE generated_reports SET download_count = download_count + 1 WHERE id = :id");
        $st->execute([':id'=>$id]);
    }


    public function detailedDailyMetrics(string $startDate, string $endDate, ?int $mailboxId = null): array
    {
        $whereMailbox = $mailboxId ? " AND c.mailbox_id = :mb " : "";
        
        $sql = "
            SELECT 
                DATE(c.received_at) AS day,
                COUNT(*) AS total_cases,
                SUM(CASE WHEN cs.is_final = 0 THEN 1 ELSE 0 END) AS open_cases,
                SUM(CASE WHEN cs.is_final = 1 THEN 1 ELSE 0 END) AS closed_cases,
                SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) AS responded_cases,
                SUM(CASE WHEN COALESCE(cst.breached,0) = 1 THEN 1 ELSE 0 END) AS breached_cases
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
            WHERE DATE(c.received_at) BETWEEN :s AND :e
            $whereMailbox
            GROUP BY DATE(c.received_at)
            ORDER BY day ASC
        ";
        
        $st = $this->pdo->prepare($sql);
        $st->bindValue(':s', $startDate);
        $st->bindValue(':e', $endDate);
        if ($mailboxId) $st->bindValue(':mb', $mailboxId, PDO::PARAM_INT);
        $st->execute();
        
        return $st->fetchAll();
    }
}
