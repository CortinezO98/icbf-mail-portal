<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Auth\Auth;
use App\Auth\Csrf;
use App\Repos\CasesRepo;
use App\Repos\EventsRepo;

use function App\Config\url;

final class AssignmentsController
{
    private CasesRepo $casesRepo;
    private EventsRepo $eventsRepo;

    public function __construct(private PDO $pdo, private array $config)
    {
        $this->casesRepo = new CasesRepo($pdo);
        $this->eventsRepo = new EventsRepo($pdo);
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

        // Transaction for consistency
        $this->pdo->beginTransaction();
        try {
            $this->casesRepo->assignToUser($caseId, $agentId, $statusAsignadoId);
            $this->eventsRepo->insertAssigned($caseId, (int)Auth::id(), $agentId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        header('Location: ' . url('/cases/' . $caseId));
        exit;
    }
}
