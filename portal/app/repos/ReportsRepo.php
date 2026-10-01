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
                  ELSE NULL END
              ), 2) AS avg_assign_hours,
              ROUND(AVG(
                CASE WHEN c.first_response_at IS NOT NULL
                  THEN TIMESTAMPDIFF(MINUTE, c.received_at, c.first_response_at) / 60
                  ELSE NULL END
              ), 2) AS avg_first_response_hours,
              ROUND(AVG(
                CASE WHEN c.closed_at IS NOT NULL
                  THEN TIMESTAMPDIFF(MINUTE, c.received_at, c.closed_at) / 60
                  ELSE NULL END
              ), 2) AS avg_close_hours,
              ROUND(AVG(NULLIF(cst.business_minutes,0)) / 60, 2) AS avg_business_hours
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
            WHERE c.received_date BETWEEN :s AND :e
            $whereMailbox
        ";

        $st = $this->pdo->prepare($sql);
        $st->bindValue(':s', $startDate);
        $st->bindValue(':e', $endDate);
        if ($mailboxId) $st->bindValue(':mb', $mailboxId, PDO::PARAM_INT);
        $st->execute();
        $kpis = $st->fetch() ?: [];

        $sqlDaily = "
            SELECT c.received_date AS day, COUNT(*) AS cnt
            FROM cases c
            WHERE c.received_date BETWEEN :s AND :e
            $whereMailbox
            GROUP BY c.received_date
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
            'kpis'                => $kpis,
            'daily'               => $daily,
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

    /**
     * FIX v2:
     * 1. Subquery correlacionado eliminado — reemplazado por CTE first_ev con GROUP BY.
     * 2. DATE(received_at) reemplazado por received_date (columna generada + índice).
     * Ambas mejoras en conjunto eliminan el error 500 en rangos grandes.
     */
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
              c.closed_ticket AS radicado_cierre,
              c.closed_note AS observacion_cierre,
              (
                SELECT CASE
                    WHEN JSON_EXTRACT(ce.details_json, '$.priority_override') = true THEN 'Sí'
                    ELSE 'No'
                END
                FROM case_events ce
                WHERE ce.case_id = c.id
                  AND ce.event_type = 'ASSIGNED'
                ORDER BY ce.created_at DESC
                LIMIT 1
              ) AS asignacion_prioritaria,
              c.escalated_at,
              c.escalated_by_user_id,
              ue.full_name AS escalated_by_user,
              c.escalated_note,
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
              ROUND(CASE WHEN c.assigned_at IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, c.received_at, c.assigned_at) / 60
                ELSE NULL END, 2) AS horas_hasta_asignacion,
              ROUND(CASE WHEN c.first_response_at IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, c.received_at, c.first_response_at) / 60
                ELSE NULL END, 2) AS horas_hasta_1ra_respuesta,
              ROUND(CASE WHEN c.closed_at IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, c.received_at, c.closed_at) / 60
                ELSE NULL END, 2) AS horas_hasta_cierre,
              ROUND(CASE WHEN cst.sla_due_at IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, NOW(), cst.sla_due_at) / 60
                ELSE NULL END, 2) AS horas_restantes_sla,
              ROUND(CASE WHEN cst.sla_due_at IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, c.received_at, cst.sla_due_at) / 60
                ELSE NULL END, 2) AS horas_sla_total,

              COALESCE(t.nuevo_min, 0)          AS tiempo_nuevo_min,
              COALESCE(t.asignado_min, 0)        AS tiempo_asignado_min,
              COALESCE(t.en_proceso_min, 0)      AS tiempo_en_proceso_min,
              COALESCE(t.respondido_min, 0)      AS tiempo_respondido_min,
              COALESCE(t.cerrado_min, 0)         AS tiempo_cerrado_min,
              COALESCE(t.escalado_min, 0)        AS tiempo_escalado_min,
              COALESCE(t.esperando_info_min, 0)  AS tiempo_esperando_info_min,

              ROUND(COALESCE(t.nuevo_min,0)/60, 2)          AS tiempo_nuevo_h,
              ROUND(COALESCE(t.asignado_min,0)/60, 2)       AS tiempo_asignado_h,
              ROUND(COALESCE(t.en_proceso_min,0)/60, 2)     AS tiempo_en_proceso_h,
              ROUND(COALESCE(t.respondido_min,0)/60, 2)     AS tiempo_respondido_h,
              ROUND(COALESCE(t.cerrado_min,0)/60, 2)        AS tiempo_cerrado_h,
              ROUND(COALESCE(t.escalado_min,0)/60, 2)       AS tiempo_escalado_h,
              ROUND(COALESCE(t.esperando_info_min,0)/60, 2) AS tiempo_esperando_info_h,

              COALESCE(t.ultimo_evento_at, NULL)  AS ultimo_cambio_estado_at,
              COALESCE(t.min_estado_actual, NULL) AS minutos_en_estado_actual,
              ROUND(COALESCE(t.min_estado_actual,0)/60, 2)  AS horas_en_estado_actual,
              cs.name AS estado_actual

            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN users u ON u.id = c.assigned_user_id
            LEFT JOIN users ue ON ue.id = c.escalated_by_user_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id

            LEFT JOIN (
              WITH
              first_ev AS (
                SELECT e0.case_id, MIN(e0.created_at) AS first_event_at
                FROM case_events e0
                WHERE e0.to_status_id IS NOT NULL
                  AND e0.case_id IN (
                    SELECT id FROM cases c3 WHERE c3.received_date BETWEEN :s_base AND :e_base
                  )
                GROUP BY e0.case_id
              ),
              base AS (
                SELECT c2.id AS case_id, c2.status_id AS initial_status_id,
                       c2.received_at AS initial_at, fe.first_event_at
                FROM cases c2
                LEFT JOIN first_ev fe ON fe.case_id = c2.id
                WHERE c2.received_date BETWEEN :s_base2 AND :e_base2
              ),
              ev AS (
                SELECT e.case_id,
                       e.to_status_id AS status_id,
                       e.created_at AS at_time,
                       LEAD(e.created_at) OVER (PARTITION BY e.case_id ORDER BY e.created_at) AS next_time
                FROM case_events e
                WHERE e.to_status_id IS NOT NULL
                  AND e.case_id IN (
                    SELECT id FROM cases c4 WHERE c4.received_date BETWEEN :s_base3 AND :e_base3
                  )
              ),
              timeline AS (
                SELECT b.case_id, b.initial_status_id AS status_id,
                       b.initial_at AS at_time, b.first_event_at AS next_time
                FROM base b
                UNION ALL
                SELECT ev.case_id, ev.status_id, ev.at_time, ev.next_time
                FROM ev
                INNER JOIN base b2 ON b2.case_id = ev.case_id
              ),
              durations AS (
                SELECT case_id, status_id,
                       GREATEST(0, TIMESTAMPDIFF(MINUTE, at_time, COALESCE(next_time, NOW(6)))) AS minutes_in_status
                FROM timeline
                WHERE at_time IS NOT NULL
              ),
              last_ev AS (
                SELECT e.case_id, MAX(e.created_at) AS last_at
                FROM case_events e
                WHERE e.to_status_id IS NOT NULL
                  AND e.case_id IN (
                    SELECT id FROM cases c5 WHERE c5.received_date BETWEEN :s_base4 AND :e_base4
                  )
                GROUP BY e.case_id
              )
              SELECT
                d.case_id,
                SUM(CASE WHEN csx.code = 'NUEVO'                  THEN d.minutes_in_status ELSE 0 END) AS nuevo_min,
                SUM(CASE WHEN csx.code = 'ASIGNADO'               THEN d.minutes_in_status ELSE 0 END) AS asignado_min,
                SUM(CASE WHEN csx.code = 'EN_PROCESO'             THEN d.minutes_in_status ELSE 0 END) AS en_proceso_min,
                SUM(CASE WHEN csx.code = 'RESPONDIDO'             THEN d.minutes_in_status ELSE 0 END) AS respondido_min,
                SUM(CASE WHEN csx.code = 'CERRADO'                THEN d.minutes_in_status ELSE 0 END) AS cerrado_min,
                SUM(CASE WHEN csx.code IN('ESCALADO','ESCALATED') THEN d.minutes_in_status ELSE 0 END) AS escalado_min,
                SUM(CASE WHEN csx.code = 'ESPERANDO_INFO'         THEN d.minutes_in_status ELSE 0 END) AS esperando_info_min,
                le.last_at AS ultimo_evento_at,
                CASE WHEN le.last_at IS NOT NULL
                  THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, le.last_at, NOW(6)))
                  ELSE NULL END AS min_estado_actual
              FROM durations d
              JOIN case_statuses csx ON csx.id = d.status_id
              LEFT JOIN last_ev le ON le.case_id = d.case_id
              GROUP BY d.case_id, le.last_at
            ) t ON t.case_id = c.id

            WHERE c.received_date BETWEEN :s AND :e
            $whereMailbox
            ORDER BY c.received_at DESC
        ";

        $st = $this->pdo->prepare($sql);
        $st->bindValue(':s',       $startDate);
        $st->bindValue(':e',       $endDate);
        $st->bindValue(':s_base',  $startDate);
        $st->bindValue(':e_base',  $endDate);
        $st->bindValue(':s_base2', $startDate);
        $st->bindValue(':e_base2', $endDate);
        $st->bindValue(':s_base3', $startDate);
        $st->bindValue(':e_base3', $endDate);
        $st->bindValue(':s_base4', $startDate);
        $st->bindValue(':e_base4', $endDate);
        if ($mailboxId) {
            $st->bindValue(':mb', $mailboxId, PDO::PARAM_INT);
        }
        $st->execute();
        return $st->fetchAll() ?: [];
    }

    public function exportHeaderMap(): array
    {
        return [
            'case_id'                   => 'ID Caso',
            'mailbox_id'                => 'ID Buzón',
            'case_number'               => 'Número de Caso',
            'subject'                   => 'Asunto',
            'requester_email'           => 'Correo del Solicitante',
            'requester_name'            => 'Nombre del Solicitante',
            'status_code'               => 'Código Estado',
            'status_name'               => 'Estado Actual',
            'assigned_user_id'          => 'ID Agente Asignado',
            'assigned_user'             => 'Agente Asignado',
            'received_at'               => 'Fecha Recepción',
            'assigned_at'               => 'Fecha Asignación',
            'in_process_at'             => 'Fecha Inicio Gestión',
            'first_response_at'         => 'Fecha Primera Respuesta',
            'closed_at'                 => 'Fecha Cierre',
            'radicado_cierre'           => 'Radicado de Cierre',
            'observacion_cierre'        => 'Observación de Cierre (Tipificación)',
            'asignacion_prioritaria'    => 'Asignación Prioritaria (supera límite de 2 casos)',
            'escalated_at'              => 'Fecha de Escalamiento',
            'escalated_by_user_id'      => 'ID Usuario que Escaló',
            'escalated_by_user'         => 'Usuario que Escaló',
            'escalated_note'            => 'Observación de Escalamiento',
            'is_responded'              => 'Respondido (1/0)',
            'due_at'                    => 'Vencimiento (cases.due_at)',
            'sla_state'                 => 'Estado SLA (cases)',
            'current_sla_state'         => 'Semáforo SLA',
            'breached'                  => 'Incumplió SLA (1/0)',
            'sla_due_at'                => 'Vence SLA (tracking)',
            'minutes_since_creation'    => 'Minutos desde Creación (tracking)',
            'days_since_creation'       => 'Días desde Creación (tracking)',
            'last_updated'              => 'Última Actualización SLA (tracking)',
            'sla_ignored'               => 'SLA Ignorado (1/0)',
            'policy_id'                 => 'ID Política SLA',
            'warn_yellow_at'            => 'Alerta Amarillo (fecha)',
            'warn_red_at'               => 'Alerta Rojo (fecha)',
            'business_minutes'          => 'Minutos Hábiles (tracking)',
            'sla_started_at'            => 'Inicio SLA (tracking)',
            'horas_desde_recepcion'     => 'Horas desde Recepción',
            'horas_hasta_asignacion'    => 'Horas hasta Asignación',
            'horas_hasta_1ra_respuesta' => 'Horas hasta 1ra Respuesta',
            'horas_hasta_cierre'        => 'Horas hasta Cierre',
            'horas_restantes_sla'       => 'Horas Restantes SLA',
            'horas_sla_total'           => 'Horas Totales SLA',
            'tiempo_nuevo_min'          => 'Tiempo en NUEVO (min)',
            'tiempo_asignado_min'       => 'Tiempo en ASIGNADO (min)',
            'tiempo_en_proceso_min'     => 'Tiempo en EN PROCESO (min)',
            'tiempo_respondido_min'     => 'Tiempo en RESPONDIDO (min)',
            'tiempo_cerrado_min'        => 'Tiempo en CERRADO (min)',
            'tiempo_escalado_min'       => 'Tiempo en ESCALADO (min)',
            'tiempo_esperando_info_min' => 'Tiempo en ESPERANDO INFO (min)',
            'tiempo_nuevo_h'            => 'Tiempo en NUEVO (h)',
            'tiempo_asignado_h'         => 'Tiempo en ASIGNADO (h)',
            'tiempo_en_proceso_h'       => 'Tiempo en EN PROCESO (h)',
            'tiempo_respondido_h'       => 'Tiempo en RESPONDIDO (h)',
            'tiempo_cerrado_h'          => 'Tiempo en CERRADO (h)',
            'tiempo_escalado_h'         => 'Tiempo en ESCALADO (h)',
            'tiempo_esperando_info_h'   => 'Tiempo en ESPERANDO INFO (h)',
            'ultimo_cambio_estado_at'   => 'Último cambio de estado',
            'minutos_en_estado_actual'  => 'Minutos en Estado Actual',
            'horas_en_estado_actual'    => 'Horas en Estado Actual',
            'estado_actual'             => 'Estado Actual (derivado)',
        ];
    }

    public function exportColumnOrder(): array
    {
        return [
            'case_id', 'case_number', 'mailbox_id',
            'requester_email', 'requester_name', 'subject',
            'status_code', 'status_name',
            'assigned_user_id', 'assigned_user',
            'received_at', 'assigned_at', 'in_process_at',
            'first_response_at', 'closed_at',
            'radicado_cierre', 'observacion_cierre', 'asignacion_prioritaria',
            'escalated_at', 'escalated_by_user_id',
            'escalated_by_user', 'escalated_note',
            'is_responded', 'due_at', 'sla_state',
            'current_sla_state', 'breached',
            'sla_started_at', 'sla_due_at',
            'warn_yellow_at', 'warn_red_at',
            'sla_ignored', 'policy_id', 'business_minutes',
            'minutes_since_creation', 'days_since_creation', 'last_updated',
            'horas_desde_recepcion', 'horas_hasta_asignacion',
            'horas_hasta_1ra_respuesta', 'horas_hasta_cierre',
            'horas_restantes_sla', 'horas_sla_total',
            'tiempo_nuevo_min', 'tiempo_asignado_min', 'tiempo_en_proceso_min',
            'tiempo_respondido_min', 'tiempo_cerrado_min',
            'tiempo_escalado_min', 'tiempo_esperando_info_min',
            'tiempo_nuevo_h', 'tiempo_asignado_h', 'tiempo_en_proceso_h',
            'tiempo_respondido_h', 'tiempo_cerrado_h',
            'tiempo_escalado_h', 'tiempo_esperando_info_h',
            'estado_actual', 'ultimo_cambio_estado_at',
            'minutos_en_estado_actual', 'horas_en_estado_actual',
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
            ':rt'  => $reportType,
            ':fp'  => $filePath,
            ':uid' => $userId ?: null,
            ':pj'  => $paramsJson,
            ':ph'  => $hash,
            ':ps'  => $periodStart,
            ':pe'  => $periodEnd,
            ':st'  => $status,
            ':em'  => $errorMessage,
            ':rc'  => $rowCount,
            ':fa'  => $finishedAtFinal,
        ]);
    }

    public function getReportById(int $id): ?array
    {
        $st = $this->pdo->prepare("
            SELECT gr.*, gr.generated_by AS created_by
            FROM generated_reports gr WHERE gr.id = :id LIMIT 1
        ");
        $st->execute([':id' => $id]);
        $r = $st->fetch();
        return $r ?: null;
    }

    public function incrementDownloadCount(int $id): void
    {
        $st = $this->pdo->prepare("
            UPDATE generated_reports SET download_count = download_count + 1 WHERE id = :id
        ");
        $st->execute([':id' => $id]);
    }

    public function detailedDailyMetrics(string $startDate, string $endDate, ?int $mailboxId = null): array
    {
        $whereMailbox = $mailboxId ? " AND c.mailbox_id = :mb " : "";
        $sql = "
            SELECT
                c.received_date AS day,
                COUNT(*) AS total_cases,
                SUM(CASE WHEN cs.is_final = 0 THEN 1 ELSE 0 END) AS open_cases,
                SUM(CASE WHEN cs.is_final = 1 THEN 1 ELSE 0 END) AS closed_cases,
                SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) AS responded_cases,
                SUM(CASE WHEN COALESCE(cst.breached,0) = 1 THEN 1 ELSE 0 END) AS breached_cases
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
            WHERE c.received_date BETWEEN :s AND :e
            $whereMailbox
            GROUP BY c.received_date
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
        $page     = max(1, $page);
        $pageSize = max(1, min($pageSize, 100));
        $offset   = ($page - 1) * $pageSize;
        $sql = "
            SELECT
                gr.id, gr.report_type, gr.report_date, gr.file_path, gr.download_count,
                gr.generated_by, u.full_name AS generated_by_name,
                gr.created_at, gr.status, gr.error_message, gr.row_count, gr.finished_at,
                gr.generated_by AS created_by, u.full_name AS created_by_name, NULL AS updated_at
            FROM generated_reports gr
            LEFT JOIN users u ON u.id = gr.generated_by
            ORDER BY gr.created_at DESC
            LIMIT :limit OFFSET :offset
        ";
        $st = $this->pdo->prepare($sql);
        $st->bindValue(':limit',  $pageSize, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset,   PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll() ?: [];
    }

    // =====================================================================
    // Reportería de agentes: histórico de estados, resumen agregado y
    // snapshot en tiempo real. Misma tabla base (agent_presence_history /
    // agent_presence) que ya usa el assignment_worker para decidir a quién
    // asignar casos - este reporte no depende de ninguna tabla nueva.
    // =====================================================================

    /**
     * Detalle forense: una fila por cada transición de estado de cada
     * agente dentro del rango. ended_at puede venir NULL si el tramo
     * sigue abierto (el agente sigue en ese estado en este momento).
     */
    public function exportAgentPresenceHistoryDataset(
        string $startDate,
        string $endDate,
        ?int $userId = null
    ): array {
        $whereUser = $userId ? " AND h.user_id = :uid " : "";

        // 'tipo_evento' distingue explícitamente si la fila representa una
        // acción real del agente (PORTAL/LOGOUT) o un evento automático del
        // sistema de heartbeat (HEARTBEAT_INIT/HEARTBEAT_RECONNECT) - ver
        // AgentPresenceRepo::heartbeat(). Un HEARTBEAT_RECONNECT ocurre
        // cuando el navegador deja de enviar heartbeat por más de
        // AGENT_PRESENCE_STALE_SECONDS (típicamente porque el navegador
        // limita los timers de una pestaña en segundo plano, o hay un
        // microcorte de red) y luego reconecta - el agente NO tocó nada,
        // el sistema simplemente confirma que sigue conectado antes de
        // seguir considerándolo disponible. Sin esta distinción, el
        // reporte hacía ver como "cambios de estado" del agente lo que en
        // realidad eran reconexiones técnicas.
        $sql = "
            SELECT
              h.id AS history_id,
              h.user_id,
              u.full_name AS agente,
              u.email AS agente_email,
              aps.code AS estado_code,
              aps.name AS estado_nombre,
              h.started_at,
              h.ended_at,
              (h.ended_at IS NULL) AS estado_abierto,
              TIMESTAMPDIFF(SECOND, h.started_at, COALESCE(h.ended_at, NOW(6))) AS duracion_segundos,
              ROUND(TIMESTAMPDIFF(SECOND, h.started_at, COALESCE(h.ended_at, NOW(6))) / 60, 2) AS duracion_minutos,
              ROUND(TIMESTAMPDIFF(SECOND, h.started_at, COALESCE(h.ended_at, NOW(6))) / 3600, 2) AS duracion_horas,
              h.source AS origen_cambio,
              CASE h.source
                WHEN 'PORTAL' THEN 'Cambio Manual del Agente'
                WHEN 'LOGOUT' THEN 'Cierre de Sesión'
                WHEN 'HEARTBEAT_INIT' THEN 'Primera Conexión Detectada'
                WHEN 'HEARTBEAT_RECONNECT' THEN 'Reconexión Automática (sin acción del agente)'
                ELSE h.source
              END AS tipo_evento,
              (h.source IN ('HEARTBEAT_INIT', 'HEARTBEAT_RECONNECT')) AS es_evento_automatico,
              cu.full_name AS cambiado_por
            FROM agent_presence_history h
            JOIN users u ON u.id = h.user_id
            JOIN agent_presence_statuses aps ON aps.id = h.status_id
            LEFT JOIN users cu ON cu.id = h.changed_by_user_id
            WHERE h.started_at < :end_next_day
              AND (h.ended_at IS NULL OR h.ended_at >= :start)
              {$whereUser}
            ORDER BY h.user_id ASC, h.started_at ASC
        ";

        $params = [
            ':start' => $startDate . ' 00:00:00',
            ':end_next_day' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
        ];
        if ($userId) {
            $params[':uid'] = $userId;
        }

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll() ?: [];
    }

    public function exportAgentPresenceHistoryHeaderMap(): array
    {
        return [
            'history_id'            => 'ID Registro',
            'user_id'               => 'ID Agente',
            'agente'                => 'Agente',
            'agente_email'          => 'Correo Agente',
            'estado_code'           => 'Código Estado',
            'estado_nombre'         => 'Estado',
            'started_at'            => 'Inicio del Estado',
            'ended_at'              => 'Fin del Estado',
            'estado_abierto'        => 'Estado Activo Ahora (1/0)',
            'duracion_segundos'     => 'Duración (segundos)',
            'duracion_minutos'      => 'Duración (minutos)',
            'duracion_horas'        => 'Duración (horas)',
            'origen_cambio'         => 'Origen del Cambio (código técnico)',
            'tipo_evento'           => 'Tipo de Evento',
            'es_evento_automatico'  => 'Es Reconexión Automática, no Acción del Agente (1/0)',
            'cambiado_por'          => 'Cambiado Por (si fue manual/admin)',
        ];
    }

    public function exportAgentPresenceHistoryColumnOrder(): array
    {
        return [
            'user_id', 'agente', 'agente_email',
            'estado_code', 'estado_nombre',
            'started_at', 'ended_at', 'estado_abierto',
            'duracion_segundos', 'duracion_minutos', 'duracion_horas',
            'tipo_evento', 'es_evento_automatico', 'origen_cambio', 'cambiado_por',
        ];
    }

    /**
     * Resumen ejecutivo: totales por agente y por día dentro del rango -
     * horas en cada estado, número de transiciones (proxy de
     * conexiones/desconexiones), primera y última actividad del día.
     */
    public function exportAgentPresenceSummaryDataset(
        string $startDate,
        string $endDate,
        ?int $userId = null
    ): array {
        $whereUser = $userId ? " AND h.user_id = :uid " : "";

        // total_transiciones se mantiene (todas las filas), pero se
        // desglosa explícitamente en manuales vs automáticas para no
        // repetir la confusión reportada: un HEARTBEAT_RECONNECT no es
        // una decisión del agente, es el sistema confirmando reconexión
        // tras un corte de heartbeat (ver AgentPresenceRepo::heartbeat).
        // veces_desconectado no cambia - DESCONECTADO solo se alcanza vía
        // LOGOUT (real), heartbeat nunca mueve a ese estado.
        $sql = "
            SELECT
              h.user_id,
              u.full_name AS agente,
              u.email AS agente_email,
              DATE(h.started_at) AS dia,
              MIN(h.started_at) AS primera_actividad,
              MAX(COALESCE(h.ended_at, NOW(6))) AS ultima_actividad,
              COUNT(*) AS total_transiciones,
              SUM(CASE WHEN h.source IN ('PORTAL', 'LOGOUT') THEN 1 ELSE 0 END) AS cambios_manuales_estado,
              SUM(CASE WHEN h.source IN ('HEARTBEAT_INIT', 'HEARTBEAT_RECONNECT') THEN 1 ELSE 0 END) AS reconexiones_automaticas_heartbeat,
              SUM(CASE WHEN aps.code = 'DISPONIBLE'
                THEN TIMESTAMPDIFF(SECOND, h.started_at, COALESCE(h.ended_at, NOW(6))) ELSE 0 END) AS segundos_disponible,
              SUM(CASE WHEN aps.code NOT IN ('DISPONIBLE', 'DESCONECTADO')
                THEN TIMESTAMPDIFF(SECOND, h.started_at, COALESCE(h.ended_at, NOW(6))) ELSE 0 END) AS segundos_no_disponible_conectado,
              SUM(CASE WHEN aps.code = 'DESCONECTADO'
                THEN TIMESTAMPDIFF(SECOND, h.started_at, COALESCE(h.ended_at, NOW(6))) ELSE 0 END) AS segundos_desconectado,
              ROUND(SUM(CASE WHEN aps.code = 'DISPONIBLE'
                THEN TIMESTAMPDIFF(SECOND, h.started_at, COALESCE(h.ended_at, NOW(6))) ELSE 0 END) / 3600, 2) AS horas_disponible,
              ROUND(SUM(CASE WHEN aps.code NOT IN ('DISPONIBLE', 'DESCONECTADO')
                THEN TIMESTAMPDIFF(SECOND, h.started_at, COALESCE(h.ended_at, NOW(6))) ELSE 0 END) / 3600, 2) AS horas_no_disponible_conectado,
              ROUND(SUM(CASE WHEN aps.code = 'DESCONECTADO'
                THEN TIMESTAMPDIFF(SECOND, h.started_at, COALESCE(h.ended_at, NOW(6))) ELSE 0 END) / 3600, 2) AS horas_desconectado,
              SUM(CASE WHEN aps.code = 'DESCONECTADO' THEN 1 ELSE 0 END) AS veces_desconectado
            FROM agent_presence_history h
            JOIN users u ON u.id = h.user_id
            JOIN agent_presence_statuses aps ON aps.id = h.status_id
            WHERE h.started_at < :end_next_day
              AND (h.ended_at IS NULL OR h.ended_at >= :start)
              {$whereUser}
            GROUP BY h.user_id, DATE(h.started_at)
            ORDER BY dia DESC, agente ASC
        ";

        $params = [
            ':start' => $startDate . ' 00:00:00',
            ':end_next_day' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
        ];
        if ($userId) {
            $params[':uid'] = $userId;
        }

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll() ?: [];
    }

    public function exportAgentPresenceSummaryHeaderMap(): array
    {
        return [
            'user_id'                            => 'ID Agente',
            'agente'                              => 'Agente',
            'agente_email'                        => 'Correo Agente',
            'dia'                                 => 'Día',
            'primera_actividad'                   => 'Primera Actividad',
            'ultima_actividad'                    => 'Última Actividad',
            'total_transiciones'                  => 'Total de Registros (manuales + automáticos)',
            'cambios_manuales_estado'             => 'Cambios de Estado Reales (hechos por el Agente)',
            'reconexiones_automaticas_heartbeat'  => 'Reconexiones Automáticas (sin acción del Agente)',
            'horas_disponible'                    => 'Horas en Disponible',
            'horas_no_disponible_conectado'       => 'Horas Conectado (No Disponible)',
            'horas_desconectado'                  => 'Horas Desconectado',
            'veces_desconectado'                  => 'Veces Desconectado (cierre de sesión real)',
            'segundos_disponible'                 => 'Segundos en Disponible',
            'segundos_no_disponible_conectado'    => 'Segundos Conectado (No Disponible)',
            'segundos_desconectado'               => 'Segundos Desconectado',
        ];
    }

    public function exportAgentPresenceSummaryColumnOrder(): array
    {
        return [
            'dia', 'user_id', 'agente', 'agente_email',
            'primera_actividad', 'ultima_actividad',
            'cambios_manuales_estado', 'reconexiones_automaticas_heartbeat', 'total_transiciones',
            'horas_disponible', 'horas_no_disponible_conectado', 'horas_desconectado',
            'veces_desconectado',
            'segundos_disponible', 'segundos_no_disponible_conectado', 'segundos_desconectado',
        ];
    }

    /**
     * Snapshot en tiempo real: estado actual de cada agente en este
     * instante. No usa rango de fechas - siempre es "ahora". Reutiliza
     * el mismo umbral AGENT_PRESENCE_STALE_SECONDS que usa el
     * assignment_worker para marcar heartbeats caídos, así el reporte
     * es consistente con el criterio real que decide asignaciones.
     */
    public function exportAgentPresenceLiveDataset(int $staleSeconds = 90): array
    {
        $sql = "
            SELECT
              u.id AS user_id,
              u.full_name AS agente,
              u.email AS agente_email,
              aps.code AS estado_code,
              aps.name AS estado_nombre,
              aps.is_assignable AS estado_es_asignable,
              ap.status_since,
              TIMESTAMPDIFF(SECOND, ap.status_since, NOW(6)) AS segundos_en_estado_actual,
              ROUND(TIMESTAMPDIFF(SECOND, ap.status_since, NOW(6)) / 60, 2) AS minutos_en_estado_actual,
              ap.last_seen_at,
              TIMESTAMPDIFF(SECOND, ap.last_seen_at, NOW(6)) AS segundos_desde_ultimo_heartbeat,
              CASE
                WHEN TIMESTAMPDIFF(SECOND, ap.last_seen_at, NOW(6)) > :stale_seconds
                  THEN 'DESACTUALIZADO'
                ELSE 'ACTIVO'
              END AS conexion_real,
              (
                SELECT COUNT(*) FROM cases c
                JOIN case_statuses cs ON cs.id = c.status_id
                WHERE c.assigned_user_id = u.id
                  AND cs.code IN ('ASIGNADO', 'EN_PROCESO')
              ) AS casos_activos_ahora
            FROM users u
            JOIN agent_presence ap ON ap.user_id = u.id
            JOIN agent_presence_statuses aps ON aps.id = ap.status_id
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN roles r ON r.id = ur.role_id
            WHERE UPPER(TRIM(r.code)) IN ('AGENTE', 'AGENT')
            GROUP BY u.id
            ORDER BY aps.sort_order ASC, u.full_name ASC
        ";

        $st = $this->pdo->prepare($sql);
        $st->bindValue(':stale_seconds', $staleSeconds, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll() ?: [];
    }

    public function exportAgentPresenceLiveHeaderMap(): array
    {
        return [
            'user_id'                           => 'ID Agente',
            'agente'                              => 'Agente',
            'agente_email'                        => 'Correo Agente',
            'estado_code'                        => 'Código Estado',
            'estado_nombre'                      => 'Estado Actual',
            'estado_es_asignable'                => 'Estado Permite Asignación (1/0)',
            'status_since'                       => 'Desde Cuándo en Este Estado',
            'segundos_en_estado_actual'          => 'Segundos en Estado Actual',
            'minutos_en_estado_actual'           => 'Minutos en Estado Actual',
            'last_seen_at'                       => 'Último Heartbeat',
            'segundos_desde_ultimo_heartbeat'    => 'Segundos Desde Último Heartbeat',
            'conexion_real'                      => 'Conexión Real (Activo/Desactualizado)',
            'casos_activos_ahora'                => 'Casos Activos Asignados Ahora',
        ];
    }

    public function exportAgentPresenceLiveColumnOrder(): array
    {
        return [
            'user_id', 'agente', 'agente_email',
            'estado_code', 'estado_nombre', 'estado_es_asignable',
            'status_since', 'segundos_en_estado_actual', 'minutos_en_estado_actual',
            'last_seen_at', 'segundos_desde_ultimo_heartbeat', 'conexion_real',
            'casos_activos_ahora',
        ];
    }
}
