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

        // Inicializar tracking SLA si es necesario
        $this->initializeSlaSystem();
    }

    private function initializeSlaSystem(): void
    {
        // Solo ejecutar una vez al día por sesión (sin romper)
        $lastInit = $_SESSION['_sla_last_init'] ?? 0;

        if (!is_int($lastInit)) {
            $lastInit = 0;
        }

        if (time() - $lastInit > 86400) { // 24 horas
            try {
                $initialized = $this->metrics->initializeSlaTracking();
                $updated = $this->metrics->updateSlaTracking();

                $_SESSION['_sla_last_init'] = time();

                if (($initialized ?? 0) > 0 || ($updated ?? 0) > 0) {
                    error_log("SLA System: Initialized {$initialized}, Updated {$updated} cases");
                }
            } catch (\Throwable $e) {
                error_log("SLA System Error: " . $e->getMessage());
            }
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
        if (!is_array($summary)) {
            $summary = [];
        }

        // (Opcional) Ajuste UX: texto del semáforo (para views pro)
        $summary['semaforo_hint'] = $summary['semaforo_hint']
            ?? 'El semáforo se calcula automáticamente según la política de ANS (SLA) configurada.';

        $summary['semaforo_legend'] = $summary['semaforo_legend']
            ?? [
                'VERDE' => 'Dentro de plazo',
                'AMARILLO' => 'Próximo a vencer',
                'ROJO' => 'Prioridad alta / riesgo de incumplimiento',
            ];

        // Distribución del semáforo (si la usas en el view o futuro)
        $semaforoDistribution = $this->metrics->getSemaforoDistribution($uid);
        if (!is_array($semaforoDistribution)) {
            $semaforoDistribution = [];
        }

        // Casos críticos / por vencer
        $criticalCases = $this->metrics->getCasesBySemaforo('ROJO', $uid, 15);
        if (!is_array($criticalCases)) {
            $criticalCases = [];
        }

        $warningCases = $this->metrics->getCasesBySemaforo('AMARILLO', $uid, 10);
        if (!is_array($warningCases)) {
            $warningCases = [];
        }

        // Tendencias semanales
        $weeklyTrends = $this->metrics->getWeeklyTrends($uid);
        if (!is_array($weeklyTrends)) {
            $weeklyTrends = [];
        }

        // Productividad por agente (solo supervisor/admin)
        $byAgent = $isSupervisor ? $this->metrics->realtimeByAgent() : [];
        if (!is_array($byAgent)) {
            $byAgent = [];
        }

        // Reporte ejecutivo (solo Admin)
        $executiveReport = $isAdmin ? $this->metrics->getExecutiveReport() : [];
        if (!is_array($executiveReport)) {
            $executiveReport = [];
        }

        // Render
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
        if (!is_array($cases)) {
            $cases = [];
        }

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
