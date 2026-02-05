<?php
declare(strict_types=1);

use App\Auth\Auth;
use function App\Config\url;

$title = "Dashboard de Reportes";
$k = $kpis ?? [];

$total = (int)($k['total_cases'] ?? 0);
$open = (int)($k['open_cases'] ?? 0);
$closed = (int)($k['closed_cases'] ?? 0);
$responded = (int)($k['responded_cases'] ?? 0);
$breached = (int)($k['breached_cases'] ?? 0);
$verde = (int)($k['sla_verde'] ?? 0);
$amarillo = (int)($k['sla_amarillo'] ?? 0);
$rojo = (int)($k['sla_rojo'] ?? 0);

// Calcular porcentajes
$openRate = $total > 0 ? round(($open / $total) * 100, 1) : 0;
$closedRate = $total > 0 ? round(($closed / $total) * 100, 1) : 0;
$responseRate = $total > 0 ? round(($responded / $total) * 100, 1) : 0;
$breachRate = $total > 0 ? round(($breached / $total) * 100, 1) : 0;

// Calcular SLA total
$slaTotal = $verde + $amarillo + $rojo;
$slaVerdeRate = $slaTotal > 0 ? round(($verde / $slaTotal) * 100, 1) : 0;
$slaAmarilloRate = $slaTotal > 0 ? round(($amarillo / $slaTotal) * 100, 1) : 0;
$slaRojoRate = $slaTotal > 0 ? round(($rojo / $slaTotal) * 100, 1) : 0;
?>
<div class="container-fluid py-4">
  <!-- Header -->
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h1 class="h3 mb-1 fw-bold text-dark">Dashboard de Reportes</h1>
      <p class="text-muted mb-0">Análisis completo de métricas y desempeño del sistema</p>
    </div>
    
    <div class="d-flex gap-2 align-items-center">
      <div class="badge bg-light text-dark border px-3 py-2">
        <i class="bi bi-calendar-range me-1"></i>
        <?= htmlspecialchars(date('d/m/Y', strtotime($start)), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars(date('d/m/Y', strtotime($end)), ENT_QUOTES, 'UTF-8') ?>
      </div>
      <div class="dropdown">
        <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
          <i class="bi bi-download me-1"></i> Exportar
        </button>
        <ul class="dropdown-menu">
          <li>
            <a class="dropdown-item" href="<?= htmlspecialchars(url('/reports/export?type=sla&start='.$start.'&end='.$end.'&format=csv' . ($mailbox_id ? '&mailbox_id='.$mailbox_id : '')), ENT_QUOTES, 'UTF-8') ?>">
              <i class="bi bi-filetype-csv me-2"></i> CSV
            </a>
          </li>
          <li>
            <a class="dropdown-item" href="<?= htmlspecialchars(url('/reports/export?type=sla&start='.$start.'&end='.$end.'&format=xlsx' . ($mailbox_id ? '&mailbox_id='.$mailbox_id : '')), ENT_QUOTES, 'UTF-8') ?>">
              <i class="bi bi-file-earmark-excel me-2"></i> Excel
            </a>
          </li>
        </ul>
      </div>
    </div>
  </div>

  <!-- Filtros -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
      <form method="get" action="<?= htmlspecialchars(url('/reports'), ENT_QUOTES, 'UTF-8') ?>">
        <div class="row g-3 align-items-end">
          <div class="col-md-3">
            <label class="form-label small fw-semibold text-muted mb-1">Fecha Inicio</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-calendar"></i></span>
              <input class="form-control" type="date" name="start" value="<?= htmlspecialchars($start, ENT_QUOTES, 'UTF-8') ?>">
            </div>
          </div>
          <div class="col-md-3">
            <label class="form-label small fw-semibold text-muted mb-1">Fecha Fin</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-calendar"></i></span>
              <input class="form-control" type="date" name="end" value="<?= htmlspecialchars($end, ENT_QUOTES, 'UTF-8') ?>">
            </div>
          </div>
          <div class="col-md-3">
            <label class="form-label small fw-semibold text-muted mb-1">Mailbox</label>
            <input class="form-control" type="number" min="1" placeholder="Todos" name="mailbox_id" value="<?= htmlspecialchars((string)($mailbox_id ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="col-md-3">
            <button class="btn btn-primary w-100" type="submit">
              <i class="bi bi-funnel me-1"></i> Aplicar Filtros
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- KPIs Cards -->
  <div class="row g-3 mb-4">
    <!-- Casos Totales -->
    <div class="col-xl-3 col-md-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h6 class="text-muted mb-1">Casos Totales</h6>
              <h3 class="fw-bold mb-2"><?= $total ?></h3>
            </div>
            <div class="bg-primary bg-opacity-10 p-2 rounded">
              <i class="bi bi-folder2-open text-primary fs-4"></i>
            </div>
          </div>
          <div class="mt-3">
            <div class="d-flex justify-content-between small">
              <span class="text-muted">Abiertos</span>
              <span class="fw-semibold"><?= $open ?> <small class="text-muted">(<?= $openRate ?>%)</small></span>
            </div>
            <div class="d-flex justify-content-between small">
              <span class="text-muted">Cerrados</span>
              <span class="fw-semibold"><?= $closed ?> <small class="text-muted">(<?= $closedRate ?>%)</small></span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Respondidos -->
    <div class="col-xl-3 col-md-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h6 class="text-muted mb-1">Respondidos</h6>
              <h3 class="fw-bold mb-2"><?= $responded ?></h3>
            </div>
            <div class="bg-success bg-opacity-10 p-2 rounded">
              <i class="bi bi-check-circle text-success fs-4"></i>
            </div>
          </div>
          <div class="mt-3">
            <div class="progress" style="height: 6px;">
              <div class="progress-bar bg-success" style="width: <?= $responseRate ?>%"></div>
            </div>
            <div class="small text-muted mt-2">Tasa de respuesta: <?= $responseRate ?>%</div>
          </div>
        </div>
      </div>
    </div>

    <!-- SLA -->
    <div class="col-xl-3 col-md-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h6 class="text-muted mb-1">Estado SLA</h6>
              <h3 class="fw-bold mb-2"><?= "{$verde}/{$amarillo}/{$rojo}" ?></h3>
            </div>
            <div class="bg-warning bg-opacity-10 p-2 rounded">
              <i class="bi bi-speedometer2 text-warning fs-4"></i>
            </div>
          </div>
          <div class="mt-3">
            <div class="d-flex align-items-center mb-1">
              <span class="badge bg-success me-2">Verde</span>
              <div class="flex-grow-1">
                <div class="progress" style="height: 4px;">
                  <div class="progress-bar bg-success" style="width: <?= $slaVerdeRate ?>%"></div>
                </div>
              </div>
              <small class="text-muted ms-2"><?= $slaVerdeRate ?>%</small>
            </div>
            <div class="d-flex align-items-center mb-1">
              <span class="badge bg-warning me-2">Amarillo</span>
              <div class="flex-grow-1">
                <div class="progress" style="height: 4px;">
                  <div class="progress-bar bg-warning" style="width: <?= $slaAmarilloRate ?>%"></div>
                </div>
              </div>
              <small class="text-muted ms-2"><?= $slaAmarilloRate ?>%</small>
            </div>
            <div class="d-flex align-items-center">
              <span class="badge bg-danger me-2">Rojo</span>
              <div class="flex-grow-1">
                <div class="progress" style="height: 4px;">
                  <div class="progress-bar bg-danger" style="width: <?= $slaRojoRate ?>%"></div>
                </div>
              </div>
              <small class="text-muted ms-2"><?= $slaRojoRate ?>%</small>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Vencidos y Alertas -->
    <div class="col-xl-3 col-md-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h6 class="text-muted mb-1">Vencidos</h6>
              <h3 class="fw-bold mb-2 text-danger"><?= $breached ?></h3>
            </div>
            <div class="bg-danger bg-opacity-10 p-2 rounded">
              <i class="bi bi-exclamation-triangle text-danger fs-4"></i>
            </div>
          </div>
          <div class="mt-3">
            <div class="d-flex justify-content-between small">
              <span class="text-muted">Tasa de vencimiento</span>
              <span class="fw-semibold text-danger"><?= $breachRate ?>%</span>
            </div>
            <?php if ($missing_attachments > 0): ?>
              <div class="alert alert-warning py-2 mt-2 mb-0 small d-flex align-items-center">
                <i class="bi bi-paperclip me-2"></i>
                <span>Gaps adjuntos: <?= (int)$missing_attachments ?></span>
              </div>
            <?php else: ?>
              <div class="alert alert-success py-2 mt-2 mb-0 small d-flex align-items-center">
                <i class="bi bi-check-circle me-2"></i>
                <span>Todos los adjuntos sincronizados</span>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Gráfico de Serie Diaria -->
  <div class="row g-3 mb-4">
    <div class="col-12">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 py-3">
          <h6 class="mb-0 fw-semibold">
            <i class="bi bi-graph-up me-2"></i> Serie Diaria - Casos Recibidos
          </h6>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-hover">
              <thead class="table-light">
                <tr>
                  <th class="text-muted fw-semibold small">Día</th>
                  <th class="text-muted fw-semibold small text-end">Casos Recibidos</th>
                  <th class="text-muted fw-semibold small text-end">Tendencia</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($daily)): ?>
                  <tr>
                    <td colspan="3" class="text-center text-muted py-4">
                      <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                      No hay datos para el período seleccionado
                    </td>
                  </tr>
                <?php else: 
                  $maxCases = max(array_column($daily, 'cnt'));
                  foreach ($daily as $d): 
                    $percentage = $maxCases > 0 ? ($d['cnt'] / $maxCases) * 100 : 0;
                ?>
                  <tr>
                    <td>
                      <span class="fw-medium"><?= htmlspecialchars(date('d/m/Y', strtotime($d['day'])), ENT_QUOTES, 'UTF-8') ?></span>
                    </td>
                    <td class="text-end fw-bold"><?= (int)$d['cnt'] ?></td>
                    <td class="text-end">
                      <div class="d-flex align-items-center justify-content-end">
                        <div class="progress flex-grow-1 me-2" style="width: 100px; height: 8px;">
                          <div class="progress-bar bg-primary" style="width: <?= $percentage ?>%"></div>
                        </div>
                        <small class="text-muted"><?= round($percentage) ?>%</small>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabla de Productividad -->
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-0 py-3">
      <div class="d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold">
          <i class="bi bi-people me-2"></i> Productividad por Agente
        </h6>
        <small class="text-muted">Datos desde cache de métricas diarias</small>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th class="text-muted fw-semibold small ps-4">Agente</th>
              <th class="text-muted fw-semibold small text-end">Asignados</th>
              <th class="text-muted fw-semibold small text-end">Resueltos</th>
              <th class="text-muted fw-semibold small text-end">Vencidos</th>
              <th class="text-muted fw-semibold small text-end">Tiempo Resp. (h)</th>
              <th class="text-muted fw-semibold small text-end pe-4">% Cumplimiento SLA</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($agents)): ?>
              <tr>
                <td colspan="6" class="text-center text-muted py-4">
                  <i class="bi bi-person-x fs-4 d-block mb-2"></i>
                  No hay datos de agentes para el período seleccionado
                </td>
              </tr>
            <?php else: foreach ($agents as $a): 
              $complianceRate = (float)$a['sla_compliance_rate'];
              $complianceColor = $complianceRate >= 90 ? 'success' : ($complianceRate >= 70 ? 'warning' : 'danger');
            ?>
              <tr>
                <td class="ps-4">
                  <div class="d-flex align-items-center">
                    <div class="bg-light rounded-circle p-2 me-3">
                      <i class="bi bi-person text-muted"></i>
                    </div>
                    <div>
                      <div class="fw-medium"><?= htmlspecialchars((string)$a['agent_name'], ENT_QUOTES, 'UTF-8') ?></div>
                      <small class="text-muted">ID: <?= (int)$a['agent_id'] ?></small>
                    </div>
                  </div>
                </td>
                <td class="text-end">
                  <span class="fw-bold"><?= (int)$a['cases_assigned'] ?></span>
                </td>
                <td class="text-end">
                  <span class="fw-bold text-success"><?= (int)$a['cases_resolved'] ?></span>
                </td>
                <td class="text-end">
                  <?php if ((int)$a['cases_overdue'] > 0): ?>
                    <span class="badge bg-danger"><?= (int)$a['cases_overdue'] ?></span>
                  <?php else: ?>
                    <span class="text-muted">0</span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <span class="fw-medium"><?= htmlspecialchars((string)$a['avg_response_hours'], ENT_QUOTES, 'UTF-8') ?></span>
                </td>
                <td class="text-end pe-4">
                  <div class="d-flex align-items-center justify-content-end">
                    <div class="progress flex-grow-1 me-2" style="width: 80px; height: 8px;">
                      <div class="progress-bar bg-<?= $complianceColor ?>" style="width: <?= min($complianceRate, 100) ?>%"></div>
                    </div>
                    <span class="badge bg-<?= $complianceColor ?>-subtle text-<?= $complianceColor ?>">
                      <?= htmlspecialchars((string)$a['sla_compliance_rate'], ENT_QUOTES, 'UTF-8') ?>%
                    </span>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php if (!empty($agents)): ?>
      <div class="card-footer bg-white border-0 py-3">
        <div class="row text-center">
          <div class="col">
            <small class="text-muted">Total Agentes</small>
            <div class="fw-bold"><?= count($agents) ?></div>
          </div>
          <div class="col">
            <small class="text-muted">Total Casos Asignados</small>
            <div class="fw-bold">
              <?= array_sum(array_column($agents, 'cases_assigned')) ?>
            </div>
          </div>
          <div class="col">
            <small class="text-muted">Promedio % SLA</small>
            <div class="fw-bold">
              <?php 
                $avgCompliance = !empty($agents) ? array_sum(array_column($agents, 'sla_compliance_rate')) / count($agents) : 0;
                echo round($avgCompliance, 1) . '%';
              ?>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Estilos adicionales -->
<style>
.card {
  transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
  border-radius: 12px;
}
.card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 16px rgba(0,0,0,0.1) !important;
}
.progress {
  border-radius: 10px;
}
.progress-bar {
  border-radius: 10px;
}
.badge {
  border-radius: 8px;
}
.table th {
  border-top: none;
  font-weight: 600;
}
.table tbody tr:hover {
  background-color: rgba(var(--bs-primary-rgb), 0.05);
}
.alert {
  border-radius: 8px;
  border: none;
}
.input-group-text {
  background-color: #f8f9fa;
  border-right: none;
}
.form-control:focus + .input-group-text {
  border-color: #86b7fe;
}
</style>