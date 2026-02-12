<?php
declare(strict_types=1);

use App\Auth\Auth;
use function App\Config\url;

function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function n($v): int { return (int)($v ?? 0); }
function fnum($v, int $dec = 1): string {
  if ($v === null || $v === '') return '—';
  if (!is_numeric($v)) return esc((string)$v);
  return number_format((float)$v, $dec, '.', '');
}

/**
 * ✅ Recomendación aplicada (SIN romper):
 * - Si existe business_minutes (tracking hábil), lo usamos para mostrar "Tiempo" en horas hábiles.
 * - Si no existe, caemos a minutes_since_creation (compat).
 * - Si no existe, caemos a days_since_creation / dias_desde_creacion.
 */
function computeTimeLabel(array $row): string {
  // 1) Preferir horas hábiles (business_minutes)
  $biz = $row['business_minutes'] ?? null;
  if ($biz !== null && $biz !== '' && is_numeric($biz)) {
    $bizMins = (int)$biz;
    if ($bizMins > 0) return fnum($bizMins / 60, 1) . ' h (hábiles)';
    return '0.0 h (hábiles)';
  }

  // 2) Compat: minutos desde creación (minutes_since_creation)
  $mins = $row['minutes_since_creation'] ?? null;
  if ($mins !== null && $mins !== '' && is_numeric($mins)) {
    $m = (int)$mins;
    if ($m > 0) return fnum($m / 60, 1) . ' h';
  }

  // 3) Compat: días (puede venir en español o inglés)
  $dias = $row['dias_desde_creacion'] ?? ($row['days_since_creation'] ?? null);
  if ($dias !== null && $dias !== '' && is_numeric($dias)) {
    $d = (int)$dias;
    if ($d > 0) return $d . ' días';
  }

  // 4) Compat extra: algunas vistas usan "minutes_since_creation" o "minutes_since_received"
  $mins2 = $row['minutes_since_received'] ?? null;
  if ($mins2 !== null && $mins2 !== '' && is_numeric($mins2)) {
    $m2 = (int)$mins2;
    if ($m2 > 0) return fnum($m2 / 60, 1) . ' h';
  }

  return '—';
}

$total = is_array($data ?? null) ? count($data) : 0;
$csrfToken = $csrfToken ?? \App\Auth\Csrf::token();

$user = Auth::user();
$userName = $user['full_name'] ?? $user['username'] ?? 'Sistema';
?>

<div class="report-results">
  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
      <h1 class="h2 mb-1 fw-bold">
        <i class="bi bi-file-text me-2"></i>Resultados del Reporte
      </h1>
      <p class="text-muted mb-0">
        <?= $total ?> casos encontrados
        <?php if (!empty($params['start_date']) || !empty($params['end_date'])): ?>
          • Período:
          <?= !empty($params['start_date']) ? 'Desde ' . esc($params['start_date']) : '' ?>
          <?= !empty($params['end_date']) ? ' hasta ' . esc($params['end_date']) : '' ?>
        <?php endif; ?>
      </p>
    </div>

    <div class="d-flex gap-2">
      <a href="<?= esc(url('/reports')) ?>" class="btn btn-light">
        <i class="bi bi-arrow-left me-2"></i>Nuevo Reporte
      </a>
      <a href="<?= esc(url('/reports/generate')) ?>"
         onclick="event.preventDefault(); document.getElementById('exportForm').submit();"
         class="btn btn-primary">
        <i class="bi bi-download me-2"></i>Exportar
      </a>
    </div>
  </div>

  <!-- Re-export -->
  <form id="exportForm" method="post" action="<?= esc(url('/reports/generate')) ?>" style="display:none;">
    <input type="hidden" name="_csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="start_date" value="<?= esc($params['start_date'] ?? '') ?>">
    <input type="hidden" name="end_date" value="<?= esc($params['end_date'] ?? '') ?>">
    <input type="hidden" name="status" value="<?= esc($params['status'] ?? '') ?>">
    <input type="hidden" name="agent_id" value="<?= esc($params['agent_id'] ?? '') ?>">
    <input type="hidden" name="semaforo" value="<?= esc($params['semaforo'] ?? '') ?>">
    <input type="hidden" name="format" value="csv">
  </form>

  <!-- Resumen -->
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <div class="row text-center">
        <div class="col-md-3">
          <div class="h3 mb-1"><?= esc((string)($summary['total_cases'] ?? $total)) ?></div>
          <div class="text-muted small">Total casos</div>
        </div>
        <div class="col-md-3">
          <div class="h3 mb-1 text-success"><?= esc((string)($summary['responded'] ?? 0)) ?></div>
          <div class="text-muted small">Respondidos</div>
        </div>
        <div class="col-md-3">
          <div class="h3 mb-1 text-warning"><?= esc((string)($summary['pending'] ?? 0)) ?></div>
          <div class="text-muted small">Pendientes</div>
        </div>
        <div class="col-md-3">
          <div class="h3 mb-1"><?= esc((string)($summary['avg_response_hours'] ?? 0)) ?>h</div>
          <div class="text-muted small">Promedio 1ª respuesta</div>
        </div>
      </div>

      <?php if (!empty($summary['by_status'])): ?>
        <hr class="my-3">
        <h6 class="mb-2">Distribución por Estado</h6>
        <div class="row g-2">
          <?php foreach ($summary['by_status'] as $code => $status): ?>
            <div class="col-auto">
              <span class="badge bg-secondary">
                <?= esc($status['name'] ?? (string)$code) ?>: <?= esc((string)($status['count'] ?? 0)) ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($summary['by_semaforo'])): ?>
        <hr class="my-3">
        <h6 class="mb-2">Distribución por Semáforo</h6>
        <div class="row g-2">
          <?php foreach ($summary['by_semaforo'] as $semaforo => $info): ?>
            <div class="col-auto">
              <span class="badge bg-<?= esc((string)($info['color'] ?? 'secondary')) ?>">
                <?= esc((string)$semaforo) ?>: <?= esc((string)($info['count'] ?? 0)) ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tabla -->
  <div class="card shadow-sm">
    <div class="card-header">
      <h5 class="mb-0">Detalle de Casos</h5>
    </div>

    <div class="card-body p-0">
      <?php if (empty($data)): ?>
        <div class="text-center py-5">
          <i class="bi bi-inbox fs-1 text-muted mb-3"></i>
          <h5 class="text-muted">No se encontraron casos</h5>
          <p class="text-muted">Intenta con otros filtros</p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Asunto</th>
                <th>Remitente</th>
                <th>Estado</th>
                <th>Asignado a</th>
                <th>Creación</th>
                <th>Semáforo</th>
                <th>Tiempo</th>
                <th>Vence</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data as $row): ?>
                <?php
                  $id = $row['id'] ?? $row['case_id'] ?? '';
                  $subject = $row['subject'] ?? '';
                  $sender = $row['sender_email'] ?? ($row['requester_email'] ?? '');
                  $statusName = $row['status_name'] ?? ($row['status_code'] ?? '');

                  // assigned_to (vistas métricas) o assigned_user (export) o assigned_user_id (crudo)
                  $assigned = $row['assigned_to']
                    ?? ($row['assigned_user'] ?? ($row['assigned_user_id'] ?? 'Sin asignar'));

                  // semáforo puede venir como semaforo/current_sla_state/sla_state
                  $semaforo = $row['semaforo'] ?? ($row['current_sla_state'] ?? ($row['sla_state'] ?? ''));
                  $semaforo = (string)$semaforo;

                  $semaforoColor = match ($semaforo) {
                    'VERDE' => 'success',
                    'AMARILLO' => 'warning',
                    'ROJO' => 'danger',
                    'RESPONDIDO', 'CERRADO' => 'primary',
                    default => 'secondary',
                  };

                  // ✅ etiqueta de tiempo con fallback (business_minutes -> minutes_since_creation -> days)
                  $timeLabel = computeTimeLabel($row);

                  // ✅ “Creación” (reloj operativo): prioriza received_at, luego created_at
                  $createdAt = $row['received_at'] ?? ($row['created_at'] ?? null);

                  // “Vence”: tracking (sla_due_at) o due_at (cases)
                  $dueAt = $row['sla_due_at'] ?? ($row['due_at'] ?? null);
                ?>
                <tr>
                  <td class="fw-semibold"><?= esc((string)$id) ?></td>
                  <td><?= esc((string)$subject) ?></td>
                  <td><?= esc((string)($sender ?: '—')) ?></td>
                  <td><span class="badge bg-secondary"><?= esc((string)$statusName) ?></span></td>
                  <td><?= esc((string)$assigned) ?></td>
                  <td class="text-nowrap"><?= $createdAt ? esc(date('d/m/Y', strtotime((string)$createdAt))) : '—' ?></td>
                  <td><span class="badge bg-<?= esc($semaforoColor) ?>"><?= esc($semaforo ?: '—') ?></span></td>
                  <td><span class="badge bg-light text-dark"><?= esc($timeLabel) ?></span></td>
                  <td class="text-nowrap"><?= $dueAt ? esc((string)$dueAt) : '—' ?></td>
                  <td class="text-end">
                    <a href="<?= esc(url('/cases/' . (string)$id)) ?>" class="btn btn-sm btn-outline-primary">
                      <i class="bi bi-eye"></i>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card-footer text-muted">
      <small>Generado el <?= esc(date('d/m/Y H:i:s')) ?> por <?= esc($userName) ?></small>
    </div>
  </div>
</div>

<style>
.report-results { padding: 1rem; }
.table th { font-weight: 600; font-size: .85rem; text-transform: uppercase; letter-spacing: .4px; }
.table td { vertical-align: middle; }
.card { border-radius: 12px; }
.card-footer { background: #f8f9fa; border-top: 1px solid #e9ecef; }
</style>
