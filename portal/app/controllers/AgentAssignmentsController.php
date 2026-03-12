<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use Throwable;
use App\Auth\Auth;
use App\Auth\Csrf;
use App\Repos\CasesRepo;
use App\Repos\UsersRepo;
use App\Repos\EventsRepo;

use function App\Config\url;

final class AgentAssignmentsController
{
    private CasesRepo $casesRepo;
    private UsersRepo $usersRepo;
    private EventsRepo $eventsRepo;

    public function __construct(private PDO $pdo, private array $config)
    {
        $this->casesRepo = new CasesRepo($pdo);
        $this->usersRepo = new UsersRepo($pdo);
        $this->eventsRepo = new EventsRepo($pdo);
    }

    public function index(): void
    {
        $sourceAgentId = isset($_GET['agent_id']) && $_GET['agent_id'] !== ''
            ? (int)$_GET['agent_id']
            : 0;

        $q = trim((string)($_GET['q'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(10, min(100, (int)($_GET['per_page'] ?? 20)));

        $agents = $this->usersRepo->listAssignableAgents();

        $result = [
            'data' => [],
            'pagination' => [
                'page' => 1,
                'per_page' => $perPage,
                'total_rows' => 0,
                'total_pages' => 1,
                'has_prev' => false,
                'has_next' => false,
                'offset' => 0,
            ],
            'summary' => [
                'total_assigned' => 0,
            ],
        ];

        if ($sourceAgentId > 0) {
            $result = $this->casesRepo->listAssignedByAgent($sourceAgentId, $q, $page, $perPage);
        }

        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);

        $this->render('cases/by_agent.php', [
            'agents' => $agents,
            'cases' => $result['data'] ?? [],
            'pagination' => $result['pagination'] ?? [],
            'summary' => $result['summary'] ?? ['total_assigned' => 0],
            'selectedAgentId' => $sourceAgentId,
            'q' => $q,
            '_csrf' => Csrf::token(),
            'flash' => $flash,
        ]);
    }

    public function bulkReassign(): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);

        $sourceAgentId = (int)($_POST['source_agent_id'] ?? 0);
        $targetAgentId = (int)($_POST['target_agent_id'] ?? 0);
        $caseIds = $_POST['case_ids'] ?? [];

        if ($sourceAgentId <= 0) {
            $this->flash('error', 'Debes seleccionar el agente origen.');
            $this->redirect('/cases/by-agent');
        }

        if ($targetAgentId <= 0) {
            $this->flash('error', 'Debes seleccionar el agente destino.');
            $this->redirect('/cases/by-agent?agent_id=' . $sourceAgentId);
        }

        if ($sourceAgentId === $targetAgentId) {
            $this->flash('error', 'El agente origen y destino no pueden ser el mismo.');
            $this->redirect('/cases/by-agent?agent_id=' . $sourceAgentId);
        }

        if (!is_array($caseIds) || empty($caseIds)) {
            $this->flash('error', 'Debes seleccionar al menos un caso.');
            $this->redirect('/cases/by-agent?agent_id=' . $sourceAgentId);
        }

        $caseIds = array_values(array_unique(array_filter(array_map('intval', $caseIds), fn($v) => $v > 0)));

        if (empty($caseIds)) {
            $this->flash('error', 'No se recibieron casos válidos para reasignar.');
            $this->redirect('/cases/by-agent?agent_id=' . $sourceAgentId);
        }

        $statusAsignadoId = $this->casesRepo->getStatusIdByCode('ASIGNADO');
        if (!$statusAsignadoId) {
            $this->flash('error', 'No existe el estado ASIGNADO en la base de datos.');
            $this->redirect('/cases/by-agent?agent_id=' . $sourceAgentId);
        }

        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $actorUserId = (int)(Auth::id() ?? 0);

        $this->pdo->beginTransaction();
        try {
            $eligibleCases = $this->casesRepo->lockAssignedCasesForReassign($caseIds, $sourceAgentId);

            if (empty($eligibleCases)) {
                $this->pdo->rollBack();
                $this->flash('warning', 'No se encontraron casos elegibles para reasignación.');
                $this->redirect('/cases/by-agent?agent_id=' . $sourceAgentId);
            }

            $reassigned = 0;

            foreach ($eligibleCases as $case) {
                $caseId = (int)$case['id'];
                $fromStatusId = isset($case['status_id']) ? (int)$case['status_id'] : null;

                $ok = $this->casesRepo->reassignAssignedCase(
                    $caseId,
                    $sourceAgentId,
                    $targetAgentId,
                    (int)$statusAsignadoId
                );

                if (!$ok) {
                    continue;
                }

                $this->eventsRepo->insertEvent(
                    caseId: $caseId,
                    actorUserId: $actorUserId,
                    source: 'PORTAL',
                    eventType: 'ASSIGNED',
                    fromStatusId: $fromStatusId,
                    toStatusId: (int)$statusAsignadoId,
                    ipAddress: $ip !== '' ? $ip : null,
                    userAgent: $ua !== '' ? $ua : null,
                    details: [
                        'mode' => 'bulk_reassign',
                        'from_assigned_user_id' => $sourceAgentId,
                        'assigned_to' => $targetAgentId,
                    ]
                );

                $reassigned++;
            }

            $this->pdo->commit();

            if ($reassigned > 0) {
                $this->flash('success', "Se reasignaron correctamente {$reassigned} caso(s).");
            } else {
                $this->flash('warning', 'No se pudo reasignar ningún caso. Puede que ya hayan cambiado de estado o de responsable.');
            }

            $this->redirect('/cases/by-agent?agent_id=' . $sourceAgentId);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $message = !empty($this->config['debug'])
                ? ('Error al reasignar casos: ' . $e->getMessage())
                : 'Ocurrió un error interno al reasignar los casos.';

            $this->flash('error', $message);
            $this->redirect('/cases/by-agent?agent_id=' . $sourceAgentId);
        }
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['_flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    private function redirect(string $path): void
    {
        header('Location: ' . url($path));
        exit;
    }

    private function render(string $view, array $params = []): void
    {
        extract($params, EXTR_SKIP);
        $viewPath = dirname(__DIR__) . '/views/' . $view;
        include dirname(__DIR__) . '/views/layout.php';
    }
}