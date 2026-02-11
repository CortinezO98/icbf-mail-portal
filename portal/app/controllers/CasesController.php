<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Auth\Auth;
use App\Auth\Csrf;
use App\Repos\CasesRepo;
use App\Repos\MessagesRepo;
use App\Repos\AttachmentsRepo;
use App\Repos\EventsRepo;
use App\Repos\UsersRepo;
use App\Repos\CaseEventsRepo;

use function App\Config\url;

final class CasesController
{
    private CasesRepo $casesRepo;
    private MessagesRepo $messagesRepo;
    private AttachmentsRepo $attachmentsRepo;
    private EventsRepo $eventsRepo; // se mantiene (no se rompe nada)
    private UsersRepo $usersRepo;
    private CaseEventsRepo $caseEventsRepo; // nuevo

    public function __construct(private PDO $pdo, private array $config)
    {
        $this->casesRepo = new CasesRepo($pdo);
        $this->messagesRepo = new MessagesRepo($pdo);
        $this->attachmentsRepo = new AttachmentsRepo($pdo);
        $this->eventsRepo = new EventsRepo($pdo);
        $this->usersRepo = new UsersRepo($pdo);
        $this->caseEventsRepo = new CaseEventsRepo($pdo);
    }

    public function inbox(): void
    {
        $status = isset($_GET['status']) ? strtoupper(trim((string)$_GET['status'])) : null;
        if ($status === '') $status = null;

        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 20;

        $assignedUserId = null;
        if (Auth::hasRole('AGENTE') && !Auth::hasRole('SUPERVISOR') && !Auth::hasRole('ADMIN')) {
            $assignedUserId = Auth::id();
        }

        if (($status === null || $status === '') && (Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN'))) {
            $status = 'NUEVO';
        }

        $result = $this->casesRepo->listInbox($status, $assignedUserId, $page, $perPage);

        if (isset($result['data']) && isset($result['pagination'])) {
            $cases = $result['data'];
            $pagination = $result['pagination'];
        } else {
            $cases = $result;
            $pagination = [
                'page' => 1,
                'per_page' => count($cases),
                'total_rows' => count($cases),
                'total_pages' => 1,
                'has_prev' => false,
                'has_next' => false,
                'offset' => 0
            ];
        }

        $unassignedCount = 0;
        if (Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN')) {
            $statusNuevoId = $this->casesRepo->getStatusIdByCode('NUEVO');
            $unassignedCount = $statusNuevoId ? $this->casesRepo->countUnassignedByStatus($statusNuevoId) : 0;
        }

        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);

        $this->render('cases/inbox.php', [
            'cases' => $cases,
            'status' => $status,
            'unassignedCount' => $unassignedCount,
            'pagination' => $pagination,
            'flash' => $flash,
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

        // Object-level auth (AGENTE solo ve/actúa lo suyo)
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

        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);

        $this->render('cases/detail.php', [
            'case' => $case,
            'messages' => $messages,
            'attachments' => $attachments,
            'events' => $events,
            'agents' => $agents,
            'flash' => $flash,
            '_csrf' => Csrf::token(),
        ]);
    }

    /**
     * POST /cases/{id}/escalate
     */
    public function escalate(int $caseId): void
    {
        // ✅ CSRF (mantener patrón del proyecto)
        Csrf::validate($_POST['_csrf'] ?? null);

        if (!Auth::check()) {
            http_response_code(401);
            echo "Unauthorized";
            exit;
        }

        $case = $this->casesRepo->findCase($caseId);
        if (!$case) {
            $this->flash('error', 'Caso no encontrado.');
            $this->redirect('/cases');
        }

        // ✅ AGENTE solo puede operar casos asignados a él
        if (Auth::hasRole('AGENTE') && !Auth::hasRole('SUPERVISOR') && !Auth::hasRole('ADMIN')) {
            if ((int)($case['assigned_user_id'] ?? 0) !== (int)Auth::id()) {
                http_response_code(403);
                echo "Forbidden";
                exit;
            }
        }

        $note = trim((string)($_POST['escalated_note'] ?? ''));

        try {
            $this->pdo->beginTransaction();

            $fromStatusId = (int)($case['status_id'] ?? 0);

            // ✅ usa ESCALATED interno
            $toStatusId = $this->casesRepo->escalate($caseId, (int)Auth::id(), $note);

            // ✅ Evento en case_events (pro)
            $this->caseEventsRepo->addStatusChange(
                $caseId,
                (int)Auth::id(),
                'PORTAL',
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                'ESCALATED',
                $fromStatusId ?: null,
                $toStatusId ?: null,
                ['note' => $note]
            );

            $this->pdo->commit();

            $this->flash('success', 'Caso escalado correctamente.');
            $this->redirect('/cases/' . $caseId);

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->flash('error', 'No se pudo escalar: ' . $e->getMessage());
            $this->redirect('/cases/' . $caseId);
        }
    }

    /**
     * POST /cases/{id}/close
     */
    public function close(int $caseId): void
    {
        // ✅ CSRF
        Csrf::validate($_POST['_csrf'] ?? null);

        if (!Auth::check()) {
            http_response_code(401);
            echo "Unauthorized";
            exit;
        }

        $case = $this->casesRepo->findCase($caseId);
        if (!$case) {
            $this->flash('error', 'Caso no encontrado.');
            $this->redirect('/cases');
        }

        // ✅ AGENTE solo puede operar casos asignados a él
        if (Auth::hasRole('AGENTE') && !Auth::hasRole('SUPERVISOR') && !Auth::hasRole('ADMIN')) {
            if ((int)($case['assigned_user_id'] ?? 0) !== (int)Auth::id()) {
                http_response_code(403);
                echo "Forbidden";
                exit;
            }
        }

        $note = trim((string)($_POST['closed_note'] ?? ''));
        $ticket = trim((string)($_POST['closed_ticket'] ?? ''));

        try {
            $this->pdo->beginTransaction();

            $fromStatusId = (int)($case['status_id'] ?? 0);

            // ✅ AQUÍ está el ajuste mínimo: tu BD usa CERRADO
            $toStatusId = $this->casesRepo->close($caseId, (int)Auth::id(), $note, $ticket, 'CERRADO');

            // ✅ Evento en case_events
            $this->caseEventsRepo->addStatusChange(
                $caseId,
                (int)Auth::id(),
                'PORTAL',
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                'CLOSED',
                $fromStatusId ?: null,
                $toStatusId ?: null,
                ['note' => $note, 'ticket' => $ticket]
            );

            $this->pdo->commit();

            $this->flash('success', 'Caso cerrado correctamente.');
            $this->redirect('/cases/' . $caseId);

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->flash('error', 'No se pudo cerrar: ' . $e->getMessage());
            $this->redirect('/cases/' . $caseId);
        }
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
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
