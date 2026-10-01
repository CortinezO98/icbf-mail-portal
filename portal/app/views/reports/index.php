<?php
declare(strict_types=1);

use App\Auth\Auth;
use function App\Config\url;

function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$csrfToken = $csrfToken ?? \App\Auth\Csrf::token();
$isSupervisor = Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN');

// compat: pueden venir como recentReports (viejo) o exports (nuevo)
$recentReports = $recentReports ?? $exports ?? [];
$agents = $agents ?? [];
?>

<div class="reports-container">
  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
      <h1 class="h2 mb-1 fw-bold">
        <i class="bi bi-file-earmark-bar-graph me-2"></i>Reportería y Exportaciones
      </h1>
      <p class="text-muted mb-0">Genera reportes por filtros y descarga exportaciones (con auditoría).</p>
    </div>
    <a href="<?= esc(url('/dashboard')) ?>" class="btn btn-light">
      <i class="bi bi-arrow-left me-2"></i>Volver al Tablero
    </a>
  </div>

  <?php if (!$isSupervisor): ?>
    <div class="alert alert-warning">
      <i class="bi bi-shield-exclamation me-2"></i>
      Solo supervisores y administradores pueden generar reportes.
    </div>
  <?php endif; ?>

  <!-- Exportaciones recientes -->
  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Exportaciones recientes</h5>
      <small class="text-muted">Estado: PENDING / RUNNING / READY / FAILED</small>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>ID</th>
              <th>Tipo</th>
              <th>Estado</th>
              <th>Generación</th>
              <th>Filas</th>
              <th>Descargas</th>
              <th class="text-end">Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recentReports)): ?>
              <tr><td colspan="7" class="text-center text-muted py-3">Sin exportaciones recientes</td></tr>
            <?php else: foreach ($recentReports as $report): ?>
              <?php
                $status = strtoupper((string)($report['status'] ?? 'READY'));
                $badge = match ($status) {
                  'READY' => 'success',
                  'RUNNING' => 'primary',
                  'PENDING' => 'warning',
                  'FAILED' => 'danger',
                  default => 'secondary'
                };
                $downloadCount = (int)($report['download_count'] ?? 0);
                $rowCount = $report['row_count'] ?? null;
              ?>
              <tr>
                <td class="fw-semibold"><?= (int)($report['id'] ?? 0) ?></td>
                <td><span class="badge bg-info"><?= esc((string)($report['report_type'] ?? 'SLA')) ?></span></td>
                <td><span class="badge bg-<?= $badge ?>"><?= esc($status) ?></span></td>
                <td><?= isset($report['created_at']) ? esc(date('d/m/Y H:i', strtotime((string)$report['created_at']))) : '—' ?></td>
                <td><?= $rowCount !== null ? (int)$rowCount : '—' ?></td>
                <td><span class="badge bg-secondary"><?= $downloadCount ?></span></td>
                <td class="text-end">
                  <?php if ($status === 'READY'): ?>
                    <a href="<?= esc(url('/reports/download?id=' . (int)$report['id'])) ?>" class="btn btn-sm btn-outline-primary">
                      <i class="bi bi-download me-1"></i>Descargar
                    </a>
                  <?php elseif ($status === 'FAILED'): ?>
                    <span class="text-danger small"><?= esc((string)($report['error_message'] ?? 'Falló')) ?></span>
                  <?php else: ?>
                    <span class="text-muted small">En proceso…</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="small text-muted mt-2">
        Tip: si un reporte tarda, aparecerá como <strong>PENDING/RUNNING</strong>. Cuando quede <strong>READY</strong>, podrás descargarlo.
      </div>
    </div>
  </div>

  <!-- Formulario -->
  <div class="row">
    <div class="col-lg-8">
      <div class="card shadow-sm">
        <div class="card-header">
          <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Filtros del Reporte</h5>
        </div>
        <div class="card-body">
          <form method="post" action="<?= esc(url('/reports/generate')) ?>" id="reportForm">
            <input type="hidden" name="_csrf" value="<?= esc($csrfToken) ?>">

            <div class="row g-3">
              <div class="col-12">
                <label class="form-label">Tipo de Reporte</label>
                <select name="type" id="reportType" class="form-select">
                  <option value="sla" selected>Casos / SLA</option>
                  <option value="agent_presence_summary">Agentes — Resumen (horas por estado, por día)</option>
                  <option value="agent_presence_history">Agentes — Histórico detallado de estados</option>
                  <option value="agent_presence_live">Agentes — Estado en Tiempo Real (ahora mismo)</option>
                </select>
                <small class="text-muted" id="reportTypeHint">
                  Casos/SLA: exportable en Vista HTML, CSV o Excel.
                </small>
              </div>

              <div class="col-md-6" id="fieldStartDate">
                <label class="form-label">Fecha Desde</label>
                <input type="date" name="start_date" class="form-control" value="<?= esc(date('Y-m-d', strtotime('-7 days'))) ?>">
              </div>

              <div class="col-md-6" id="fieldEndDate">
                <label class="form-label">Fecha Hasta</label>
                <input type="date" name="end_date" class="form-control" value="<?= esc(date('Y-m-d')) ?>">
              </div>

              <div class="col-md-6" id="fieldStatus">
                <label class="form-label">Estado del Caso</label>
                <select name="status" class="form-select">
                  <option value="">Todos</option>
                  <option value="NUEVO">NUEVO</option>
                  <option value="ASIGNADO">ASIGNADO</option>
                  <option value="EN_PROCESO">EN PROCESO</option>
                  <option value="RESPONDIDO">RESPONDIDO</option>
                  <option value="CERRADO">CERRADO</option>
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label" id="fieldAgentLabel">Agente</label>
                <select name="agent_id" class="form-select">
                  <option value="">Todos</option>
                  <?php foreach ($agents as $agent): ?>
                    <option value="<?= (int)$agent['id'] ?>">
                      <?= esc(($agent['full_name'] ?? 'Agente') . ' • ' . ($agent['email'] ?? '')) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <small class="text-muted" id="fieldAgentHint" style="display:none;">
                  Opcional — deja "Todos" para incluir a todo el equipo.
                </small>
              </div>

              <div class="col-md-6" id="fieldSemaforo">
                <label class="form-label">Semáforo (SLA)</label>
                <select name="semaforo" class="form-select">
                  <option value="">Todos</option>
                  <option value="VERDE">VERDE</option>
                  <option value="AMARILLO">AMARILLO</option>
                  <option value="ROJO">ROJO</option>
                  <option value="RESPONDIDO">RESPONDIDO</option>
                </select>
                <small class="text-muted">El semáforo se calcula según la política SLA configurada.</small>
              </div>

              <div class="col-md-6">
                <label class="form-label">Formato</label>
                <select name="format" id="reportFormat" class="form-select" required>
                  <option value="html" selected>Vista HTML</option>
                  <option value="csv">CSV</option>
                  <option value="excel">Excel</option>
                </select>
              </div>
            </div>

            <hr class="my-4">

            <div class="d-flex justify-content-between">
              <button type="button" class="btn btn-light" onclick="resetForm()">
                <i class="bi bi-arrow-clockwise me-1"></i>Limpiar
              </button>
              <button type="submit" class="btn btn-primary" <?= $isSupervisor ? '' : 'disabled' ?>>
                <i class="bi bi-gear me-1"></i>Generar
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card shadow-sm">
        <div class="card-header">
          <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Información</h5>
        </div>
        <div class="card-body">

          <div class="alert alert-info">
            <h6 class="alert-heading"><i class="bi bi-lightbulb me-2"></i>Recomendación</h6>
            <div class="small">
              Para rangos grandes, usa exportación asíncrona (cuando esté habilitada) para evitar timeouts.
            </div>
          </div>

          <div class="alert alert-warning">
            <h6 class="alert-heading"><i class="bi bi-exclamation-triangle me-2"></i>Límites</h6>
            <ul class="mb-0 ps-3 small">
              <li>Máximo 1000 registros por vista (según configuración)</li>
              <li>Exportaciones quedan auditadas</li>
              <li>Acceso restringido por rol</li>
            </ul>
          </div>

          <div class="alert alert-success">
            <h6 class="alert-heading"><i class="bi bi-shield-check me-2"></i>Seguridad</h6>
            <ul class="mb-0 ps-3 small">
              <li>Descargas con autorización</li>
              <li>Protección contra path traversal</li>
              <li>Registro de descargas</li>
            </ul>
          </div>

        </div>
      </div>
    </div>
  </div>
</div>

<script>
function resetForm() {
  const f = document.getElementById('reportForm');
  f.reset();
  f.querySelector('input[name="start_date"]').value = '<?= esc(date('Y-m-d', strtotime('-7 days'))) ?>';
  f.querySelector('input[name="end_date"]').value = '<?= esc(date('Y-m-d')) ?>';
  applyReportTypeUI();
}

/**
 * Ajusta el formulario según el tipo de reporte elegido:
 * - Casos/SLA: comportamiento original, sin cambios.
 * - Agentes (resumen/histórico): oculta filtros de casos (Estado, Semáforo)
 *   que no aplican; "Vista HTML" no es una opción real para estos tipos
 *   (resultados.php tiene columnas fijas de casos) así que se retira del
 *   selector de formato - el backend igual lo forzaría a CSV como defensa,
 *   pero es más claro no ofrecerlo.
 * - Agentes (tiempo real): además de lo anterior, oculta el rango de
 *   fechas - el snapshot siempre es "ahora mismo", no admite rango.
 */
function applyReportTypeUI() {
  const type = document.getElementById('reportType').value;
  const isAgentReport = type.startsWith('agent_presence');
  const isLive = type === 'agent_presence_live';

  document.getElementById('fieldStatus').style.display = isAgentReport ? 'none' : '';
  document.getElementById('fieldSemaforo').style.display = isAgentReport ? 'none' : '';
  document.getElementById('fieldStartDate').style.display = isLive ? 'none' : '';
  document.getElementById('fieldEndDate').style.display = isLive ? 'none' : '';
  document.getElementById('fieldAgentLabel').textContent = isAgentReport ? 'Agente (opcional)' : 'Agente';
  document.getElementById('fieldAgentHint').style.display = isAgentReport ? '' : 'none';

  const formatSelect = document.getElementById('reportFormat');
  const htmlOption = formatSelect.querySelector('option[value="html"]');
  const hint = document.getElementById('reportTypeHint');

  if (isAgentReport) {
    htmlOption.disabled = true;
    if (formatSelect.value === 'html') formatSelect.value = 'excel';
    hint.textContent = isLive
      ? 'Estado en Tiempo Real: siempre exporta el momento actual (no usa rango de fechas). Descargable en CSV o Excel.'
      : 'Reportes de Agentes: descargables en CSV o Excel (no disponible en Vista HTML).';
  } else {
    htmlOption.disabled = false;
    hint.textContent = 'Casos/SLA: exportable en Vista HTML, CSV o Excel.';
  }
}

document.getElementById('reportType').addEventListener('change', applyReportTypeUI);
document.addEventListener('DOMContentLoaded', applyReportTypeUI);

document.getElementById('reportForm').addEventListener('submit', function(e) {
  const start = this.querySelector('input[name="start_date"]').value;
  const end = this.querySelector('input[name="end_date"]').value;
  if (start && end && new Date(start) > new Date(end)) {
    e.preventDefault();
    alert('La fecha "Desde" no puede ser mayor que la fecha "Hasta"');
    return false;
  }
  const btn = this.querySelector('button[type="submit"]');
  btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Generando...';
  btn.disabled = true;
});
</script>

<style>
.reports-container { padding: 1rem; }
.card { border-radius: 12px; border: 1px solid #e9ecef; }
.table-sm td, .table-sm th { padding: .55rem; }
</style>
