<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Auth\Auth;
use App\Auth\Csrf;
use App\Repos\CasesRepo;
use App\Repos\EventsRepo;
use App\Repos\AgentPresenceRepo;

use function App\Config\url;

final class AssignmentsController
{
    private CasesRepo $casesRepo;
    private EventsRepo $eventsRepo;
    private AgentPresenceRepo $presenceRepo;

    public function __construct(private PDO $pdo, private array $config)
    {
        $this->casesRepo = new CasesRepo($pdo);
        $this->eventsRepo = new EventsRepo($pdo);
        $this->presenceRepo = new AgentPresenceRepo($pdo);
    }

    public function assign(int $caseId): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);

        $agentId = (int)($_POST['agent_id'] ?? 0);
        if ($agentId <= 0) {
            http_response_code(400);
            echo "Invalid agent_id";
            exit;
        }

        $statusAsignadoId = $this->casesRepo->getStatusIdByCode('ASIGNADO');
        if (!$statusAsignadoId) {
            http_response_code(500);
            echo "case_statuses missing code ASIGNADO";
            exit;
        }

        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $staleSeconds = (int)($this->config['agent_presence']['stale_seconds'] ?? 90);
        $maxActiveCases = (int)($this->config['agent_presence']['max_active_cases'] ?? 2);

        $this->pdo->beginTransaction();
        try {
            // Mismo orden de locks que el assignment worker: caso -> presencia.
            $case = $this->casesRepo->findCaseForUpdate($caseId);
            $fromStatusId = isset($case['status_id']) ? (int)$case['status_id'] : null;

            $capacity = $this->presenceRepo->lockAssignableAgent(
                $agentId,
                $staleSeconds,
                $maxActiveCases
            );
            if ($capacity === null || (int)($capacity['free_slots'] ?? 0) < 1) {
                throw new \RuntimeException('El asesor no está Disponible, perdió conexión o ya alcanzó su capacidad máxima.');
            }

            $this->casesRepo->assignToUser($caseId, $agentId, $statusAsignadoId);

            $this->eventsRepo->insertAssigned(
                $caseId,
                Auth::id(),
                $agentId,
                $fromStatusId,
                $statusAsignadoId,
                $ip,
                $ua
            );

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $_SESSION['_flash'] = [
                'type' => 'warning',
                'message' => $e->getMessage(),
            ];
            header('Location: ' . url('/cases/' . $caseId));
            exit;
        }

        header('Location: ' . url('/cases/' . $caseId));
        exit;
    }
}
