<?php
declare(strict_types=1);
use App\Auth\Auth;
use function App\Config\url;

function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function n($v): int { return (int)($v ?? 0); }

$total = count($data);
?>

<div class="report-results">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1 fw-bold">
                <i class="bi bi-file-text me-2"></i>Resultados del Reporte
            </h1>
            <p class="text-muted mb-0">
                <?= $total ?> casos encontrados
                <?php if($params['start_date'] || $params['end_date']): ?>
                • Período: 
                <?= $params['start_date'] ? 'Desde ' . esc($params['start_date']) : '' ?>
                <?= $params['end_date'] ? ' hasta ' . esc($params['end_date']) : '' ?>
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

    <!-- Formulario oculto para reexportar -->
    <form id="exportForm" method="post" action="<?= esc(url('/reports/generate')) ?>" style="display: none;">
        <input type="hidden" name="_csrf" value="<?= esc($csrfToken) ?>">
        <input type="hidden" name="start_date" value="<?= esc($params['start_date'] ?? '') ?>">
        <input type="hidden" name="end_date" value="<?= esc($params['end_date'] ?? '') ?>">
        <input type="hidden" name="status" value="<?= esc($params['status'] ?? '') ?>">
        <input type="hidden" name="agent_id" value="<?= esc($params['agent_id'] ?? '') ?>">
        <input type="hidden" name="semaforo" value="<?= esc($params['semaforo'] ?? '') ?>">
        <input type="hidden" name="format" value="csv">
    </form>

    <!-- Resumen -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <div class="h3 mb-1"><?= $summary['total_cases'] ?></div>
                            <div class="text-muted small">Total Casos</div>
                        </div>
                        <div class="col-md-3">
                            <div class="h3 mb-1 text-success"><?= $summary['responded'] ?></div>
                            <div class="text-muted small">Respondidos</div>
                        </div>
                        <div class="col-md-3">
                            <div class="h3 mb-1 text-warning"><?= $summary['pending'] ?></div>
                            <div class="text-muted small">Pendientes</div>
                        </div>
                        <div class="col-md-3">
                            <div class="h3 mb-1"><?= $summary['avg_response_hours'] ?>h</div>
                            <div class="text-muted small">Tiempo Promedio</div>
                        </div>
                    </div>
                    
                    <!-- Distribución por Estado -->
                    <?php if(!empty($summary['by_status'])): ?>
                    <hr class="my-3">
                    <h6 class="mb-2">Distribución por Estado</h6>
                    <div class="row g-2">
                        <?php foreach($summary['by_status'] as $code => $status): ?>
                        <div class="col-auto">
                            <span class="badge bg-secondary">
                                <?= esc($status['name']) ?>: <?= $status['count'] ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Distribución por Semáforo -->
                    <?php if(!empty($summary['by_semaforo'])): ?>
                    <hr class="my-3">
                    <h6 class="mb-2">Distribución por Semáforo</h6>
                    <div class="row g-2">
                        <?php foreach($summary['by_semaforo'] as $semaforo => $info): ?>
                        <div class="col-auto">
                            <span class="badge bg-<?= $info['color'] ?>">
                                <?= $semaforo ?>: <?= $info['count'] ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla de Resultados -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0">Detalle de Casos</h5>
                </div>
                <div class="card-body p-0">
                    <?php if(empty($data)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox fs-1 text-muted mb-3"></i>
                        <h5 class="text-muted">No se encontraron casos</h5>
                        <p class="text-muted">Intenta con otros filtros</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th># Caso</th>
                                    <th>Asunto</th>
                                    <th>Solicitante</th>
                                    <th>Estado</th>
                                    <th>Asignado a</th>
                                    <th>Fecha Creación</th>
                                    <th>Semáforo</th>
                                    <th>Días</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($data as $row): ?>
                                <?php 
                                $dias = n($row['dias_desde_creacion']);
                                $semaforo = $row['semaforo'] ?? '';
                                $semaforoColor = match($semaforo) {
                                    'VERDE' => 'success',
                                    'AMARILLO' => 'warning',
                                    'ROJO' => 'danger',
                                    'RESPONDIDO' => 'primary',
                                    default => 'secondary'
                                };
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= esc($row['case_number'] ?? '') ?></strong>
                                    </td>
                                    <td>
                                        <?= esc($row['subject'] ?? '') ?>
                                    </td>
                                    <td>
                                        <div><?= esc($row['requester_name'] ?? '') ?></div>
                                        <small class="text-muted"><?= esc($row['requester_email'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?= esc($row['status_name'] ?? '') ?></span>
                                    </td>
                                    <td>
                                        <?= esc($row['assigned_to'] ?? 'Sin asignar') ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <?= date('d/m/Y', strtotime($row['created_at'] ?? '')) ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $semaforoColor ?>">
                                            <?= $semaforo ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark">
                                            <?= $dias ?> días
                                        </span>
                                    </td>
                                    <td>
                                        <a href="<?= esc(url('/cases/' . ($row['id'] ?? ''))) ?>" 
                                           class="btn btn-sm btn-outline-primary">
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
                    <small>
                        Generado el <?= date('d/m/Y H:i:s') ?> por 
                        <?= htmlspecialchars(Auth::user()['full_name'] ?? Auth::user()['username'] ?? 'Sistema') ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Estilos -->
<style>
.report-results {
    padding: 1rem;
}

.table th {
    font-weight: 600;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.table td {
    vertical-align: middle;
}

.card-footer {
    background-color: #f8f9fa;
    border-top: 1px solid #e9ecef;
}

@media print {
    .btn, .card-footer, #exportForm {
        display: none !important;
    }
    
    .card {
        border: none !important;
        box-shadow: none !important;
    }
}
</style>