<?php
declare(strict_types=1);
use App\Auth\Auth;
use function App\Config\url;

function n($v): int { return (int)($v ?? 0); }
function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$openTotal   = n($summary['open_total'] ?? 0);
$stNuevo     = n($summary['st_nuevo'] ?? 0);
$stAsignado  = n($summary['st_asignado'] ?? 0);
$stEnProceso = n($summary['st_enproceso'] ?? 0);
$stRespondido= n($summary['st_respondido'] ?? 0);

// SEMÁFORO - BASADO EN DÍAS DESDE CREACIÓN (0-5 días)
$slaVerde    = n($summary['sla_verde'] ?? 0);     // 0-1 días
$slaAmarillo = n($summary['sla_amarillo'] ?? 0);  // 2-3 días
$slaRojo     = n($summary['sla_rojo'] ?? 0);      // 4+ días

$isSupervisor = Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN');
$isAdmin = Auth::hasRole('ADMIN');
$csrfToken = \App\Auth\Csrf::token();

// Calcular porcentajes para progress bars
$totalSLA = $slaVerde + $slaAmarillo + $slaRojo;
$verdePct = $totalSLA > 0 ? ($slaVerde / $totalSLA) * 100 : 0;
$amarilloPct = $totalSLA > 0 ? ($slaAmarillo / $totalSLA) * 100 : 0;
$rojoPct = $totalSLA > 0 ? ($slaRojo / $totalSLA) * 100 : 0;
?>

<div class="dashboard-container">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1 fw-bold text-dark">
                <i class="bi bi-traffic-light me-2"></i>Tablero de Control - Sistema de Semáforo
            </h1>
            <p class="text-muted mb-0">
                Seguimiento basado en días desde creación del caso • 
                <span class="text-success fw-bold">VERDE (0-1 días)</span> • 
                <span class="text-warning fw-bold">AMARILLO (2-3 días)</span> • 
                <span class="text-danger fw-bold">ROJO (4+ días)</span>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= esc(url('/cases')) ?>" class="btn btn-light">
                <i class="bi bi-inbox me-2"></i>Bandeja
            </a>
            <?php if($isSupervisor): ?>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#exportModal">
                <i class="bi bi-download me-2"></i>Exportar
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- SEMÁFORO PRINCIPAL CON EXPLICACIÓN -->
    <div class="row g-3 mb-4">
        <!-- VERDE -->
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
                            <small class="text-muted">0-1 día desde creación</small>
                        </div>
                    </div>
                    <div class="alert alert-success alert-sm">
                        <i class="bi bi-info-circle me-1"></i>
                        <small>Casos recientes. Respuesta dentro del plazo normal.</small>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-success" style="width: <?= $verdePct ?>%"></div>
                    </div>
                    <div class="mt-2 text-end">
                        <a href="<?= esc(url('/dashboard/semaforo/verde')) ?>" class="btn btn-sm btn-outline-success">
                            Ver detalle
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- AMARILLO -->
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
                            <small class="text-muted">2-3 días desde creación</small>
                        </div>
                    </div>
                    <div class="alert alert-warning alert-sm">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <small>Atención requerida. Casos próximos a límite.</small>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-warning" style="width: <?= $amarilloPct ?>%"></div>
                    </div>
                    <div class="mt-2 text-end">
                        <a href="<?= esc(url('/dashboard/semaforo/amarillo')) ?>" class="btn btn-sm btn-outline-warning">
                            Ver detalle
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ROJO -->
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
                            <small class="text-muted">4+ días desde creación</small>
                        </div>
                    </div>
                    <div class="alert alert-danger alert-sm">
                        <i class="bi bi-exclamation-octagon me-1"></i>
                        <small>¡ATENCIÓN INMEDIATA! Casos fuera de plazo.</small>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-danger" style="width: <?= $rojoPct ?>%"></div>
                    </div>
                    <div class="mt-2 text-end">
                        <a href="<?= esc(url('/dashboard/semaforo/rojo')) ?>" class="btn btn-sm btn-outline-danger">
                            Ver detalle
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- EXPLICACIÓN DEL SEMÁFORO -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="mb-0">
                        <i class="bi bi-info-circle me-2"></i>¿Cómo funciona el sistema de semáforo?
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="text-center p-3 border rounded">
                                <div class="semaforo-indicator bg-success mb-2"></div>
                                <h5 class="text-success">VERDE</h5>
                                <p class="mb-1"><strong>0-1 días</strong> desde creación</p>
                                <small class="text-muted">Casos dentro del plazo normal</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center p-3 border rounded">
                                <div class="semaforo-indicator bg-warning mb-2"></div>
                                <h5 class="text-warning">AMARILLO</h5>
                                <p class="mb-1"><strong>2-3 días</strong> desde creación</p>
                                <small class="text-muted">Requiere atención prioritaria</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center p-3 border rounded">
                                <div class="semaforo-indicator bg-danger mb-2"></div>
                                <h5 class="text-danger">ROJO</h5>
                                <p class="mb-1"><strong>4+ días</strong> desde creación</p>
                                <small class="text-muted">Fuera de plazo - Atención inmediata</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CASOS CRÍTICOS (ROJO) -->
    <?php if(!empty($criticalCases)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-exclamation-octagon me-2"></i>Casos CRÍTICOS (ROJO) - ¡ATENCIÓN INMEDIATA!
                        <span class="badge bg-light text-danger ms-2"><?= count($criticalCases) ?></span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th># Caso</th>
                                    <th>Asunto</th>
                                    <th>Solicitante</th>
                                    <th>Estado</th>
                                    <th>Días desde creación</th>
                                    <th>Asignado a</th>
                                    <th>Fecha creación</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($criticalCases as $case): ?>
                                <?php $dias = (int)($case['dias_desde_creacion'] ?? 0); ?>
                                <tr class="table-danger">
                                    <td>
                                        <strong><?= esc($case['case_number'] ?? '') ?></strong>
                                    </td>
                                    <td>
                                        <?= esc($case['subject'] ?? '') ?>
                                    </td>
                                    <td>
                                        <div><?= esc($case['requester_name'] ?? '') ?></div>
                                        <small class="text-muted"><?= esc($case['requester_email'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-danger"><?= esc($case['status_name'] ?? '') ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-dark">
                                            <?= $dias ?> días
                                        </span>
                                    </td>
                                    <td>
                                        <?= esc($case['assigned_to'] ?? 'Sin asignar') ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <?= date('d/m/Y', strtotime($case['created_at'] ?? '')) ?>
                                    </td>
                                    <td>
                                        <a href="<?= esc(url('/cases/' . ($case['id'] ?? ''))) ?>" 
                                           class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-eye me-1"></i>Ver
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- CASOS POR VENCER (AMARILLO) -->
    <?php if(!empty($warningCases)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-warning">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>Casos por Vencer (AMARILLO)
                        <span class="badge bg-light text-warning ms-2"><?= count($warningCases) ?></span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th># Caso</th>
                                    <th>Asunto</th>
                                    <th>Solicitante</th>
                                    <th>Estado</th>
                                    <th>Días desde creación</th>
                                    <th>Asignado a</th>
                                    <th>Fecha creación</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($warningCases as $case): ?>
                                <?php $dias = (int)($case['dias_desde_creacion'] ?? 0); ?>
                                <tr class="table-warning">
                                    <td>
                                        <strong><?= esc($case['case_number'] ?? '') ?></strong>
                                    </td>
                                    <td>
                                        <?= esc($case['subject'] ?? '') ?>
                                    </td>
                                    <td>
                                        <div><?= esc($case['requester_name'] ?? '') ?></div>
                                        <small class="text-muted"><?= esc($case['requester_email'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-warning text-dark"><?= esc($case['status_name'] ?? '') ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-warning text-dark">
                                            <?= $dias ?> días
                                        </span>
                                    </td>
                                    <td>
                                        <?= esc($case['assigned_to'] ?? 'Sin asignar') ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <?= date('d/m/Y', strtotime($case['created_at'] ?? '')) ?>
                                    </td>
                                    <td>
                                        <a href="<?= esc(url('/cases/' . ($case['id'] ?? ''))) ?>" 
                                           class="btn btn-sm btn-outline-warning">
                                            <i class="bi bi-eye me-1"></i>Ver
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- RESUMEN GENERAL -->
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Distribución General</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <div class="text-center p-3 border rounded">
                                <div class="text-muted small mb-1">TOTAL ABIERTOS</div>
                                <div class="h3 mb-0"><?= $openTotal ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-3 border rounded">
                                <div class="text-muted small mb-1">RESPONDIDOS</div>
                                <div class="h3 mb-0 text-primary"><?= $stRespondido ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-3 border rounded">
                                <div class="text-muted small mb-1">TIEMPO PROMEDIO</div>
                                <div class="h4 mb-0">
                                    <?= n($summary['avg_response_hours'] ?? 0) ?>h
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-3 border rounded">
                                <div class="text-muted small mb-1">TASA RESPUESTA</div>
                                <div class="h4 mb-0 <?= ($stRespondido/$openTotal*100) >= 80 ? 'text-success' : (($stRespondido/$openTotal*100) >= 50 ? 'text-warning' : 'text-danger') ?>">
                                    <?= $openTotal > 0 ? round(($stRespondido/$openTotal)*100, 1) : 0 ?>%
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Gráfico de distribución -->
                    <canvas id="distributionChart" height="100"></canvas>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>Acciones Rápidas</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="<?= esc(url('/cases?status=NUEVO')) ?>" class="btn btn-info btn-lg">
                            <i class="bi bi-plus-circle me-2"></i>Nuevos Sin Asignar (<?= $stNuevo ?>)
                        </a>
                        <a href="<?= esc(url('/cases?status=ASIGNADO')) ?>" class="btn btn-primary btn-lg">
                            <i class="bi bi-person-check me-2"></i>Asignados (<?= $stAsignado ?>)
                        </a>
                        <a href="<?= esc(url('/cases?status=EN_PROCESO')) ?>" class="btn btn-secondary btn-lg">
                            <i class="bi bi-gear me-2"></i>En Proceso (<?= $stEnProceso ?>)
                        </a>
                        <div class="d-grid gap-1 mt-2">
                            <a href="<?= esc(url('/dashboard/semaforo/rojo')) ?>" class="btn btn-danger">
                                <i class="bi bi-exclamation-octagon me-2"></i>Ver Casos ROJO (<?= $slaRojo ?>)
                            </a>
                            <a href="<?= esc(url('/dashboard/semaforo/amarillo')) ?>" class="btn btn-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>Ver Casos AMARILLO (<?= $slaAmarillo ?>)
                            </a>
                            <a href="<?= esc(url('/dashboard/semaforo/verde')) ?>" class="btn btn-success">
                                <i class="bi bi-check-circle me-2"></i>Ver Casos VERDE (<?= $slaVerde ?>)
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript para gráficos -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Gráfico de distribución de estados
    const ctx = document.getElementById('distributionChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Nuevos', 'Asignados', 'En Proceso'],
            datasets: [{
                label: 'Casos Abiertos',
                data: [<?= $stNuevo ?>, <?= $stAsignado ?>, <?= $stEnProceso ?>],
                backgroundColor: [
                    'rgba(59, 130, 246, 0.7)',
                    'rgba(16, 185, 129, 0.7)',
                    'rgba(245, 158, 11, 0.7)'
                ],
                borderColor: [
                    'rgb(59, 130, 246)',
                    'rgb(16, 185, 129)',
                    'rgb(245, 158, 11)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return `${context.label}: ${context.raw} casos`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });

    // Auto-refresh cada 30 segundos
    let refreshCount = 30;
    const refreshCounter = document.createElement('div');
    refreshCounter.className = 'position-fixed bottom-0 end-0 m-3 p-2 bg-dark text-white rounded shadow';
    refreshCounter.style.zIndex = '1000';
    refreshCounter.innerHTML = `
        <small><i class="bi bi-arrow-clockwise me-1"></i>
        Actualizando en <span id="refreshCount" class="fw-bold">${refreshCount}</span>s</small>
    `;
    document.body.appendChild(refreshCounter);

    const countdown = setInterval(() => {
        refreshCount--;
        document.getElementById('refreshCount').textContent = refreshCount;
        
        if (refreshCount <= 0) {
            clearInterval(countdown);
            window.location.reload();
        }
    }, 1000);
});
</script>

<!-- Estilos CSS adicionales -->
<style>
.dashboard-container {
    padding: 1rem;
}

.card {
    border-radius: 10px;
    border: 1px solid #e9ecef;
    transition: transform 0.2s;
}

.card:hover {
    transform: translateY(-2px);
}

.semaforo-indicator {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
}

.alert-sm {
    padding: 0.5rem;
    font-size: 0.875rem;
    margin-bottom: 1rem;
}

.table-danger {
    background-color: rgba(239, 68, 68, 0.05);
}

.table-warning {
    background-color: rgba(245, 158, 11, 0.05);
}

.badge {
    font-weight: 500;
    padding: 0.35em 0.65em;
}

/* Responsive */
@media (max-width: 768px) {
    .card .d-flex {
        flex-direction: column;
        text-align: center;
    }
    
    .semaforo-indicator {
        width: 40px;
        height: 40px;
        font-size: 18px;
    }
}
</style>