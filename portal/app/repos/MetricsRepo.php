<?php
declare(strict_types=1);

namespace App\Repos;

use App\Services\BusinessTime;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class MetricsRepo
{
    private ?HolidayRepo $holidays = null;

    public function __construct(private PDO $pdo) {}

    /**
     * Fuente de tiempo operativa: cases.received_at (no created_at).
     */
    private function clockField(): string
    {
        return 'c.received_at';
    }

    /**
     * ✅ IMPORTANTE (HY093 fix):
     * - NO reutilizamos placeholders nombrados.
     * - Permitimos definir el nombre del placeholder a usar.
     */
    private function baseOpenWhere(?int $assignedUserId, array &$params, string $uidPlaceholder = ':uid_open'): string
    {
        $w = [];
        $w[] = "cs.is_final = 0"; // abierto
        if ($assignedUserId !== null) {
            $w[] = "c.assigned_user_id = {$uidPlaceholder}";
            $params[$uidPlaceholder] = $assignedUserId;
        }
        return $w ? ("WHERE " . implode(" AND ", $w)) : "";
    }

    /* ============================================================
       Calendario hábil (sla_calendar) + Festivos (holiday_calendar)
       - NO rompe si no existe HolidayRepo/tabla: se degrada a L–V
       ============================================================ */

    private function holidayRepo(): ?HolidayRepo
    {
        // Lazy init para no romper en ambientes donde aún no esté el repo/tabla.
        if ($this->holidays !== null) return $this->holidays;

        try {
            // HolidayRepo debe existir en tu proyecto (app/repos/HolidayRepo.php)
            $this->holidays = new HolidayRepo($this->pdo);
            return $this->holidays;
        } catch (\Throwable) {
            $this->holidays = null;
            return null;
        }
    }

    private function loadBusinessClock(): BusinessTime
    {
        $row = $this->pdo->query("SELECT * FROM sla_calendar ORDER BY id ASC LIMIT 1")->fetch() ?: [
            'tz' => 'America/Bogota',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'workdays_mask' => 62, // Mon..Fri
        ];

        $clock = BusinessTime::fromRow($row);

        // ✅ Festivos CO desde BD (si existe holiday_calendar + HolidayRepo)
        $holidays = $this->holidayRepo();
        if ($holidays !== null) {
            try {
                $clock = $clock->withHolidayChecker(function (DateTimeImmutable $dt) use ($holidays): bool {
                    return $holidays->isHoliday('CO', $dt);
                });
            } catch (\Throwable) {
                // Degradar silenciosamente a L–V sin festivos
            }
        }

        return $clock;
    }

    private function nowBogota(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('America/Bogota'));
    }

    private function dtBogota(string $dt): DateTimeImmutable
    {
        return new DateTimeImmutable($dt, new DateTimeZone('America/Bogota'));
    }

    private function semaforoFromBusinessMinutes(int $mins): string
    {
        // <5h / 5-12h / >12h
        if ($mins < 300) return 'VERDE';
        if ($mins <= 720) return 'AMARILLO';
        return 'ROJO';
    }

    /* ============================================================
       DASHBOARD (misma interfaz, mismo output, solo mejor semáforo)
       - Semáforo SOLO si existe tracking (cst.case_id IS NOT NULL)
       ============================================================ */

    public function realtimeSummary(?int $assignedUserId = null): array
    {
        $params = [];

        // ✅ placeholder único para el WHERE principal
        $whereOpen = $this->baseOpenWhere($assignedUserId, $params, ':uid_open');

        // ✅ placeholder único para el subquery
        $respondedFilter = ($assignedUserId !== null) ? " AND c2.assigned_user_id = :uid_resp " : "";
        if ($assignedUserId !== null) {
            $params[':uid_resp'] = $assignedUserId;
        }

        $sql = "
            SELECT
                COUNT(*) AS open_total,

                SUM(CASE WHEN cs.code='NUEVO' THEN 1 ELSE 0 END) AS st_nuevo,
                SUM(CASE WHEN cs.code='ASIGNADO' THEN 1 ELSE 0 END) AS st_asignado,
                SUM(CASE WHEN cs.code='EN_PROCESO' THEN 1 ELSE 0 END) AS st_enproceso,
                SUM(CASE WHEN cs.code='RESPONDIDO' THEN 1 ELSE 0 END) AS st_respondido,

                -- ✅ Semáforo ANS real (solo abiertos NO respondidos + tracking presente)
                SUM(CASE WHEN c.is_responded = 0 AND cst.case_id IS NOT NULL AND cst.current_sla_state = 'VERDE' THEN 1 ELSE 0 END) AS sla_verde,
                SUM(CASE WHEN c.is_responded = 0 AND cst.case_id IS NOT NULL AND cst.current_sla_state = 'AMARILLO' THEN 1 ELSE 0 END) AS sla_amarillo,
                SUM(CASE WHEN c.is_responded = 0 AND cst.case_id IS NOT NULL AND cst.current_sla_state = 'ROJO' THEN 1 ELSE 0 END) AS sla_rojo,

                SUM(CASE WHEN c.is_responded = 0 AND cst.case_id IS NOT NULL AND COALESCE(cst.breached,0) = 1 THEN 1 ELSE 0 END) AS breached_cases,

                -- ✅ control / monitoreo
                SUM(CASE WHEN c.is_responded = 0 AND cst.case_id IS NULL THEN 1 ELSE 0 END) AS missing_tracking_open,

                (SELECT COUNT(*) FROM cases c2
                 WHERE c2.is_responded = 1
                 {$respondedFilter}
                ) AS responded_total,

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
        $row = $st->fetch() ?: [];

        // Compatibilidad extra (si algún lugar usa total_open)
        if (!isset($row['total_open']) && isset($row['open_total'])) {
            $row['total_open'] = $row['open_total'];
        }

        $row['semaforo_hint'] = 'Semáforo calculado por horas hábiles (L–V 08:00–17:00) + festivos.';
        $row['semaforo_legend'] = [
            'VERDE' => '0 a < 5 horas hábiles',
            'AMARILLO' => '5 a 12 horas hábiles',
            'ROJO' => '> 12 horas hábiles',
        ];

        return $row;
    }

    public function realtimeByAgent(): array
    {
        /**
         * ✅ Semáforo SOLO con tracking
         * Nota: Para no “romper” el total_open que ya mostrabas, dejamos COUNT(*) como total de abiertos del agente.
         * El semáforo (verde/amarillo/rojo/breached) se cuenta solo donde exista cst.case_id.
         */
        $sql = "
            SELECT
                u.id AS user_id,
                u.full_name,
                u.email,

                COUNT(*) AS total_open,

                SUM(CASE WHEN cs.code='NUEVO' THEN 1 ELSE 0 END) AS st_nuevo,
                SUM(CASE WHEN cs.code='ASIGNADO' THEN 1 ELSE 0 END) AS st_asignado,
                SUM(CASE WHEN cs.code='EN_PROCESO' THEN 1 ELSE 0 END) AS st_enproceso,

                SUM(CASE WHEN c.is_responded = 0 AND cst.case_id IS NOT NULL AND cst.current_sla_state = 'VERDE' THEN 1 ELSE 0 END) AS verde,
                SUM(CASE WHEN c.is_responded = 0 AND cst.case_id IS NOT NULL AND cst.current_sla_state = 'AMARILLO' THEN 1 ELSE 0 END) AS amarillo,
                SUM(CASE WHEN c.is_responded = 0 AND cst.case_id IS NOT NULL AND cst.current_sla_state = 'ROJO' THEN 1 ELSE 0 END) AS rojo,

                SUM(CASE WHEN c.is_responded = 0 AND cst.case_id IS NOT NULL AND COALESCE(cst.breached,0) = 1 THEN 1 ELSE 0 END) AS breached,

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
            ORDER BY rojo DESC, amarillo DESC, breached DESC, total_open DESC
        ";

        return $this->pdo->query($sql)->fetchAll() ?: [];
    }

    /**
     * Inicializa tracking para casos abiertos.
     * - minutes/days desde received_at (compat)
     * - Insertamos también sla_started_at/business_minutes/sla_ignored
     */
    public function initializeSlaTracking(): int
    {
        $sql = "
            INSERT IGNORE INTO case_sla_tracking
                (case_id, sla_started_at, business_minutes, sla_ignored,
                 current_sla_state, days_since_creation, minutes_since_creation,
                 sla_due_at, breached, last_updated, created_at)
            SELECT
                c.id AS case_id,
                NULL AS sla_started_at,
                NULL AS business_minutes,
                0 AS sla_ignored,

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

    /**
     * Update SLA (compat + hábil):
     * - sigue actualizando minutes_since_creation/days_since_creation
     * - cálculo hábil paginado para NO quedarse atrás
     */
    public function updateSlaTracking(): int
    {
        // 1) Compatibilidad (como lo tenías)
        $sqlCompat = "
            UPDATE case_sla_tracking cst
            JOIN cases c ON c.id = cst.case_id
            JOIN case_statuses cs ON cs.id = c.status_id
            SET
                cst.minutes_since_creation = TIMESTAMPDIFF(MINUTE, {$this->clockField()}, NOW()),
                cst.days_since_creation = TIMESTAMPDIFF(DAY, {$this->clockField()}, NOW()),
                cst.last_updated = NOW(6)
            WHERE cs.is_final = 0
        ";
        $stmt = $this->pdo->prepare($sqlCompat);
        $stmt->execute();
        $touched = $stmt->rowCount();

        // 2) Hábil (paginado, barre TODO)
        $updatedBusiness = $this->updateSlaTrackingBusinessAll(batchSize: 800);
        return $touched + $updatedBusiness;
    }

    /**
     * ✅ update por minutos hábiles, paginado por case_id (evita quedarse en primeros N)
     * Retorna: [updatedRows, lastCaseId]
     */
    public function updateSlaTrackingBusinessPaged(int $batchSize = 800, int $afterCaseId = 0): array
    {
        $clock = $this->loadBusinessClock();
        $now = $this->nowBogota();

        $sql = "
            SELECT
                c.id AS case_id,
                c.received_at,
                cs.pauses_sla,

                cst.sla_ignored,
                cst.sla_started_at,
                cst.business_minutes,
                cst.sla_due_at

            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            JOIN case_sla_tracking cst ON cst.case_id = c.id
            WHERE cs.is_final = 0
              AND c.id > :after
            ORDER BY c.id ASC
            LIMIT :lim
        ";

        $st = $this->pdo->prepare($sql);
        $st->bindValue(':after', $afterCaseId, PDO::PARAM_INT);
        $st->bindValue(':lim', $batchSize, PDO::PARAM_INT);
        $st->execute();

        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) return [0, $afterCaseId];

        $upd = $this->pdo->prepare("
            UPDATE case_sla_tracking
            SET
                sla_started_at = :sla_started_at,
                business_minutes = :business_minutes,
                current_sla_state = :state,
                sla_due_at = :sla_due_at,
                breached = :breached,
                last_updated = NOW(6)
            WHERE case_id = :case_id
        ");

        $updated = 0;
        $lastId = $afterCaseId;

        foreach ($rows as $r) {
            $caseId = (int)$r['case_id'];
            $lastId = $caseId;

            $ignored = ((int)($r['sla_ignored'] ?? 0) === 1);
            $pauses  = ((int)($r['pauses_sla'] ?? 0) === 1);

            // Pausado o ignorado => VERDE, business_minutes=0, due_at=NULL, breached=0
            if ($ignored || $pauses) {
                $upd->execute([
                    ':sla_started_at'   => $r['sla_started_at'] ?? null,
                    ':business_minutes' => 0,
                    ':state'            => 'VERDE',
                    ':sla_due_at'       => null,
                    ':breached'         => 0,
                    ':case_id'          => $caseId,
                ]);
                $updated += $upd->rowCount();
                continue;
            }

            $receivedAtStr = (string)($r['received_at'] ?? '');
            if ($receivedAtStr === '') {
                // Si no hay received_at, no podemos calcular hábil
                continue;
            }

            $receivedAt = $this->dtBogota($receivedAtStr);

            // ✅ Start base calculado SIEMPRE desde received_at
            $baseStart = $clock->normalizeStart($receivedAt);
            $slaStart = $baseStart;

            // Si ya había sla_started_at, lo respetamos SOLO si es razonable
            if (!empty($r['sla_started_at'])) {
                try {
                    $existing = $this->dtBogota((string)$r['sla_started_at']);
                    $existingNorm = $clock->normalizeStart($existing);

                    $diffSeconds = $existing->getTimestamp() - $receivedAt->getTimestamp();

                    if ($existing < $receivedAt || $diffSeconds > 86400) { // > 24h
                        $slaStart = $baseStart;
                    } else {
                        $slaStart = $existingNorm;
                    }
                } catch (\Throwable) {
                    $slaStart = $baseStart;
                }
            }

            // ✅ Re-normaliza final (por si cambió calendario/festivos)
            $slaStart = $clock->normalizeStart($slaStart);

            $bizMins  = $clock->diffBusinessMinutes($slaStart, $now);
            $state    = $this->semaforoFromBusinessMinutes($bizMins);
            $breached = ($bizMins > 720) ? 1 : 0;

            // ✅ recalcular SIEMPRE el due_at hábil
            $dueAt = $clock->addBusinessMinutes($slaStart, 720)->format('Y-m-d H:i:s');

            $upd->execute([
                ':sla_started_at'   => $slaStart->format('Y-m-d H:i:s'),
                ':business_minutes' => $bizMins,
                ':state'            => $state,
                ':sla_due_at'       => $dueAt,
                ':breached'         => $breached,
                ':case_id'          => $caseId,
            ]);

            $updated += $upd->rowCount();
        }

        return [$updated, $lastId];
    }

    /**
     * ✅ barre TODO iterando batches hasta terminar
     * (la clave para que no se te queden casos “colgados”)
     */
    public function updateSlaTrackingBusinessAll(int $batchSize = 800, int $maxLoops = 2000): int
    {
        $totalUpdated = 0;
        $after = 0;

        for ($i = 0; $i < $maxLoops; $i++) {
            [$upd, $last] = $this->updateSlaTrackingBusinessPaged($batchSize, $after);
            $totalUpdated += $upd;

            if ($last === $after) break; // no avanzó => fin
            $after = $last;
        }

        return $totalUpdated;
    }

    public function getSemaforoDistribution(?int $userId = null): array
    {
        // ✅ HY093 fix: el mismo placeholder no puede repetirse en UNIONs
        $params = [];

        $andUser1 = $userId ? " AND c.assigned_user_id = :user_id1 " : "";
        $andUser2 = $userId ? " AND c.assigned_user_id = :user_id2 " : "";
        $andUser3 = $userId ? " AND c.assigned_user_id = :user_id3 " : "";
        $andUser4 = $userId ? " AND c.assigned_user_id = :user_id4 " : "";
        $andUser5 = $userId ? " AND c.assigned_user_id = :user_id5 " : "";
        $andUser6 = $userId ? " AND c.assigned_user_id = :user_id6 " : "";

        if ($userId) {
            $params[':user_id1'] = $userId;
            $params[':user_id2'] = $userId;
            $params[':user_id3'] = $userId;
            $params[':user_id4'] = $userId;
            $params[':user_id5'] = $userId;
            $params[':user_id6'] = $userId;
        }

        $sql = "
            SELECT
                'ROJO' AS estado,
                COUNT(*) AS total,
                'Prioridad alta / riesgo de incumplimiento' AS descripcion,
                '#ef4444' AS color,
                'bi-exclamation-octagon-fill' AS icono
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
            WHERE cs.is_final = 0
            AND c.is_responded = 0
            AND cst.case_id IS NOT NULL
            AND cst.current_sla_state = 'ROJO'
            {$andUser1}

            UNION ALL

            SELECT
                'AMARILLO' AS estado,
                COUNT(*) AS total,
                'Próximo a vencer' AS descripcion,
                '#f59e0b' AS color,
                'bi-exclamation-triangle-fill' AS icono
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
            WHERE cs.is_final = 0
            AND c.is_responded = 0
            AND cst.case_id IS NOT NULL
            AND cst.current_sla_state = 'AMARILLO'
            {$andUser2}

            UNION ALL

            SELECT
                'VERDE' AS estado,
                COUNT(*) AS total,
                'Dentro de plazo' AS descripcion,
                '#10b981' AS color,
                'bi-check-circle-fill' AS icono
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
            WHERE cs.is_final = 0
            AND c.is_responded = 0
            AND cst.case_id IS NOT NULL
            AND cst.current_sla_state = 'VERDE'
            {$andUser3}

            UNION ALL

            SELECT
                'EN_PROCESO' AS estado,
                COUNT(*) AS total,
                'Casos en atención activa' AS descripcion,
                '#fd7e14' AS color,
                'bi-gear-fill' AS icono
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            WHERE cs.code = 'EN_PROCESO'
            AND cs.is_final = 0
            {$andUser4}

            UNION ALL

            SELECT
                'RESPONDIDOS' AS estado,
                COUNT(*) AS total,
                'Casos ya contestados' AS descripcion,
                '#3b82f6' AS color,
                'bi-chat-square-text-fill' AS icono
            FROM cases c
            WHERE c.is_responded = 1
            {$andUser5}

            UNION ALL

            SELECT
                'CERRADOS' AS estado,
                COUNT(*) AS total,
                'Casos finalizados' AS descripcion,
                '#6c757d' AS color,
                'bi-check2-all' AS icono
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            WHERE cs.is_final = 1
            {$andUser6}

            ORDER BY FIELD(estado,
                'ROJO',
                'AMARILLO',
                'VERDE',
                'EN_PROCESO',
                'RESPONDIDOS',
                'CERRADOS'
            )
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
                COALESCE(cst.minutes_since_creation, TIMESTAMPDIFF(MINUTE, {$this->clockField()}, NOW())) AS minutes_since_creation,

                -- métricas hábiles
                cst.sla_started_at,
                cst.business_minutes,

                cst.current_sla_state AS semaforo_actual,

                COALESCE(cst.breached, 0) AS breached,
                cst.sla_due_at,
                cst.warn_yellow_at,
                cst.warn_red_at

            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN users u ON u.id = c.assigned_user_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id

            WHERE cs.is_final = 0
              AND c.is_responded = 0
              AND cst.case_id IS NOT NULL
              AND cst.current_sla_state = :semaforo
              {$andUser}

            ORDER BY
              CASE WHEN cst.sla_due_at IS NULL THEN 1 ELSE 0 END ASC,
              cst.sla_due_at ASC,
              cst.business_minutes DESC,
              c.received_at ASC
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
        /**
         * ✅ Sin COALESCE(cst.current_sla_state,'VERDE'): semáforo SOLO con tracking.
         */
        $sql = "
            SELECT
                (SELECT COUNT(*) FROM cases) AS total_casos,

                (SELECT COUNT(*)
                 FROM cases c
                 JOIN case_statuses cs ON cs.id = c.status_id
                 WHERE cs.is_final = 0
                ) AS abiertos,

                (SELECT COUNT(*) FROM cases WHERE is_responded = 1) AS respondidos,

                (SELECT COUNT(*)
                 FROM cases c
                 JOIN case_statuses cs ON cs.id = c.status_id
                 LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
                 WHERE cs.is_final = 0
                   AND c.is_responded = 0
                   AND cst.case_id IS NOT NULL
                   AND cst.current_sla_state = 'VERDE'
                ) AS verde,

                (SELECT COUNT(*)
                 FROM cases c
                 JOIN case_statuses cs ON cs.id = c.status_id
                 LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
                 WHERE cs.is_final = 0
                   AND c.is_responded = 0
                   AND cst.case_id IS NOT NULL
                   AND cst.current_sla_state = 'AMARILLO'
                ) AS amarillo,

                (SELECT COUNT(*)
                 FROM cases c
                 JOIN case_statuses cs ON cs.id = c.status_id
                 LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
                 WHERE cs.is_final = 0
                   AND c.is_responded = 0
                   AND cst.case_id IS NOT NULL
                   AND cst.current_sla_state = 'ROJO'
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

        $sqlTop = "
            SELECT
                u.id AS user_id,
                u.full_name AS agente,
                COUNT(c.id) AS casos_asignados,
                SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) AS casos_resueltos,
                ROUND(
                    SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0),
                    1
                ) AS tasa_respuesta
            FROM cases c
            JOIN users u ON u.id = c.assigned_user_id
            WHERE u.is_active = 1
            GROUP BY u.id, u.full_name
            ORDER BY casos_asignados DESC
            LIMIT 5
        ";

        $result['top_agentes'] = $this->pdo->query($sqlTop)->fetchAll() ?: [];
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

    public function getSemaforoStatusBreakdown(string $semaforo, ?int $userId = null): array
    {
        $semaforo = strtoupper(trim($semaforo));
        if (!in_array($semaforo, ['VERDE','AMARILLO','ROJO'], true)) return [];

        $andUser = $userId ? " AND c.assigned_user_id = :user_id " : "";
        $params = [];
        if ($userId) $params[':user_id'] = $userId;

        $sql = "
            SELECT
                cs.code AS status_code,
                cs.name AS status_name,
                COUNT(*) AS total
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
            WHERE cs.is_final = 0
              AND c.is_responded = 0
              AND cst.case_id IS NOT NULL
              AND cst.current_sla_state = :semaforo
              {$andUser}
            GROUP BY cs.code, cs.name
            ORDER BY total DESC
        ";

        $st = $this->pdo->prepare($sql);
        $st->bindValue(':semaforo', $semaforo);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v, PDO::PARAM_INT);
        }
        $st->execute();
        return $st->fetchAll() ?: [];
    }

    public function getAdditionalStates(?int $assignedUserId = null): array
    {
        $params = [];
        $whereUser = $assignedUserId ? " AND c.assigned_user_id = :uid" : "";
        if ($assignedUserId) $params[':uid'] = $assignedUserId;

        $sql = "
            SELECT
                SUM(CASE WHEN cs.code = 'EN_PROCESO' THEN 1 ELSE 0 END) AS en_proceso,
                SUM(CASE WHEN cs.code = 'RESPONDIDO' THEN 1 ELSE 0 END) AS respondido,
                SUM(CASE WHEN cs.is_final = 1 THEN 1 ELSE 0 END) AS cerrados
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            WHERE 1=1
            {$whereUser}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: ['en_proceso' => 0, 'respondido' => 0, 'cerrados' => 0];
    }
}
