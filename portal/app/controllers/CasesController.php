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
    private CaseEventsRepo $caseEventsRepo; // auditoría

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
        $statusWasProvided = array_key_exists('status', $_GET);

        $status = $statusWasProvided
            ? strtoupper(trim((string)($_GET['status'] ?? '')))
            : null;

        if ($status === 'ALL' || $status === '') {
            $status = null;
        }

        $q = trim((string)($_GET['q'] ?? ''));

        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 20;

        $assignedUserId = null;
        if (Auth::hasRole('AGENTE') && !Auth::hasRole('SUPERVISOR') && !Auth::hasRole('ADMIN')) {
            $assignedUserId = Auth::id();
        }

        if (
            !$statusWasProvided &&
            (Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN'))
        ) {
            $status = null;
        }

        try {
            $result = $this->casesRepo->listInbox($status, $assignedUserId, $q, $page, $perPage);

            if (isset($result['data']) && isset($result['pagination'])) {
                $cases = $result['data'];
                $pagination = $result['pagination'];
            } else {
                $cases = is_array($result) ? $result : [];
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
        } catch (\Throwable $e) {
            error_log('[CasesController::inbox] ' . $e->getMessage());

            http_response_code(500);
            echo 'Error interno al consultar la bandeja de casos.';
            exit;
        }

        $unassignedCount = 0;
        if (Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN')) {
            try {
                $statusNuevoId = $this->casesRepo->getStatusIdByCode('NUEVO');
                $unassignedCount = $statusNuevoId ? $this->casesRepo->countUnassignedByStatus($statusNuevoId) : 0;
            } catch (\Throwable $e) {
                error_log('[CasesController::inbox] Error contando no asignados: ' . $e->getMessage());
                $unassignedCount = 0;
            }
        }

        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);

        $this->render('cases/inbox.php', [
            'cases' => $cases,
            'status' => $status,
            'q' => $q,
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

        // NUEVO: casos relacionados por el mismo hilo
        $relatedCases = [];
        $threadConversationId = trim((string)($case['thread_conversation_id'] ?? ''));
        if ($threadConversationId !== '') {
            $relatedCases = $this->casesRepo->listRelatedByThread($threadConversationId, $caseId);
        }

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
            'relatedCases' => $relatedCases,
            'agents' => $agents,
            'flash' => $flash,
            '_csrf' => Csrf::token(),
        ]);
    }

    /**
     * POST /cases/{id}/start
     * Transición: ASIGNADO -> EN_PROCESO
     * Repo hace transacción; aquí NO abrimos transacción (evita "double beginTransaction").
     */
    public function start(int $caseId): void
    {
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

        if (Auth::hasRole('AGENTE') && !Auth::hasRole('SUPERVISOR') && !Auth::hasRole('ADMIN')) {
            if ((int)($case['assigned_user_id'] ?? 0) !== (int)Auth::id()) {
                http_response_code(403);
                echo "Forbidden";
                exit;
            }
        }

        try {
            $fromStatusId = (int)($case['status_id'] ?? 0);

            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

            $this->casesRepo->startProcess($caseId, (int)Auth::id(), $ip, $ua);

            $toStatusId = $this->casesRepo->requireStatusIdByCode('EN_PROCESO');

            $this->caseEventsRepo->addStatusChange(
                $caseId,
                (int)Auth::id(),
                'PORTAL',
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                'STARTED',
                $fromStatusId ?: null,
                $toStatusId ?: null,
                ['note' => 'Inicio de gestión']
            );

            $this->flash('success', 'Gestión iniciada.');
            $this->redirect('/cases/' . $caseId);
        } catch (\Throwable $e) {
            $this->flash('error', 'No se pudo iniciar la gestión: ' . $e->getMessage());
            $this->redirect('/cases/' . $caseId);
        }
    }

    /**
     * POST /cases/{id}/finish
     * Transición: EN_PROCESO -> RESPONDIDO
     * Repo hace transacción; aquí NO abrimos transacción.
     */
    public function finish(int $caseId): void
    {
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

        if (Auth::hasRole('AGENTE') && !Auth::hasRole('SUPERVISOR') && !Auth::hasRole('ADMIN')) {
            if ((int)($case['assigned_user_id'] ?? 0) !== (int)Auth::id()) {
                http_response_code(403);
                echo "Forbidden";
                exit;
            }
        }

        try {
            $fromStatusId = (int)($case['status_id'] ?? 0);

            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

            $this->casesRepo->finishProcess($caseId, (int)Auth::id(), $ip, $ua);

            $toStatusId = $this->casesRepo->requireStatusIdByCode('RESPONDIDO');

            $this->caseEventsRepo->addStatusChange(
                $caseId,
                (int)Auth::id(),
                'PORTAL',
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                'RESPONDED',
                $fromStatusId ?: null,
                $toStatusId ?: null,
                ['note' => 'Finaliza gestión / marcado como respondido']
            );

            $this->flash('success', 'Gestión finalizada. Caso marcado como RESPONDIDO.');
            $this->redirect('/cases/' . $caseId);
        } catch (\Throwable $e) {
            $this->flash('error', 'No se pudo finalizar la gestión: ' . $e->getMessage());
            $this->redirect('/cases/' . $caseId);
        }
    }

    /**
     * POST /cases/{id}/escalate
     * Repo incluye transacción; aquí NO abrimos transacción.
     */
    public function escalate(int $caseId): void
    {
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

        if (Auth::hasRole('AGENTE') && !Auth::hasRole('SUPERVISOR') && !Auth::hasRole('ADMIN')) {
            if ((int)($case['assigned_user_id'] ?? 0) !== (int)Auth::id()) {
                http_response_code(403);
                echo "Forbidden";
                exit;
            }
        }

        $note = trim((string)($_POST['escalated_note'] ?? ''));

        try {
            $fromStatusId = (int)($case['status_id'] ?? 0);

            $toStatusId = $this->casesRepo->escalate($caseId, (int)Auth::id(), $note);

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

            $this->flash('success', 'Caso escalado correctamente.');
            $this->redirect('/cases/' . $caseId);
        } catch (\Throwable $e) {
            $this->flash('error', 'No se pudo escalar: ' . $e->getMessage());
            $this->redirect('/cases/' . $caseId);
        }
    }

    /**
     * POST /cases/{id}/close
     * Repo incluye transacción; aquí NO abrimos transacción.
     */
    public function close(int $caseId): void
    {
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
            $fromStatusId = (int)($case['status_id'] ?? 0);

            $toStatusId = $this->casesRepo->close($caseId, (int)Auth::id(), $note, $ticket, 'CERRADO');

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

            $this->flash('success', 'Caso cerrado correctamente.');
            $this->redirect('/cases/' . $caseId);
        } catch (\Throwable $e) {
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

    /**
     * POST /cases/{id}/finish-escalation
     * Transición: ESCALATED -> EN_PROCESO
     */
    public function finishEscalation(int $caseId): void
    {
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

        if (Auth::hasRole('AGENTE') && !Auth::hasRole('SUPERVISOR') && !Auth::hasRole('ADMIN')) {
            if ((int)($case['assigned_user_id'] ?? 0) !== (int)Auth::id()) {
                http_response_code(403);
                echo "Forbidden";
                exit;
            }
        }

        try {
            $fromStatusId = (int)($case['status_id'] ?? 0);

            $toStatusId = $this->casesRepo->finishEscalation($caseId, (int)Auth::id());

            $this->caseEventsRepo->addStatusChange(
                $caseId,
                (int)Auth::id(),
                'PORTAL',
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                'ESCALATION_FINISHED',
                $fromStatusId ?: null,
                $toStatusId ?: null,
                ['note' => 'Finaliza escalamiento']
            );

            $this->flash('success', 'Escalamiento finalizado. Puedes continuar la gestión.');
            $this->redirect('/cases/' . $caseId);

        } catch (\Throwable $e) {
            $this->flash('error', 'No se pudo finalizar escalamiento: ' . $e->getMessage());
            $this->redirect('/cases/' . $caseId);
        }
    }
}