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

        // Métricas principales
        $summary = $this->metrics->realtimeSummary($uid);
        if (!is_array($summary)) $summary = [];

        // UX: textos del semáforo (para tu view)
        $summary['semaforo_hint'] = $summary['semaforo_hint']
            ?? 'El semáforo se calcula automáticamente según la política de ANS (SLA) configurada.';

        $summary['semaforo_legend'] = $summary['semaforo_legend']
            ?? [
                'VERDE' => 'Dentro de plazo',
                'AMARILLO' => 'Próximo a vencer',
                'ROJO' => 'Prioridad alta / riesgo de incumplimiento',
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

        $estado = strtolower(trim($estado));
        $valid = ['verde', 'amarillo', 'rojo'];
        if (!in_array($estado, $valid, true)) {
            http_response_code(404);
            echo "Estado no válido";
            exit;
        }

        $estadoUpper = strtoupper($estado);
        $cases = $this->metrics->getCasesBySemaforo($estadoUpper, $uid, 50);
        if (!is_array($cases)) $cases = [];

        $this->render('dashboard/semaforo.php', [
            'estado' => $estadoUpper,
            'cases' => $cases,
        ]);
    }

    private function render(string $view, array $params = []): void
    {
        extract($params, EXTR_SKIP);
        $viewPath = dirname(__DIR__) . '/views/' . $view;
        include dirname(__DIR__) . '/views/layout.php';
    }
}
