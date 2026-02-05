<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Auth\Auth;
use App\Auth\Csrf;
use App\Repos\CasesRepo;
use App\Repos\UsersRepo;
use App\Repos\EventsRepo;

final class AutoAssignController
{
    private CasesRepo $casesRepo;
    private UsersRepo $usersRepo;
    private EventsRepo $eventsRepo;

    public function __construct(private PDO $pdo, private array $config)
    {
        $this->casesRepo  = new CasesRepo($pdo);
        $this->usersRepo  = new UsersRepo($pdo);
        $this->eventsRepo = new EventsRepo($pdo);
    }

    public function run(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // 1) Solo POST
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode([
                'ok' => false,
                'code' => 'METHOD_NOT_ALLOWED',
                'message' => 'Method Not Allowed',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 2) CSRF
        $csrf = (string)($_POST['_csrf'] ?? '');

        // Compatible con Csrf::check() o Csrf::validate()
        try {
            if (method_exists(Csrf::class, 'check')) {
                /** @phpstan-ignore-next-line */
                if (!Csrf::check($csrf)) {
                    http_response_code(419);
                    echo json_encode([
                        'ok' => false,
                        'code' => 'CSRF',
                        'message' => 'Token CSRF inválido',
                    ], JSON_UNESCAPED_UNICODE);
                    return;
                }
            } else {
                // validate normalmente lanza excepción si falla
                /** @phpstan-ignore-next-line */
                Csrf::validate($csrf ?: null);
            }
        } catch (\Throwable $e) {
            http_response_code(419);
            echo json_encode([
                'ok' => false,
                'code' => 'CSRF',
                'message' => 'Token CSRF inválido',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 3) Permisos: ADMIN o SUPERVISOR
        if (!Auth::hasRole('ADMIN') && !Auth::hasRole('SUPERVISOR')) {
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'code' => 'FORBIDDEN',
                'message' => 'Forbidden',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $limit = 200;

        $actorUserId = Auth::id();
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        try {
            $statusNuevoId    = $this->casesRepo->getStatusIdByCode('NUEVO');
            $statusAsignadoId = $this->casesRepo->getStatusIdByCode('ASIGNADO');

            if (!$statusNuevoId || !$statusAsignadoId) {
                http_response_code(500);
                echo json_encode([
                    'ok' => false,
                    'code' => 'STATUS_MISSING',
                    'message' => 'Faltan estados en DB (NUEVO/ASIGNADO).',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // 4) Traer pendientes (fuera o dentro, da igual; aquí lo hacemos antes)
            $caseIds = $this->casesRepo->listPendingUnassignedIds((int)$statusNuevoId, $limit);

            if (empty($caseIds)) {
                echo json_encode([
                    'ok' => true,
                    'code' => 'NO_PENDING',
                    'assigned' => 0,
                    'skipped' => 0,
                    'skipped_no_agents' => 0,
                    'message' => 'No hay casos pendientes por auto-asignar.',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $this->pdo->beginTransaction();

            $assigned = 0;
            $skipped = 0;
            $skippedNoAgents = 0;

            foreach ($caseIds as $caseId) {
                $caseId = (int)$caseId;

                // 5) Elegir agente balanceado
                $agentId = $this->usersRepo->pickLeastLoadedAgentId(); // debe retornar ?int

                if (!$agentId) {
                    // No hay agentes -> registrar evento y seguir
                    $this->eventsRepo->insertEvent(
                        caseId: $caseId,
                        actorUserId: $actorUserId,
                        source: 'PORTAL',
                        eventType: 'AUTO_ASSIGN_SKIPPED',
                        fromStatusId: (int)$statusNuevoId,
                        toStatusId: null,
                        ipAddress: ($ip !== '' ? $ip : null),
                        userAgent: ($ua !== '' ? $ua : null),
                        details: ['reason' => 'no_eligible_agents', 'mode' => 'bulk_auto'],
                    );
                    $skipped++;
                    $skippedNoAgents++;
                    continue;
                }

                // 6) Asignar solo si sigue NULL (idempotente)
                $ok = $this->casesRepo->assignToUserIfUnassigned(
                    caseId: $caseId,
                    agentId: (int)$agentId,
                    statusAsignadoId: (int)$statusAsignadoId
                );

                if (!$ok) {
                    // alguien lo asignó mientras corría (concurrencia)
                    $skipped++;
                    continue;
                }

                // 7) actualizar last_assigned_at
                $this->usersRepo->touchLastAssignedAt((int)$agentId);

                // 8) evento ASSIGNED bulk_auto
                $this->eventsRepo->insertEvent(
                    caseId: $caseId,
                    actorUserId: $actorUserId,
                    source: 'PORTAL',
                    eventType: 'ASSIGNED',
                    fromStatusId: (int)$statusNuevoId,
                    toStatusId: (int)$statusAsignadoId,
                    ipAddress: ($ip !== '' ? $ip : null),
                    userAgent: ($ua !== '' ? $ua : null),
                    details: ['mode' => 'bulk_auto', 'assigned_user_id' => (int)$agentId],
                );

                $assigned++;
            }

            $this->pdo->commit();

            // 9) Respuesta diferenciada para SweetAlert
            if ($assigned > 0) {
                echo json_encode([
                    'ok' => true,
                    'code' => 'ASSIGNED',
                    'assigned' => $assigned,
                    'skipped' => $skipped,
                    'skipped_no_agents' => $skippedNoAgents,
                    'message' => "Auto-asignación realizada. Asignados: {$assigned}. Omitidos: {$skipped}.",
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            if ($skippedNoAgents > 0) {
                echo json_encode([
                    'ok' => true,
                    'code' => 'NO_AGENTS',
                    'assigned' => 0,
                    'skipped' => $skipped,
                    'skipped_no_agents' => $skippedNoAgents,
                    'message' => 'No hay agentes elegibles para asignación (AGENTE + habilitado).',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            echo json_encode([
                'ok' => true,
                'code' => 'SKIPPED',
                'assigned' => 0,
                'skipped' => $skipped,
                'skipped_no_agents' => 0,
                'message' => 'No se asignaron casos (posible concurrencia o ya fueron asignados).',
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'code' => 'ERROR',
                'message' => 'Error interno al auto-asignar.',
                'detail' => !empty($this->config['debug']) ? $e->getMessage() : null,
            ], JSON_UNESCAPED_UNICODE);
        }
    }
}
