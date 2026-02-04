<?php
declare(strict_types=1);

use App\Auth\Auth;
use function App\Config\url;

// Variables esperadas desde el controller:
// - $cases (array)
// - $status (string|null)  [si no existe, se deja en null]
$roleIsSupervisor = Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN');
$status = $status ?? null;

function esc($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function badge_status_class(string $code): string {
  return match (strtoupper($code)) {
    'NUEVO' => 'badge-status--nuevo',
    'ASIGNADO' => 'badge-status--asignado',
    'EN_PROCESO' => 'badge-status--enproceso',
    'RESPONDIDO' => 'badge-status--respondido',
    'CERRADO' => 'badge-status--cerrado',
    default => '',
  };
}

function badge_sla(string $sla): array {
  $sla = strtoupper(trim($sla));
  return match ($sla) {
    'OK' => ['text-bg-success', 'OK'],
    'WARN' => ['text-bg-warning', 'Por vencer'],
    'BREACH' => ['text-bg-danger', 'Vencido'],
    default => ['text-bg-secondary', $sla !== '' ? $sla : '—'],
  };
}
?>

<div class="page-title">
  <div>
    <h3>Bandeja</h3>
    <div class="text-muted small">
      <?= $roleIsSupervisor ? 'Vista de supervisión' : 'Vista de agente' ?>
      <?= $status ? '• Filtro: ' . esc($status) : '' ?>
    </div>
  </div>

  <div class="d-flex flex-wrap gap-2">
    <?php if ($roleIsSupervisor): ?>
      <a class="btn btn-outline-brand btn-sm" href="<?= esc(url('/cases?status=NUEVO')) ?>">
        Nuevos
      </a>
      <a class="btn btn-outline-brand btn-sm" href="<?= esc(url('/cases?status=ASIGNADO')) ?>">
        Asignados
      </a>
      <a class="btn btn-outline-brand btn-sm" href="<?= esc(url('/cases?status=EN_PROCESO')) ?>">
        En proceso
      </a>
      <a class="btn btn-outline-brand btn-sm" href="<?= esc(url('/cases?status=RESPONDIDO')) ?>">
        Respondidos
      </a>
      <a class="btn btn-outline-secondary btn-sm" href="<?= esc(url('/cases?status=CERRADO')) ?>">
        Cerrados
      </a>
    <?php else: ?>
      <a class="btn btn-outline-brand btn-sm" href="<?= esc(url('/cases')) ?>">
        Mis casos
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th style="width: 160px;">Caso</th>
          <th>Asunto</th>
          <th style="width: 240px;">Solicitante</th>
          <th style="width: 150px;">Estado</th>
          <th style="width: 120px;">ANS</th>
          <th style="width: 170px;" class="text-nowrap">Recibido</th>
          <th style="width: 170px;" class="text-nowrap">Últ. actividad</th>
        </tr>
      </thead>

      <tbody>
        <?php if (empty($cases)): ?>
          <tr>
            <td colspan="7" class="p-0">
              <div class="empty">
                <i class="bi bi-inbox"></i>
                <div class="fw-semibold mt-2">Sin resultados</div>
                <div class="small">No hay casos para los filtros actuales.</div>
              </div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($cases as $c): ?>
            <?php
              $caseId = (int)($c['id'] ?? 0);
              $caseNumber = (string)($c['case_number'] ?? '');
              $subject = (string)($c['subject'] ?? '');
              $reqName = (string)($c['requester_name'] ?? '');
              $reqEmail = (string)($c['requester_email'] ?? '');

              $statusCode = strtoupper((string)($c['status_code'] ?? ''));
              $statusName = (string)($c['status_name'] ?? $statusCode);

              $statusClass = badge_status_class($statusCode);

              [$slaBadge, $slaLabel] = badge_sla((string)($c['sla_state'] ?? ''));

              $receivedAt = (string)($c['received_at'] ?? '');
              $lastActivity = (string)($c['last_activity_at'] ?? '');
            ?>
            <tr>
              <td>
                <a href="<?= esc(url('/cases/' . $caseId)) ?>" class="fw-bold">
                  <?= esc($caseNumber) ?>
                </a>
              </td>

              <td>
                <div class="fw-semibold"><?= esc($subject) ?></div>
                <?php if (!empty($c['assigned_user_id']) && $roleIsSupervisor): ?>
                  <div class="text-muted small">
                    <i class="bi bi-person-check me-1"></i>
                    Asignado
                  </div>
                <?php endif; ?>
              </td>

              <td>
                <div class="fw-semibold"><?= esc($reqName) ?></div>
                <div class="text-muted small"><?= esc($reqEmail) ?></div>
              </td>

              <td>
                <span class="badge badge-status <?= esc($statusClass) ?>">
                  <?= esc($statusName ?: $statusCode) ?>
                </span>
              </td>

              <td>
                <span class="badge <?= esc($slaBadge) ?>">
                  <?= esc($slaLabel) ?>
                </span>
              </td>

              <td class="text-nowrap">
                <?= esc($receivedAt) ?>
              </td>

              <td class="text-nowrap">
                <?= esc($lastActivity) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
