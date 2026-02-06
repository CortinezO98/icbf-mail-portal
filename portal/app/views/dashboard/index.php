<?php
declare(strict_types=1);

use App\Auth\Auth;
use function App\Config\url;


function n($v): int { return (int)($v ?? 0); }
function f($v): float { return (float)($v ?? 0); }
function dt($v): string {
    if (!$v) return '—';
    $ts = strtotime((string)$v);
    return $ts ? date('d/m/Y H:i', $ts) : esc((string)$v);
}

$csrfToken = \App\Auth\Csrf::token();
$isSupervisor = Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN');
$isAdmin = Auth::hasRole('ADMIN');

/**
 * Compatibilidad:
 * - Summary "plano": $summary['open_total'], $summary['sla_verde']...
 * - Summary "anidado": $summary['states']['nuevo'], $summary['semaforo']['verde']...
 */
$openTotal = n($summary['open_total'] ?? ($summary['total_open'] ?? 0));

$stNuevo      = n($summary['st_nuevo'] ?? ($summary['states']['nuevo'] ?? 0));
$stAsignado   = n($summary['st_asignado'] ?? ($summary['states']['asignado'] ?? 0));
$stEnProceso  = n($summary['st_enproceso'] ?? ($summary['states']['en_proceso'] ?? 0));
$stRespondido = n($summary['st_respondido'] ?? ($summary['states']['respondido'] ?? 0));

$slaVerde     = n($summary['sla_verde'] ?? ($summary['semaforo']['verde'] ?? 0));
$slaAmarillo  = n($summary['sla_amarillo'] ?? ($summary['semaforo']['amarillo'] ?? 0));
$slaRojo      = n($summary['sla_rojo'] ?? ($summary['semaforo']['rojo'] ?? 0));

/**
 * KPI breach:
 * - si backend lo trae (ideal): $summary['breached_cases']
 * - si no, fallback: contar en criticalCases los breached=1
 */
$breached = n($summary['breached_cases'] ?? 0);
if ($breached === 0 && !empty($criticalCases)) {
    $breached = 0;
    foreach ($criticalCases as $c) {
        if (n($c['breached'] ?? 0) === 1) $breached++;
    }
}

// promedio de primera respuesta (horas)
$avgRespHours = 0.0;
if (isset($summary['avg_response_hours'])) {
    $avgRespHours = f($summary['avg_response_hours']);
} elseif (isset($summary['avg_first_response_min'])) {
    $avgRespHours = round(f($summary['avg_first_response_min']) / 60, 2);
}

// porcentajes semáforo
$totalSLA = $slaVerde + $slaAmarillo + $slaRojo;
$verdePct = $totalSLA > 0 ? ($slaVerde / $totalSLA) * 100 : 0;
$amarilloPct = $totalSLA > 0 ? ($slaAmarillo / $totalSLA) * 100 : 0;
$rojoPct = $totalSLA > 0 ? ($slaRojo / $totalSLA) * 100 : 0;

// tasa respuesta (sobre abiertos del tablero)
$responseRate = $openTotal > 0 ? round(($stRespondido / $openTotal) * 100, 1) : 0;

// hints/leyendas (configurable por backend)
$semaforoHint = $summary['semaforo_hint'] ?? 'El semáforo se calcula automáticamente según la política de ANS (SLA) configurada.';
$semaforoLegend = $summary['semaforo_legend'] ?? [
    'VERDE' => 'Dentro de plazo',
    'AMARILLO' => 'Próximo a vencer',
    'ROJO' => 'Prioridad alta / posible incumplimiento',
];

// Tablas accionables (compatibilidad)
$criticalCases = $criticalCases ?? $critical ?? [];
$warningCases  = $warningCases ?? $warning ?? [];

// Helpers para pintar filas
function caseId(array $c): string {
    return (string)($c['id'] ?? $c['case_id'] ?? '');
}
function caseSubject(array $c): string {
    return (string)($c['subject'] ?? '');
}
function caseSender(array $c): string {
    return (string)($c['sender_email'] ?? $c['requester_email'] ?? '');
}
function caseStatus(array $c): string {
    return (string)($c['status_name'] ?? $c['status_code'] ?? '');
}
function caseTimeLabel(array $c): string {
    $mins = n($c['minutes_since_creation'] ?? $c['minutos_desde_recibido'] ?? 0);
    if ($mins > 0) return round($mins / 60, 1) . ' h';
    $days = n($c['days_since_creation'] ?? $c['dias_desde_recibido'] ?? $c['dias_desde_creacion'] ?? 0);
    if ($days > 0) return $days . ' días';
    return '—';
}
?>

<div class="dashboard-container">

  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
      <h1 class="h2 mb-1 fw-bold text-dark">
        <i class="bi bi-traffic-light me-2"></i>Tablero de Control • ANS (SLA)
      </h1>
      <p class="text-muted mb-0"><?= esc($semaforoHint) ?></p>
    </div>

    <div class="d-flex gap-2">
      <a href="<?= esc(url('/cases')) ?>" class="btn btn-light">
        <i class="bi bi-inbox me-2"></i>Bandeja
      </a>

      <?php if ($isSupervisor): ?>
        <a href="<?= esc(url('/reports')) ?>" class="btn btn-outline-primary">
          <i class="bi bi-file-earmark-bar-graph me-2"></i>Reportes
        </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <div class="col-lg-3 col-md-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between">
            <div>
              <div class="text-muted small">Casos abiertos</div>
              <div class="h2 mb-0 fw-bold"><?= $openTotal ?></div>
              <div class="text-muted small mt-1">
                Nuevos: <strong><?= $stNuevo ?></strong> • Asignados: <strong><?= $stAsignado ?></strong>
              </div>
            </div>
            <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3">
              <i class="bi bi-folder2-open fs-4"></i>
            </div>
          </div>
          <hr class="my-3">
          <div class="d-flex justify-content-between small">
            <span class="text-muted">En proceso</span>
            <span class="fw-semibold"><?= $stEnProceso ?></span>
          </div>
          <div class="d-flex justify-content-between small">
            <span class="text-muted">Respondidos</span>
            <span class="fw-semibold text-primary"><?= $stRespondido ?></span>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-3 col-md-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between">
            <div>
              <div class="text-muted small">Tasa de respuesta</div>
              <div class="h2 mb-0 fw-bold <?= $responseRate >= 80 ? 'text-success' : ($responseRate >= 50 ? 'text-warning' : 'text-danger') ?>">
                <?= $responseRate ?>%
              </div>
              <div class="text-muted small mt-1">Sobre casos abiertos del tablero</div>
            </div>
            <div class="bg-success bg-opacity-10 text-success rounded-circle p-3">
              <i class="bi bi-check2-circle fs-4"></i>
            </div>
          </div>
          <hr class="my-3">
          <div class="d-flex justify-content-between small">
            <span class="text-muted">Promedio 1ª respuesta</span>
            <span class="fw-semibold"><?= esc((string)$avgRespHours) ?>h</span>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-3 col-md-6">
      <div class="card shadow-sm h-100 border-start border-4 border-danger">
        <div class="card-body">
          <div class="d-flex justify-content-between">
            <div>
              <div class="text-muted small">Incumplidos (breach)</div>
              <div class="h2 mb-0 fw-bold text-danger"><?= $breached ?></div>
              <div class="text-muted small mt-1">Casos vencidos según SLA</div>
            </div>
            <div class="bg-danger bg-opacity-10 text-danger rounded-circle p-3">
              <i class="bi bi-exclamation-triangle fs-4"></i>
            </div>
          </div>
          <hr class="my-3">
          <a class="btn btn-sm btn-outline-danger w-100" href="<?= esc(url('/dashboard/semaforo/rojo')) ?>">
            <i class="bi bi-lightning-charge me-1"></i>Atender ROJOS
          </a>
        </div>
      </div>
    </div>

    <div class="col-lg-3 col-md-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between">
            <div>
              <div class="text-muted small">Semáforo (activos)</div>
              <div class="h4 mb-0 fw-bold"><?= $slaVerde ?>/<?= $slaAmarillo ?>/<?= $slaRojo ?></div>
              <div class="text-muted small mt-1">Verde/Amarillo/Rojo</div>
            </div>
            <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-3">
              <i class="bi bi-speedometer2 fs-4"></i>
            </div>
          </div>

          <hr class="my-3">

          <div class="d-flex align-items-center mb-2">
            <span class="badge bg-success me-2">Verde</span>
            <div class="progress flex-grow-1" style="height:6px;">
              <div class="progress-bar bg-success" style="width: <?= $verdePct ?>%"></div>
            </div>
            <small class="text-muted ms-2"><?= round($verdePct, 1) ?>%</small>
          </div>

          <div class="d-flex align-items-center mb-2">
            <span class="badge bg-warning me-2">Amarillo</span>
            <div class="progress flex-grow-1" style="height:6px;">
              <div class="progress-bar bg-warning" style="width: <?= $amarilloPct ?>%"></div>
            </div>
            <small class="text-muted ms-2"><?= round($amarilloPct, 1) ?>%</small>
          </div>

          <div class="d-flex align-items-center">
            <span class="badge bg-danger me-2">Rojo</span>
            <div class="progress flex-grow-1" style="height:6px;">
              <div class="progress-bar bg-danger" style="width: <?= $rojoPct ?>%"></div>
            </div>
            <small class="text-muted ms-2"><?= round($rojoPct, 1) ?>%</small>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Semáforo cards -->
  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="card border-start border-success border-4 shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="bg-success bg-opacity-10 text-success rounded-circle p-3 me-3">
              <i class="bi bi-check-circle-fill fs-2"></i>
            </div>
            <div>
              <h6 class="text-muted mb-1">VERDE</h6>
              <h2 class="mb-0 text-success"><?= $slaVerde ?></h2>
              <small class="text-muted"><?= esc($semaforoLegend['VERDE'] ?? 'Dentro de plazo') ?></small>
            </div>
          </div>
          <div class="alert alert-success alert-sm">
            <i class="bi bi-info-circle me-1"></i>
            <small>Atención normal. Mantener flujo y priorización.</small>
          </div>
          <div class="mt-2 text-end">
            <a href="<?= esc(url('/dashboard/semaforo/verde')) ?>" class="btn btn-sm btn-outline-success">Ver detalle</a>
          </div>
        </div>
      </div>
    </div>

    <div class="col-md-4">
      <div class="card border-start border-warning border-4 shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-3 me-3">
              <i class="bi bi-exclamation-triangle-fill fs-2"></i>
            </div>
            <div>
              <h6 class="text-muted mb-1">AMARILLO</h6>
              <h2 class="mb-0 text-warning"><?= $slaAmarillo ?></h2>
              <small class="text-muted"><?= esc($semaforoLegend['AMARILLO'] ?? 'Próximo a vencer') ?></small>
            </div>
          </div>
          <div class="alert alert-warning alert-sm">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <small>Atención prioritaria. Evitar que pasen a ROJO.</small>
          </div>
          <div class="mt-2 text-end">
            <a href="<?= esc(url('/dashboard/semaforo/amarillo')) ?>" class="btn btn-sm btn-outline-warning">Ver detalle</a>
          </div>
        </div>
      </div>
    </div>

    <div class="col-md-4">
      <div class="card border-start border-danger border-4 shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="bg-danger bg-opacity-10 text-danger rounded-circle p-3 me-3">
              <i class="bi bi-exclamation-octagon-fill fs-2"></i>
            </div>
            <div>
              <h6 class="text-muted mb-1">ROJO</h6>
              <h2 class="mb-0 text-danger"><?= $slaRojo ?></h2>
              <small class="text-muted"><?= esc($semaforoLegend['ROJO'] ?? 'Prioridad alta') ?></small>
            </div>
          </div>
          <div class="alert alert-danger alert-sm">
            <i class="bi bi-exclamation-octagon me-1"></i>
            <small>Atención inmediata. Riesgo/Incumplimiento de ANS.</small>
          </div>
          <div class="mt-2 text-end">
            <a href="<?= esc(url('/dashboard/semaforo/rojo')) ?>" class="btn btn-sm btn-outline-danger">Ver detalle</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Casos críticos -->
  <?php if (!empty($criticalCases)): ?>
    <div class="card shadow-sm border-danger mb-4">
      <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
        <div class="fw-semibold">
          <i class="bi bi-exclamation-octagon me-2"></i>Casos críticos (ROJO)
        </div>
        <span class="badge bg-light text-danger"><?= count($criticalCases) ?></span>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-hover table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Asunto</th>
                <th>Remitente</th>
                <th>Estado</th>
                <th>Tiempo</th>
                <th>Vence ANS</th>
                <th>Breach</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($criticalCases as $c): ?>
                <?php
                  $id = caseId($c);
                  $due = $c['sla_due_at'] ?? null;
                  $breach = n($c['breached'] ?? 0) === 1;
                ?>
                <tr class="table-danger">
                  <td class="fw-semibold"><?= esc($id ?: '—') ?></td>
                  <td><?= esc(caseSubject($c)) ?></td>
                  <td><?= esc(caseSender($c) ?: '—') ?></td>
                  <td><span class="badge bg-danger"><?= esc(caseStatus($c) ?: '—') ?></span></td>
                  <td><span class="badge bg-dark"><?= esc(caseTimeLabel($c)) ?></span></td>
                  <td class="text-nowrap"><?= $due ? dt($due) : '—' ?></td>
                  <td>
                    <?php if ($breach): ?>
                      <span class="badge bg-danger">Vencido</span>
                    <?php else: ?>
                      <span class="badge bg-secondary">OK</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <?php if ($id !== ''): ?>
                      <a href="<?= esc(url('/cases/' . $id)) ?>" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-eye me-1"></i>Ver
                      </a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Casos por vencer -->
  <?php if (!empty($warningCases)): ?>
    <div class="card shadow-sm border-warning mb-4">
      <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
        <div class="fw-semibold">
          <i class="bi bi-exclamation-triangle me-2"></i>Casos por vencer (AMARILLO)
        </div>
        <span class="badge bg-light text-warning"><?= count($warningCases) ?></span>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-hover table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Asunto</th>
                <th>Remitente</th>
                <th>Estado</th>
                <th>Tiempo</th>
                <th>Vence ANS</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($warningCases as $c): ?>
                <?php
                  $id = caseId($c);
                  $due = $c['sla_due_at'] ?? null;
                ?>
                <tr class="table-warning">
                  <td class="fw-semibold"><?= esc($id ?: '—') ?></td>
                  <td><?= esc(caseSubject($c)) ?></td>
                  <td><?= esc(caseSender($c) ?: '—') ?></td>
                  <td><span class="badge bg-warning text-dark"><?= esc(caseStatus($c) ?: '—') ?></span></td>
                  <td><span class="badge bg-warning text-dark"><?= esc(caseTimeLabel($c)) ?></span></td>
                  <td class="text-nowrap"><?= $due ? dt($due) : '—' ?></td>
                  <td class="text-end">
                    <?php if ($id !== ''): ?>
                      <a href="<?= esc(url('/cases/' . $id)) ?>" class="btn btn-sm btn-outline-warning">
                        <i class="bi bi-eye me-1"></i>Ver
                      </a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>

</div>

<style>
.dashboard-container { padding: 1rem; }
.card { border-radius: 12px; border: 1px solid #e9ecef; transition: transform .2s ease; }
.card:hover { transform: translateY(-2px); }
.alert-sm { padding: .5rem; font-size: .875rem; margin-bottom: 1rem; }
.progress { border-radius: 10px; }
.progress-bar { border-radius: 10px; }
</style>
