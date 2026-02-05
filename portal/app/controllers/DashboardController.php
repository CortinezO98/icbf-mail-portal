<?php
// File: portal/app/controllers/DashboardController.php (PARTE MODIFICADA)
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Auth\Auth;
use App\Repos\MetricsRepo;
use App\Auth\Csrf;

use function App\Config\url;

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
        // Solo ejecutar una vez al día
        $lastInit = $_SESSION['_sla_last_init'] ?? 0;
        if (time() - $lastInit > 86400) { // 24 horas
            try {
                $initialized = $this->metrics->initializeSlaTracking();
                $updated = $this->metrics->updateSlaTracking();
                
                $_SESSION['_sla_last_init'] = time();
                
                if ($initialized > 0 || $updated > 0) {
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
        $byAgent = $isSupervisor ? $this->metrics->realtimeByAgent() : [];
        
        // Distribución del semáforo
        $semaforoDistribution = $this->metrics->getSemaforoDistribution($uid);
        
        // Casos críticos (ROJO)
        $criticalCases = $this->metrics->getCasesBySemaforo('ROJO', $uid, 15);
        
        // Casos por vencer (AMARILLO)
        $warningCases = $this->metrics->getCasesBySemaforo('AMARILLO', $uid, 10);
        
        // Tendencias semanales
        $weeklyTrends = $this->metrics->getWeeklyTrends($uid);
        
        // Reporte ejecutivo (solo Admin)
        $executiveReport = $isAdmin ? $this->metrics->getExecutiveReport() : [];

        $viewPath = dirname(__DIR__) . '/views/dashboard/index.php';
        include dirname(__DIR__) . '/views/layout.php';
    }

    // NUEVO: Vista de semáforo específico
    public function semaforo(string $estado): void
    {
        $isSupervisor = Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN');
        $uid = $isSupervisor ? null : (int)Auth::id();
        
        // Validar estado
        $estadosValidos = ['verde', 'amarillo', 'rojo'];
        if (!in_array(strtolower($estado), $estadosValidos)) {
            http_response_code(404);
            echo "Estado no válido";
            exit;
        }
        
        $estadoUpper = strtoupper($estado);
        $cases = $this->metrics->getCasesBySemaforo($estadoUpper, $uid, 50);
        
        $viewPath = dirname(__DIR__) . '/views/dashboard/semaforo.php';
        include dirname(__DIR__) . '/views/layout.php';
    }


}