<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Csrf;
use App\Repos\CasesRepo;
use App\Repos\CaseEventsRepo;
use PDO;

final class CaseActionsController
{
    private CasesRepo $cases;
    private CaseEventsRepo $events;

    public function __construct(private PDO $pdo, private array $config)
    {
        $this->cases  = new CasesRepo($pdo);
        $this->events = new CaseEventsRepo($pdo);
    }

    private function clientIp(): ?string { return $_SERVER['REMOTE_ADDR'] ?? null; }
    private function userAgent(): ?string { return $_SERVER['HTTP_USER_AGENT'] ?? null; }

    private function assertCanAct(array $case): void
    {
        // Admin/Supervisor siempre; agente solo si assigned_user_id == Auth::id()
        if (Auth::hasRole('ADMIN') || Auth::hasRole('SUPERVISOR')) return;

        $uid = (int)Auth::id();
        if ((int)($case['assigned_user_id'] ?? 0) !== $uid) {
            http_response_code(403);
            echo "No autorizado: el caso no está asignado a ti.";
            exit;
        }
    }

    public function escalate(int $caseId): void
    {
        Csrf::validateOrFail($_POST['csrf'] ?? '');

        $note = (string)($_POST['escalated_note'] ?? '');
        $uid  = (int)Auth::id();

        $this->pdo->beginTransaction();
        try {
            $case = $this->cases->findCaseForUpdate($caseId);
            $this->assertCanAct($case);

            $fromStatusId = (int)$case['status_id'];
            $toStatusId   = $this->cases->escalate($caseId, $uid, $note);

            $this->events->addStatusChange(
                caseId: $caseId,
                actorUserId: $uid,
                source: 'PORTAL',
                ip: $this->clientIp(),
                ua: $this->userAgent(),
                eventType: 'ESCALATED',
                fromStatusId: $fromStatusId,
                toStatusId: $toStatusId,
                details: ['note' => $note]
            );

            $this->pdo->commit();
            header('Location: ' . $this->config['base_url'] . '/cases/' . $caseId);
            exit;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            http_response_code(400);
            echo "Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            exit;
        }
    }

    public function close(int $caseId): void
    {
        Csrf::validateOrFail($_POST['csrf'] ?? '');

        $note   = (string)($_POST['closed_note'] ?? '');
        $ticket = (string)($_POST['closed_ticket'] ?? '');
        $uid    = (int)Auth::id();

        // Detecta code real del cerrado desde config (para que no adivinemos)
        $closedCode = (string)($this->config['closed_status_code'] ?? 'CLOSED');

        $this->pdo->beginTransaction();
        try {
            $case = $this->cases->findCaseForUpdate($caseId);
            $this->assertCanAct($case);

            $fromStatusId = (int)$case['status_id'];
            $toStatusId   = $this->cases->close($caseId, $uid, $note, $ticket, $closedCode);

            $this->events->addStatusChange(
                caseId: $caseId,
                actorUserId: $uid,
                source: 'PORTAL',
                ip: $this->clientIp(),
                ua: $this->userAgent(),
                eventType: 'CLOSED',
                fromStatusId: $fromStatusId,
                toStatusId: $toStatusId,
                details: ['note' => $note, 'ticket' => $ticket]
            );

            $this->pdo->commit();
            header('Location: ' . $this->config['base_url'] . '/cases/' . $caseId);
            exit;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            http_response_code(400);
            echo "Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            exit;
        }
    }
}
