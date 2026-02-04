<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Auth\Auth;
use App\Repos\CasesRepo;
use App\Repos\MessagesRepo;
use App\Repos\AttachmentsRepo;
use App\Repos\EventsRepo;
use App\Repos\UsersRepo;

final class CasesController
{
    private CasesRepo $casesRepo;
    private MessagesRepo $messagesRepo;
    private AttachmentsRepo $attachmentsRepo;
    private EventsRepo $eventsRepo;
    private UsersRepo $usersRepo;

    public function __construct(private PDO $pdo, private array $config)
    {
        $this->casesRepo = new CasesRepo($pdo);
        $this->messagesRepo = new MessagesRepo($pdo);
        $this->attachmentsRepo = new AttachmentsRepo($pdo);
        $this->eventsRepo = new EventsRepo($pdo);
        $this->usersRepo = new UsersRepo($pdo);
    }

    public function inbox(): void
    {
        $status = isset($_GET['status']) ? strtoupper(trim((string)$_GET['status'])) : null;

        // AGENTE: por defecto ve solo lo asignado a él
        $assignedUserId = null;
        if (Auth::hasRole('AGENTE') && !Auth::hasRole('SUPERVISOR') && !Auth::hasRole('ADMIN')) {
            $assignedUserId = Auth::id();
        }

        // Supervisor/Admin: default NUEVO si no viene filtro
        if (($status === null || $status === '') && (Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN'))) {
            $status = 'NUEVO';
        }

        $cases = $this->casesRepo->listInbox($status, $assignedUserId);

        $this->render('cases/inbox.php', [
            'cases' => $cases,
            'status' => $status,
        ]);
    }

    public function detail(int $caseId): void
    {
        $case = $this->casesRepo->findCase($caseId);
        if (!$case) {
            http_response_code(404);
            echo "Case not found";
            exit;
        }

        // Permisos: AGENTE solo puede ver si está asignado
        if (Auth::hasRole('AGENTE') && !Auth::hasRole('SUPERVISOR') && !Auth::hasRole('ADMIN')) {
            if ((int)($case['assigned_user_id'] ?? 0) !== (int)Auth::id()) {
                http_response_code(403);
                echo "Forbidden";
                exit;
            }
        }

        $messages = $this->messagesRepo->listByCase($caseId);
        $attachments = $this->attachmentsRepo->listByCase($caseId);
        $events = $this->eventsRepo->listByCase($caseId);

        $agents = [];
        if (Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN')) {
            $agents = $this->usersRepo->listAgents();
        }

        $this->render('cases/detail.php', [
            'case' => $case,
            'messages' => $messages,
            'attachments' => $attachments,
            'events' => $events,
            'agents' => $agents,
        ]);
    }

    private function render(string $view, array $params = []): void
    {
        extract($params, EXTR_SKIP);
        $viewPath = dirname(__DIR__) . '/views/' . $view;
        include dirname(__DIR__) . '/views/layout.php';
    }
}
