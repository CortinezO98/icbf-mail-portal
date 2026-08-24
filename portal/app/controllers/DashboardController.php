<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;

use App\Auth\Auth;
use App\Repos\MetricsRepo;

final class DashboardController
{
    private MetricsRepo $metrics;

    public function __construct(private PDO $pdo, private array $config)
    {
        $this->metrics = new MetricsRepo($pdo);

        // Inicializar tracking SLA si es necesario (lock global, no por sesión)
        $this->initializeSlaSystem();
    }

    private function initializeSlaSystem(): void
    {
        // ✅ Lock global por archivo (evita depender de sesión)
        // Ruta: portal/storage/locks/sla_init.lock
        $locksDir = dirname(__DIR__, 2) . '/storage/locks';
        if (!is_dir($locksDir)) {
            @mkdir($locksDir, 0777, true);
        }

        $lockFile = $locksDir . '/sla_init.lock';

        $lastInit = 0;
        if (is_file($lockFile)) {
            $raw = @file_get_contents($lockFile);
            if ($raw !== false) {
                $lastInit = (int)trim($raw);
            }
        }

        // Solo ejecutar una vez cada 24h
        if (time() - $lastInit <= 86400) {
            return;
        }

        // Intento de lock con fopen+flock (best effort)
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            // fallback: no bloqueamos el portal, solo salimos
            return;
        }

        try {
            // lock exclusivo no bloqueante
            if (!@flock($fp, LOCK_EX | LOCK_NB)) {
                fclose($fp);
                return;
            }

            // Releer estando bloqueado
            @rewind($fp);
            $raw2 = @stream_get_contents($fp);
            $lastInit2 = (int)trim((string)$raw2);

            if (time() - $lastInit2 <= 86400) {
                @flock($fp, LOCK_UN);
                fclose($fp);
                return;
            }

            try {
                $initialized = $this->metrics->initializeSlaTracking();
                $updated     = $this->metrics->updateSlaTracking();

                // Persistir timestamp
                @ftruncate($fp, 0);
                @rewind($fp);
                @fwrite($fp, (string)time());

                if (($initialized ?? 0) > 0 || ($updated ?? 0) > 0) {
                    error_log("SLA System: Initialized {$initialized}, Updated {$updated} cases");
                }
            } catch (\Throwable $e) {
                error_log("SLA System Error: " . $e->getMessage());
                // igual actualizamos lock para no spamear cada request si hay un error permanente
                @ftruncate($fp, 0);
                @rewind($fp);
                @fwrite($fp, (string)time());
            }

            @flock($fp, LOCK_UN);
            fclose($fp);
        } catch (\Throwable $e) {
            // No romper portal
            try { @flock($fp, LOCK_UN); } catch (\Throwable) {}
            try { fclose($fp); } catch (\Throwable) {}
        }
    }

    public function index(): void
    {
        $isSupervisor = Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN');
        $isAdmin = Auth::hasRole('ADMIN');

        // Agente: solo lo suyo. Supervisor/Admin: global.
        $uid = $isSupervisor ? null : (int)Auth::id();

        // ✅ Refresh corto para que el tablero sea real-time (TTL + lock)
        $this->refreshSlaTrackingShortTtl();

        // Métricas principales
        $summary = $this->metrics->realtimeSummary($uid);
        if (!is_array($summary)) $summary = [];

        // ✅ Obtener estados adicionales (EN_PROCESO, CERRADOS) y fusionar con summary
        $additionalStates = $this->metrics->getAdditionalStates($uid);
        $summary = array_merge($summary, $additionalStates);

        // UX: textos del semáforo (para tu view)
        $summary['semaforo_hint'] = $summary['semaforo_hint']
            ?? 'El semáforo se calcula automáticamente según la política de ANS (SLA) configurada.';

        $summary['semaforo_legend'] = $summary['semaforo_legend']
            ?? [
                'VERDE' => '0 a < 2 horas hábiles (Dentro del plazo)',
                'AMARILLO' => '2 a 4 horas hábiles (Próximo a vencer)',
                'ROJO' => ' > 4 horas hábiles (Vencido)',
            ];

        $semaforoDistribution = $this->metrics->getSemaforoDistribution($uid);
        if (!is_array($semaforoDistribution)) $semaforoDistribution = [];

        $criticalCases = $this->metrics->getCasesBySemaforo('ROJO', $uid, 15);
        if (!is_array($criticalCases)) $criticalCases = [];

        $warningCases = $this->metrics->getCasesBySemaforo('AMARILLO', $uid, 10);
        if (!is_array($warningCases)) $warningCases = [];

        $weeklyTrends = $this->metrics->getWeeklyTrends($uid);
        if (!is_array($weeklyTrends)) $weeklyTrends = [];

        $byAgent = $isSupervisor ? $this->metrics->realtimeByAgent() : [];
        if (!is_array($byAgent)) $byAgent = [];

        $executiveReport = $isAdmin ? $this->metrics->getExecutiveReport() : [];
        if (!is_array($executiveReport)) $executiveReport = [];

        $this->render('dashboard/index.php', [
            'summary' => $summary,
            'semaforoDistribution' => $semaforoDistribution,
            'criticalCases' => $criticalCases,
            'warningCases' => $warningCases,
            'weeklyTrends' => $weeklyTrends,
            'byAgent' => $byAgent,
            'executiveReport' => $executiveReport,
        ]);
    }

    public function semaforo(string $estado): void
    {
        $isSupervisor = Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN');
        $uid = $isSupervisor ? null : (int)Auth::id();

        // ✅ Asegura que el listado sea real-time (TTL + lock)
        $this->refreshSlaTrackingShortTtl();

        $estado = strtolower(trim($estado));
        
        // ✅ Agregamos los nuevos estados válidos SIN eliminar los originales
        $valid = ['verde', 'amarillo', 'rojo', 'en_proceso', 'respondidos', 'cerrados'];
        
        if (!in_array($estado, $valid, true)) {
            http_response_code(404);
            echo "Estado no válido";
            exit;
        }

        // ✅ Para los estados del semáforo original (verde, amarillo, rojo)
        if (in_array($estado, ['verde', 'amarillo', 'rojo'], true)) {
            $estadoUpper = strtoupper($estado);
            $cases = $this->metrics->getCasesBySemaforo($estadoUpper, $uid, 50);
        } 
        // ✅ Para EN_PROCESO
        elseif ($estado === 'en_proceso') {
            $cases = $this->getCasesByStatus('EN_PROCESO', $uid, 50);
        }
        // ✅ Para RESPONDIDOS
        elseif ($estado === 'respondidos') {
            $cases = $this->getCasesByResponded($uid, 50);
        }
        // ✅ Para CERRADOS
        elseif ($estado === 'cerrados') {
            $cases = $this->getCasesByClosed($uid, 50);
        } else {
            $cases = [];
        }

        if (!is_array($cases)) $cases = [];

        $this->render('dashboard/semaforo.php', [
            'estado' => strtoupper($estado),
            'cases' => $cases,
        ]);
    }

    /**
     * Obtiene casos por código de estado (EN_PROCESO, ASIGNADO, NUEVO, etc)
     */
    private function getCasesByStatus(string $statusCode, ?int $userId, int $limit): array
    {
        $andUser = $userId ? " AND c.assigned_user_id = :user_id " : "";
        $params = [':status' => $statusCode];
        if ($userId) $params[':user_id'] = $userId;

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
                COALESCE(cst.business_minutes, 0) AS business_minutes,
                cst.sla_due_at,
                COALESCE(cst.breached, 0) AS breached
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN users u ON u.id = c.assigned_user_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
            WHERE cs.code = :status
              AND cs.is_final = 0
              {$andUser}
            ORDER BY 
                CASE WHEN cst.breached = 1 THEN 0 ELSE 1 END,
                cst.sla_due_at ASC,
                c.received_at ASC
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':status', $statusCode);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        if ($userId) $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Obtiene casos respondidos (is_responded = 1)
     */
    private function getCasesByResponded(?int $userId, int $limit): array
    {
        $andUser = $userId ? " AND c.assigned_user_id = :user_id " : "";
        $params = $userId ? [':user_id' => $userId] : [];

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
                c.first_response_at AS responded_at,
                COALESCE(cst.business_minutes, 0) AS business_minutes
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN users u ON u.id = c.assigned_user_id
            LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
            WHERE c.is_responded = 1
              AND cs.is_final = 0
              {$andUser}
            ORDER BY c.first_response_at DESC
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        if ($userId) $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Obtiene casos cerrados (is_final = 1)
     */
    private function getCasesByClosed(?int $userId, int $limit): array
    {
        $andUser = $userId ? " AND c.assigned_user_id = :user_id " : "";
        $params = $userId ? [':user_id' => $userId] : [];

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
                c.closed_at,
                c.resolved_at,
                c.updated_at
            FROM cases c
            JOIN case_statuses cs ON cs.id = c.status_id
            LEFT JOIN users u ON u.id = c.assigned_user_id
            WHERE cs.is_final = 1
              {$andUser}
            ORDER BY c.closed_at DESC, c.updated_at DESC
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        if ($userId) $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    private function render(string $view, array $params = []): void
    {
        extract($params, EXTR_SKIP);
        $viewPath = dirname(__DIR__) . '/views/' . $view;
        include dirname(__DIR__) . '/views/layout.php';
    }

    private function refreshSlaTrackingShortTtl(): void
    {
        // ✅ Refresh frecuente pero controlado (TTL + lock global)
        $locksDir = dirname(__DIR__, 2) . '/storage/locks';
        if (!is_dir($locksDir)) {
            @mkdir($locksDir, 0777, true);
        }

        $lockFile = $locksDir . '/sla_refresh.lock';

        $ttl = 120; // 2 minutos
        $lastRun = 0;

        if (is_file($lockFile)) {
            $raw = @file_get_contents($lockFile);
            if ($raw !== false) $lastRun = (int)trim($raw);
        }

        if (time() - $lastRun < $ttl) {
            return; // aún fresco
        }

        $fp = @fopen($lockFile, 'c+');
        if (!$fp) return;

        try {
            if (!@flock($fp, LOCK_EX | LOCK_NB)) {
                fclose($fp);
                return;
            }

            @rewind($fp);
            $raw2 = @stream_get_contents($fp);
            $lastRun2 = (int)trim((string)$raw2);

            if (time() - $lastRun2 < $ttl) {
                @flock($fp, LOCK_UN);
                fclose($fp);
                return;
            }

            try {
                // Asegura tracking + recalcula hábil
                $this->metrics->initializeSlaTracking();
                $this->metrics->updateSlaTracking();
            } catch (\Throwable $e) {
                error_log("SLA Refresh Error: " . $e->getMessage());
            }

            @ftruncate($fp, 0);
            @rewind($fp);
            @fwrite($fp, (string)time());

            @flock($fp, LOCK_UN);
            fclose($fp);
        } catch (\Throwable) {
            try { @flock($fp, LOCK_UN); } catch (\Throwable) {}
            try { fclose($fp); } catch (\Throwable) {}
        }
    }
}