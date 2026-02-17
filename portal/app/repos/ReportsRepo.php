<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class ReportsRepo
{
    public function __construct(private PDO $pdo) {}

    public function dashboard(string $startDate, string $endDate, ?int $mailboxId = null): array
    {
        $whereMailbox = $mailboxId ? " AND c.mailbox_id = :mb " : "";

        $sql = "
            SELECT
              COUNT(*) AS total_cases,
              SUM(CASE WHEN cs.is_final = 0 THEN 1 ELSE 0 END) AS open_cases,
              SUM(CASE WHEN cs.is_final = 1 THEN 1 ELSE 0 END) AS closed_cases,
              SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) AS responded_cases,
              SUM(CASE WHEN COALESCE(cst.breached,0) = 1 THEN 1 ELSE 0 END) AS breached_cases,
              SUM(CASE WHEN cst.current_sla_state='VERDE' THEN 1 ELSE 0 END) AS sla_verde,
              SUM(CASE WHEN cst.current_sla_state='AMARILLO' THEN 1 ELSE 0 END) AS sla_amarillo,
              SUM(CASE WHEN cst.current_sla_state='ROJO' THEN 1 ELSE 0 END) AS sla_rojo,

              ROUND(AVG(
                CASE WHEN c.assigned_at IS NOT NULL
                  THEN TIMESTAMPDIFF(MINUTE, c.received_at, c.assigned_at) / 60
                  ELSE NULL
                END
              ), 2) AS avg_assign_hours,

              ROUND(AVG(
                CASE WHEN c.first_response_at IS NOT NULL
                  THEN TIMESTAMPDIFF(MINUTE, c.received_at, c.first_response_at) / 60
                  ELSE NULL
                END
              ), 2) AS avg_first_response_hours,

              ROUND(AVG(
                CASE WHEN c.closed_at IS NOT NULL
                  THEN TIMESTAMPDIFF(MINUTE, c.received_at, c.closed_at) / 60
                  ELSE NULL
                END
              ), 2) AS avg_close_hours,

              ROUND(AVG(NULLIF(cst.business_minutes,0)) / 60, 2) AS avg_business_hours

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
        $daily = $st->fetchAll() ?: [];

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
        $st->execute([':s' => $startDate, ':e' => $endDate]);
        $missing = (int)($st->fetchColumn() ?: 0);

        return [
            'kpis' => $kpis,
            'daily' => $daily,
            'missing_attachments' => $missing,
        ];
    }

    public function agentsMetrics(string $startDate, string $endDate): array
    {
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
        $st->execute([':s' => $startDate, ':e' => $endDate]);
        return $st->fetchAll() ?: [];
    }


    public function exportSlaDataset(string $startDate, string $endDate, ?int $mailboxId = null): array
    {
        $whereMailbox = $mailboxId ? " AND c.mailbox_id = :mb " : "";

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
              c.in_process_at,
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
              cst.last_updated,

              cst.sla_ignored,
              cst.policy_id,
              cst.warn_yellow_at,
              cst.warn_red_at,
              cst.business_minutes,
              cst.sla_started_at,

              ROUND(TIMESTAMPDIFF(MINUTE, c.received_at, NOW()) / 60, 2) AS horas_desde_recepcion,

              ROUND(
                CASE WHEN c.assigned_at IS NOT NULL
                  THEN TIMESTAMPDIFF(MINUTE, c.received_at, c.assigned_at) / 60
                  ELSE NULL
                END
              , 2) AS horas_hasta_asignacion,

              ROUND(
                CASE WHEN c.first_response_at IS NOT NULL
                  THEN TIMESTAMPDIFF(MINUTE, c.received_at, c.first_response_at) / 60
                  ELSE NULL
                END
              , 2) AS horas_hasta_1ra_respuesta,

              ROUND(
                CASE WHEN c.closed_at IS NOT NULL
                  THEN TIMESTAMPDIFF(MINUTE, c.received_at, c.closed_at) / 60
                  ELSE NULL
                END
              , 2) AS horas_hasta_cierre,

              ROUND(
                CASE WHEN cst.sla_due_at IS NOT NULL
                  THEN TIMESTAMPDIFF(MINUTE, NOW(), cst.sla_due_at) / 60
                  ELSE NULL
                END
              , 2) AS horas_restantes_sla,

              ROUND(
                CASE WHEN cst.sla_due_at IS NOT NULL
                  THEN TIMESTAMPDIFF(MINUTE, c.received_at, cst.sla_due_at) / 60
                  ELSE NULL
                END
              , 2) AS horas_sla_total,

              /* ==============================
                 ✅ NUEVO: TIEMPOS POR ESTADO
                 ============================== */
              COALESCE(t.nuevo_min, 0) AS tiempo_nuevo_min,
              COALESCE(t.asignado_min, 0) AS tiempo_asignado_min,
              COALESCE(t.en_proceso_min, 0) AS tiempo_en_proceso_min,
              COALESCE(t.respondido_min, 0) AS tiempo_respondido_min,
              COALESCE(t.cerrado_min, 0) AS tiempo_cerrado_min,
              COALESCE(t.escalado_min, 0) AS tiempo_escalado_min,
              COALESCE(t.esperando_info_min, 0) AS tiempo_esperando_info_min,

              ROUND(COALESCE(t.nuevo_min,0)/60, 2) AS tiempo_nuevo_h,
              ROUND(COALESCE(t.asignado_min,0)/60, 2) AS tiempo_asignado_h,
              ROUND(COALESCE(t.en_proceso_min,0)/60, 2) AS tiempo_en_proceso_h,
              ROUND(COALESCE(t.respondido_min,0)/60, 2) AS tiempo_respondido_h,
              ROUND(COALESCE(t.cerrado_min,0)/60, 2) AS tiempo_cerrado_h,
              ROUND(COALESCE(t.escalado_min,0)/60, 2) AS tiempo_escalado_h,
              ROUND(COALESCE(t.esperando_info_min,0)/60, 2) AS tiempo_esperando_info_h,

              COALESCE(t.ultimo_evento_at, NULL) AS ultimo_cambio_estado_at,
              COALESCE(t.min_estado_actual, NULL) AS minutos_en_estado_actual,
              ROUND(COALESCE(t.min_estado_actual,0)/60, 2) AS horas_en_estado_actual,

              -- ✅ Alias “bonitos” solicitados (no rompe)
              cs.name AS estado_actual

            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN users u ON u.id = c.assigned_user_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id

            /* Subquery de tiempos por estado basada en case_events */
            LEFT JOIN (
              WITH
              base AS (
                SELECT
                  c2.id AS case_id,
                  c2.status_id AS initial_status_id,
                  c2.received_at AS initial_at,
                  (
                    SELECT MIN(e0.created_at)
                    FROM case_events e0
                    WHERE e0.case_id = c2.id
                      AND e0.to_status_id IS NOT NULL
                  ) AS first_event_at
                FROM cases c2
                WHERE DATE(c2.received_at) BETWEEN :s_base AND :e_base

              ),
              ev AS (
                SELECT
                  e.case_id,
                  e.to_status_id AS status_id,
                  e.created_at AS at_time,
                  LEAD(e.created_at) OVER (PARTITION BY e.case_id ORDER BY e.created_at) AS next_time
                FROM case_events e
                WHERE e.to_status_id IS NOT NULL
              ),
              timeline AS (
                SELECT
                  b.case_id,
                  b.initial_status_id AS status_id,
                  b.initial_at AS at_time,
                  b.first_event_at AS next_time
                FROM base b
                UNION ALL
                SELECT
                  ev.case_id,
                  ev.status_id,
                  ev.at_time,
                  ev.next_time
                FROM ev
              ),
              durations AS (
                SELECT
                  case_id,
                  status_id,
                  GREATEST(0, TIMESTAMPDIFF(MINUTE, at_time, COALESCE(next_time, NOW(6)))) AS minutes_in_status
                FROM timeline
                WHERE at_time IS NOT NULL
              ),
              last_ev AS (
                SELECT
                  e.case_id,
                  MAX(e.created_at) AS last_at
                FROM case_events e
                WHERE e.to_status_id IS NOT NULL
                GROUP BY e.case_id
              )
              SELECT
                d.case_id,

                SUM(CASE WHEN csx.code='NUEVO' THEN d.minutes_in_status ELSE 0 END) AS nuevo_min,
                SUM(CASE WHEN csx.code='ASIGNADO' THEN d.minutes_in_status ELSE 0 END) AS asignado_min,
                SUM(CASE WHEN csx.code='EN_PROCESO' THEN d.minutes_in_status ELSE 0 END) AS en_proceso_min,
                SUM(CASE WHEN csx.code='RESPONDIDO' THEN d.minutes_in_status ELSE 0 END) AS respondido_min,
                SUM(CASE WHEN csx.code='CERRADO' THEN d.minutes_in_status ELSE 0 END) AS cerrado_min,
                SUM(CASE WHEN csx.code IN('ESCALADO','ESCALATED') THEN d.minutes_in_status ELSE 0 END) AS escalado_min,
                SUM(CASE WHEN csx.code='ESPERANDO_INFO' THEN d.minutes_in_status ELSE 0 END) AS esperando_info_min,

                le.last_at AS ultimo_evento_at,
                CASE
                  WHEN le.last_at IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, le.last_at, NOW(6)))
                  ELSE NULL
                END AS min_estado_actual

              FROM durations d
              JOIN case_statuses csx ON csx.id = d.status_id
              LEFT JOIN last_ev le ON le.case_id = d.case_id
              GROUP BY d.case_id, le.last_at
            ) t ON t.case_id = c.id

            WHERE DATE(c.received_at) BETWEEN :s AND :e
            $whereMailbox
            ORDER BY c.received_at DESC
        ";

        $st = $this->pdo->prepare($sql);
        // outer WHERE
        $st->bindValue(':s', $startDate);
        $st->bindValue(':e', $endDate);

        // CTE base
        $st->bindValue(':s_base', $startDate);
        $st->bindValue(':e_base', $endDate);

        if ($mailboxId) {
            $st->bindValue(':mb', $mailboxId, PDO::PARAM_INT);
        }

        $st->execute();
        return $st->fetchAll() ?: [];
    }

    public function exportHeaderMap(): array
    {
        return [
            'case_id' => 'ID Caso',
            'mailbox_id' => 'ID Buzón',
            'case_number' => 'Número de Caso',
            'subject' => 'Asunto',
            'requester_email' => 'Correo del Solicitante',
            'requester_name' => 'Nombre del Solicitante',

            'status_code' => 'Código Estado',
            'status_name' => 'Estado Actual',

            'assigned_user_id' => 'ID Agente Asignado',
            'assigned_user' => 'Agente Asignado',

            'received_at' => 'Fecha Recepción',
            'assigned_at' => 'Fecha Asignación',
            'in_process_at' => 'Fecha Inicio Gestión',
            'first_response_at' => 'Fecha Primera Respuesta',
            'closed_at' => 'Fecha Cierre',

            'is_responded' => 'Respondido (1/0)',
            'due_at' => 'Vencimiento (cases.due_at)',
            'sla_state' => 'Estado SLA (cases)',

            'current_sla_state' => 'Semáforo SLA',
            'breached' => 'Incumplió SLA (1/0)',
            'sla_due_at' => 'Vence SLA (tracking)',

            'minutes_since_creation' => 'Minutos desde Creación (tracking)',
            'days_since_creation' => 'Días desde Creación (tracking)',
            'last_updated' => 'Última Actualización SLA (tracking)',

            'sla_ignored' => 'SLA Ignorado (1/0)',
            'policy_id' => 'ID Política SLA',
            'warn_yellow_at' => 'Alerta Amarillo (fecha)',
            'warn_red_at' => 'Alerta Rojo (fecha)',
            'business_minutes' => 'Minutos Hábiles (tracking)',
            'sla_started_at' => 'Inicio SLA (tracking)',

            'horas_desde_recepcion' => 'Horas desde Recepción',
            'horas_hasta_asignacion' => 'Horas hasta Asignación',
            'horas_hasta_1ra_respuesta' => 'Horas hasta 1ra Respuesta',
            'horas_hasta_cierre' => 'Horas hasta Cierre',
            'horas_restantes_sla' => 'Horas Restantes SLA',
            'horas_sla_total' => 'Horas Totales SLA',

            // Tiempos por estado
            'tiempo_nuevo_min' => 'Tiempo en NUEVO (min)',
            'tiempo_asignado_min' => 'Tiempo en ASIGNADO (min)',
            'tiempo_en_proceso_min' => 'Tiempo en EN PROCESO (min)',
            'tiempo_respondido_min' => 'Tiempo en RESPONDIDO (min)',
            'tiempo_cerrado_min' => 'Tiempo en CERRADO (min)',
            'tiempo_escalado_min' => 'Tiempo en ESCALADO (min)',
            'tiempo_esperando_info_min' => 'Tiempo en ESPERANDO INFO (min)',

            'tiempo_nuevo_h' => 'Tiempo en NUEVO (h)',
            'tiempo_asignado_h' => 'Tiempo en ASIGNADO (h)',
            'tiempo_en_proceso_h' => 'Tiempo en EN PROCESO (h)',
            'tiempo_respondido_h' => 'Tiempo en RESPONDIDO (h)',
            'tiempo_cerrado_h' => 'Tiempo en CERRADO (h)',
            'tiempo_escalado_h' => 'Tiempo en ESCALADO (h)',
            'tiempo_esperando_info_h' => 'Tiempo en ESPERANDO INFO (h)',

            'ultimo_cambio_estado_at' => 'Último cambio de estado',
            'minutos_en_estado_actual' => 'Minutos en Estado Actual',
            'horas_en_estado_actual' => 'Horas en Estado Actual',
            'estado_actual' => 'Estado Actual (derivado)',
        ];
    }


    public function exportColumnOrder(): array
    {
        return [
            // Identificación del caso
            'case_id',
            'case_number',
            'mailbox_id',

            // Solicitante
            'requester_email',
            'requester_name',

            // Contenido
            'subject',

            // Estado actual (cases)
            'status_code',
            'status_name',

            // Asignación / agente
            'assigned_user_id',
            'assigned_user',

            // Fechas principales
            'received_at',
            'assigned_at',
            'in_process_at',
            'first_response_at',
            'closed_at',

            // Flags / SLA base (cases)
            'is_responded',
            'due_at',
            'sla_state',

            // SLA tracking
            'current_sla_state',
            'breached',
            'sla_started_at',
            'sla_due_at',
            'warn_yellow_at',
            'warn_red_at',
            'sla_ignored',
            'policy_id',
            'business_minutes',
            'minutes_since_creation',
            'days_since_creation',
            'last_updated',

            // Métricas calculadas (horas)
            'horas_desde_recepcion',
            'horas_hasta_asignacion',
            'horas_hasta_1ra_respuesta',
            'horas_hasta_cierre',
            'horas_restantes_sla',
            'horas_sla_total',

            // Tiempos por estado
            'tiempo_nuevo_min',
            'tiempo_asignado_min',
            'tiempo_en_proceso_min',
            'tiempo_respondido_min',
            'tiempo_cerrado_min',
            'tiempo_escalado_min',
            'tiempo_esperando_info_min',

            'tiempo_nuevo_h',
            'tiempo_asignado_h',
            'tiempo_en_proceso_h',
            'tiempo_respondido_h',
            'tiempo_cerrado_h',
            'tiempo_escalado_h',
            'tiempo_esperando_info_h',

            // Estado actual + duración
            'estado_actual',
            'ultimo_cambio_estado_at',
            'minutos_en_estado_actual',
            'horas_en_estado_actual',
        ];
    }


    public function insertGeneratedReport(
        int $userId,
        string $reportType,
        string $filePath,
        array $params,
        string $periodStart,
        string $periodEnd,
        string $status = 'READY',
        ?string $errorMessage = null,
        ?int $rowCount = null,
        ?string $finishedAt = null
    ): void {
        $status = strtoupper(trim($status));
        $paramsJson = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $paramsJson ?: '');

        // finished_at se resuelve en PHP (evita reuso de placeholders en SQL)
        $finishedAtFinal = $finishedAt;
        if ($finishedAtFinal === null && ($status === 'READY' || $status === 'FAILED')) {
            $finishedAtFinal = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');
        }

        $sql = "
          INSERT INTO generated_reports
            (report_type, report_date, file_path, download_count, generated_by, created_at, params, params_hash,
             period_start, period_end, status, error_message, row_count, finished_at)
          VALUES
            (:rt, CURDATE(), :fp, 0, :uid, NOW(6), :pj, :ph, :ps, :pe, :st, :em, :rc, :fa)
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':rt' => $reportType,
            ':fp' => $filePath,
            ':uid' => $userId ?: null,
            ':pj' => $paramsJson,
            ':ph' => $hash,
            ':ps' => $periodStart,
            ':pe' => $periodEnd,
            ':st' => $status,
            ':em' => $errorMessage,
            ':rc' => $rowCount,
            ':fa' => $finishedAtFinal,
        ]);
    }

    public function getReportById(int $id): ?array
    {
        $sql = "
            SELECT
                gr.*,
                gr.generated_by AS created_by
            FROM generated_reports gr
            WHERE gr.id = :id
            LIMIT 1
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $id]);
        $r = $st->fetch();
        return $r ?: null;
    }

    public function incrementDownloadCount(int $id): void
    {
        $sql = "
            UPDATE generated_reports
            SET download_count = download_count + 1
            WHERE id = :id
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $id]);
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

        return $st->fetchAll() ?: [];
    }

    public function recentExports(int $page = 1, int $pageSize = 20): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min($pageSize, 100));
        $offset = ($page - 1) * $pageSize;

        $sql = "
            SELECT
                gr.id,
                gr.report_type,
                gr.report_date,
                gr.file_path,
                gr.download_count,

                gr.generated_by,
                u.full_name AS generated_by_name,

                gr.created_at,
                gr.status,
                gr.error_message,
                gr.row_count,
                gr.finished_at,

                gr.generated_by AS created_by,
                u.full_name AS created_by_name,
                NULL AS updated_at

            FROM generated_reports gr
            LEFT JOIN users u ON u.id = gr.generated_by
            ORDER BY gr.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $st = $this->pdo->prepare($sql);
        $st->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll() ?: [];
    }
}
