<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Auth\Auth;
use App\Auth\Csrf;
use App\Repos\AgentPresenceRepo;

final class AgentPresenceController
{
    private AgentPresenceRepo $repo;

    public function __construct(private PDO $pdo, private array $config)
    {
        $this->repo = new AgentPresenceRepo($pdo);
    }

    private function isAgent(): bool
    {
        return Auth::hasAnyRole(['AGENTE', 'AGENT']);
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function current(): void
    {
        if (!$this->isAgent()) {
            $this->json(['ok' => false, 'message' => 'Solo aplica para agentes.'], 403);
        }

        $uid = (int)(Auth::id() ?? 0);
        $stale = (int)($this->config['agent_presence']['stale_seconds'] ?? 90);

        // GET inicial también funciona como reconexión segura: si la sesión
        // vuelve después de quedar stale, el repo la fuerza a NO ACD antes
        // de devolver el estado. Nunca revive un DISPONIBLE antiguo.
        $this->repo->heartbeat($uid, $stale);
        $current = $this->repo->getCurrent($uid);

        $this->json([
            'ok' => true,
            'presence' => $current,
            'statuses' => $this->repo->listSelectableStatuses(),
            'heartbeat_seconds' => (int)($this->config['agent_presence']['heartbeat_seconds'] ?? 30),
        ]);
    }

    public function update(): void
    {
        if (!$this->isAgent()) {
            $this->json(['ok' => false, 'message' => 'Solo aplica para agentes.'], 403);
        }

        Csrf::validate($_POST['_csrf'] ?? null);
        $statusCode = strtoupper(trim((string)($_POST['status_code'] ?? '')));
        $uid = (int)(Auth::id() ?? 0);

        try {
            $presence = $this->repo->setStatus(
                $uid,
                $statusCode,
                $uid,
                'PORTAL'
            );
            $this->json(['ok' => true, 'presence' => $presence]);
        } catch (\InvalidArgumentException $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function heartbeat(): void
    {
        if (!$this->isAgent()) {
            $this->json(['ok' => false, 'message' => 'Solo aplica para agentes.'], 403);
        }

        Csrf::validate($_POST['_csrf'] ?? null);
        $uid = (int)(Auth::id() ?? 0);
        $stale = (int)($this->config['agent_presence']['stale_seconds'] ?? 90);
        $this->repo->heartbeat($uid, $stale);
        $this->json(['ok' => true, 'presence' => $this->repo->getCurrent($uid)]);
    }

    public function supervisor(): void
    {
        $stale = (int)($this->config['agent_presence']['stale_seconds'] ?? 90);
        $maxCases = (int)($this->config['agent_presence']['max_active_cases'] ?? 2);

        $agents = $this->repo->listAgentStatus($stale, $maxCases);
        $summary = $this->repo->getOperationalSummary($stale, $maxCases);

        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);

        $this->render('agents/status.php', [
            'agents' => $agents,
            'summary' => $summary,
            'staleSeconds' => $stale,
            'maxActiveCases' => $maxCases,
            'flash' => $flash,
        ]);
    }

    public function supervisorData(): void
    {
        $stale = (int)($this->config['agent_presence']['stale_seconds'] ?? 90);
        $maxCases = (int)($this->config['agent_presence']['max_active_cases'] ?? 2);

        $this->json([
            'ok' => true,
            'agents' => $this->repo->listAgentStatus($stale, $maxCases),
            'summary' => $this->repo->getOperationalSummary($stale, $maxCases),
            'generated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function render(string $view, array $params = []): void
    {
        extract($params, EXTR_SKIP);
        $viewPath = dirname(__DIR__) . '/views/' . $view;
        include dirname(__DIR__) . '/views/layout.php';
    }
}
