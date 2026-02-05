<?php
declare(strict_types=1);
use App\Auth\Auth;
use function App\Config\url;

function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>

<div class="reports-container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1 fw-bold">
                <i class="bi bi-file-earmark-bar-graph me-2"></i>Generador de Reportes
            </h1>
            <p class="text-muted mb-0">
                Genera reportes personalizados por fecha, estado, agente o semáforo
            </p>
        </div>
        <a href="<?= esc(url('/dashboard')) ?>" class="btn btn-light">
            <i class="bi bi-arrow-left me-2"></i>Volver al Tablero
        </a>
    </div>

    <!-- Reportes Recientes -->
    <?php if(!empty($recentReports)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Reportes Recientes</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Tipo</th>
                                    <th>Fecha Generación</th>
                                    <th>Generado por</th>
                                    <th>Descargas</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($recentReports as $report): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-info"><?= esc($report['report_type']) ?></span>
                                    </td>
                                    <td>
                                        <?= date('d/m/Y H:i', strtotime($report['created_at'])) ?>
                                    </td>
                                    <td>
                                        <?= esc($report['generated_by_name'] ?? 'Sistema') ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?= (int)$report['download_count'] ?></span>
                                    </td>
                                    <td>
                                        <?php if(!empty($report['file_path']) && file_exists($report['file_path'])): ?>
                                        <a href="<?= esc(url('/reports/download/' . $report['id'])) ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-download me-1"></i>Descargar
                                        </a>
                                        <?php else: ?>
                                        <span class="text-muted small">No disponible</span>
                                        <?php endif; ?>
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

    <!-- Formulario de Reporte -->
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
                            <!-- Rango de Fechas -->
                            <div class="col-md-6">
                                <label class="form-label">Fecha Desde</label>
                                <input type="date" 
                                       name="start_date" 
                                       class="form-control"
                                       value="<?= date('Y-m-d', strtotime('-7 days')) ?>">
                                <small class="text-muted">Dejar vacío para no filtrar por fecha</small>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Fecha Hasta</label>
                                <input type="date" 
                                       name="end_date" 
                                       class="form-control"
                                       value="<?= date('Y-m-d') ?>">
                            </div>
                            
                            <!-- Estado -->
                            <div class="col-md-6">
                                <label class="form-label">Estado del Caso</label>
                                <select name="status" class="form-select">
                                    <option value="">Todos los estados</option>
                                    <option value="NUEVO">NUEVO</option>
                                    <option value="ASIGNADO">ASIGNADO</option>
                                    <option value="EN_PROCESO">EN PROCESO</option>
                                    <option value="RESPONDIDO">RESPONDIDO</option>
                                    <option value="CERRADO">CERRADO</option>
                                </select>
                            </div>
                            
                            <!-- Agente -->
                            <div class="col-md-6">
                                <label class="form-label">Agente</label>
                                <select name="agent_id" class="form-select">
                                    <option value="">Todos los agentes</option>
                                    <?php foreach($agents as $agent): ?>
                                    <option value="<?= $agent['id'] ?>">
                                        <?= esc($agent['full_name'] . ' (' . $agent['email'] . ')') ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Semáforo -->
                            <div class="col-md-6">
                                <label class="form-label">Semáforo</label>
                                <select name="semaforo" class="form-select">
                                    <option value="">Todos</option>
                                    <option value="VERDE">VERDE (0-1 días)</option>
                                    <option value="AMARILLO">AMARILLO (2-3 días)</option>
                                    <option value="ROJO">ROJO (4+ días)</option>
                                    <option value="RESPONDIDO">RESPONDIDO</option>
                                </select>
                            </div>
                            
                            <!-- Formato -->
                            <div class="col-md-6">
                                <label class="form-label">Formato de Exportación</label>
                                <select name="format" class="form-select" required>
                                    <option value="html" selected>Vista HTML</option>
                                    <option value="csv">CSV (Excel)</option>
                                    <option value="excel">Excel Formateado</option>
                                </select>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-light" onclick="resetForm()">
                                <i class="bi bi-arrow-clockwise me-1"></i>Limpiar Filtros
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-gear me-1"></i>Generar Reporte
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
                        <h6 class="alert-heading"><i class="bi bi-lightbulb me-2"></i>Tipos de Reportes</h6>
                        <ul class="mb-0 ps-3">
                            <li><strong>Vista HTML:</strong> Previsualización en el navegador</li>
                            <li><strong>CSV:</strong> Archivo plano para Excel/Google Sheets</li>
                            <li><strong>Excel Formateado:</strong> Tabla con colores y formato básico</li>
                        </ul>
                    </div>
                    
                    <div class="alert alert-warning">
                        <h6 class="alert-heading"><i class="bi bi-exclamation-triangle me-2"></i>Límites</h6>
                        <ul class="mb-0 ps-3">
                            <li>Máximo 1000 registros por reporte</li>
                            <li>Archivos temporales se eliminan en 5 minutos</li>
                            <li>Solo supervisores y administradores pueden generar reportes</li>
                        </ul>
                    </div>
                    
                    <div class="alert alert-success">
                        <h6 class="alert-heading"><i class="bi bi-shield-check me-2"></i>Seguridad</h6>
                        <ul class="mb-0 ps-3">
                            <li>Todos los reportes requieren autenticación</li>
                            <li>Los reportes generados se registran en el sistema</li>
                            <li>Se controlan las descargas por usuario</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
function resetForm() {
    document.getElementById('reportForm').reset();
    document.querySelector('input[name="start_date"]').value = '<?= date('Y-m-d', strtotime('-7 days')) ?>';
    document.querySelector('input[name="end_date"]').value = '<?= date('Y-m-d') ?>';
}

// Validación de fechas
document.getElementById('reportForm').addEventListener('submit', function(e) {
    const start = document.querySelector('input[name="start_date"]').value;
    const end = document.querySelector('input[name="end_date"]').value;
    
    if (start && end && new Date(start) > new Date(end)) {
        e.preventDefault();
        alert('La fecha "Desde" no puede ser mayor que la fecha "Hasta"');
        return false;
    }
    
    // Mostrar loader
    const btn = this.querySelector('button[type="submit"]');
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Generando...';
    btn.disabled = true;
});
</script>

<!-- Estilos -->
<style>
.reports-container {
    padding: 1rem;
}

.card {
    border-radius: 10px;
    border: 1px solid #e9ecef;
}

.alert ul {
    margin-bottom: 0;
}

.form-label {
    font-weight: 500;
    margin-bottom: 0.5rem;
}

.table-sm td, .table-sm th {
    padding: 0.5rem;
}

@media (max-width: 768px) {
    .reports-container {
        padding: 0.5rem;
    }
    
    .card-body {
        padding: 1rem;
    }
}
</style>