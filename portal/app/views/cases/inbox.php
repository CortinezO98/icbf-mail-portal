<?php
declare(strict_types=1);

use App\Auth\Auth;
use App\Auth\Csrf;
use function App\Config\url;

$roleIsSupervisor = Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN');
$status = $status ?? null;
$pagination = $pagination ?? [
    'page' => 1,
    'per_page' => 20,
    'total_rows' => 0,
    'total_pages' => 1,
    'has_prev' => false,
    'has_next' => false
];


function badge_status_class(string $code): string {
    return match (strtoupper($code)) {
        'NUEVO' => 'bg-primary-subtle text-primary border-primary',
        'ASIGNADO' => 'bg-warning-subtle text-warning-emphasis border-warning',
        'EN_PROCESO' => 'bg-info-subtle text-info-emphasis border-info',
        'RESPONDIDO' => 'bg-success-subtle text-success-emphasis border-success',
        'CERRADO' => 'bg-secondary-subtle text-secondary border-secondary',
        default => 'bg-light text-dark border',
    };
}

function badge_sla(string $sla): array {
    $sla = strtoupper(trim($sla));
    return match ($sla) {
        'OK' => ['bg-success-subtle text-success border-success', '<i class="bi bi-check-circle me-1"></i>OK'],
        'WARN' => ['bg-warning-subtle text-warning-emphasis border-warning', '<i class="bi bi-exclamation-triangle me-1"></i>Por vencer'],
        'BREACH' => ['bg-danger-subtle text-danger-emphasis border-danger', '<i class="bi bi-exclamation-octagon me-1"></i>Vencido'],
        default => ['bg-light text-dark border', $sla !== '' ? $sla : '—'],
    };
}

function buildPaginationUrl($page, $status = null): string {
    $params = [];
    if ($page > 1) $params['page'] = $page;
    if ($status) $params['status'] = $status;
    
    // Mantener el parámetro per_page si existe
    if (isset($_GET['per_page'])) {
        $params['per_page'] = $_GET['per_page'];
    }
    
    $query = http_build_query($params);
    return url('/cases' . ($query ? '?' . $query : ''));
}

$csrfToken = Csrf::token();
$autoAssignUrl = url('/cases/auto-assign');
$totalCases = $pagination['total_rows'] ?? 0;
$currentPage = $pagination['page'] ?? 1;
$totalPages = $pagination['total_pages'] ?? 1;
$perPage = $pagination['per_page'] ?? 20;
$hasPrev = $pagination['has_prev'] ?? false;
$hasNext = $pagination['has_next'] ?? false;
$startRow = ($currentPage - 1) * $perPage + 1;
$endRow = min($currentPage * $perPage, $totalCases);
$casesCount = count($cases ?? []);
?>

<div class="inbox-container">
    <!-- Header con estadísticas y acciones -->
    <div class="row align-items-center mb-4">
        <div class="col-md-6">
            <div class="d-flex align-items-center">
                <div class="me-3">
                    <div class="inbox-icon bg-brand rounded-3 p-3">
                        <i class="bi bi-inbox-fill text-white fs-4"></i>
                    </div>
                </div>
                <div>
                    <h1 class="h2 mb-1 fw-bold">Bandeja de Casos</h1>
                    <div class="text-muted d-flex align-items-center gap-2 flex-wrap">
                        <span>
                            <i class="bi bi-funnel me-1"></i>
                            <?= $status ? 'Filtro: ' . esc($status) : 'Todos los estados' ?>
                        </span>
                        <span class="text-muted">•</span>
                        <span>
                            <i class="bi bi-person-badge me-1"></i>
                            <?= $roleIsSupervisor ? 'Vista de supervisión' : 'Vista de agente' ?>
                        </span>
                        <?php if($totalCases > 0): ?>
                        <span class="text-muted">•</span>
                        <span class="badge bg-brand-subtle text-brand rounded-pill" 
                              data-bs-toggle="tooltip" 
                              title="Total de casos que coinciden con los filtros">
                            <?= number_format($totalCases, 0, ',', '.') ?> 
                            <?= $totalCases === 1 ? 'caso' : 'casos' ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="d-flex justify-content-end gap-2">
                <?php if ($roleIsSupervisor): ?>
                <div class="dropdown">
                    <button class="btn btn-brand dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-speedometer2 me-1"></i>Tableros
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= esc(url('/dashboard')) ?>">
                            <i class="bi bi-bar-chart me-2"></i>Dashboard Principal
                        </a></li>
                        <li><a class="dropdown-item" href="<?= esc(url('/reports')) ?>">
                            <i class="bi bi-file-earmark-bar-graph me-2"></i>Reportes
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= esc(url('/dashboard/semaforo/rojo')) ?>">
                            <i class="bi bi-exclamation-octagon text-danger me-2"></i>Casos Críticos
                        </a></li>
                    </ul>
                </div>
                <?php endif; ?>
                
                <div class="dropdown">
                    <button class="btn btn-outline-brand dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-filter me-1"></i>Filtrar por Estado
                    </button>
                    <ul class="dropdown-menu">
                        <li><h6 class="dropdown-header">Estados</h6></li>
                        <li><a class="dropdown-item <?= $status === null ? 'active' : '' ?>" 
                               href="<?= esc(buildPaginationUrl(1, null)) ?>">
                            <i class="bi bi-circle-fill text-secondary me-2"></i>Todos
                        </a></li>
                        <li><a class="dropdown-item <?= $status === 'NUEVO' ? 'active' : '' ?>" 
                               href="<?= esc(buildPaginationUrl(1, 'NUEVO')) ?>">
                            <i class="bi bi-circle-fill text-primary me-2"></i>Nuevos
                        </a></li>
                        <li><a class="dropdown-item <?= $status === 'ASIGNADO' ? 'active' : '' ?>" 
                               href="<?= esc(buildPaginationUrl(1, 'ASIGNADO')) ?>">
                            <i class="bi bi-circle-fill text-warning me-2"></i>Asignados
                        </a></li>
                        <li><a class="dropdown-item <?= $status === 'EN_PROCESO' ? 'active' : '' ?>" 
                               href="<?= esc(buildPaginationUrl(1, 'EN_PROCESO')) ?>">
                            <i class="bi bi-circle-fill text-info me-2"></i>En Proceso
                        </a></li>
                        <li><a class="dropdown-item <?= $status === 'RESPONDIDO' ? 'active' : '' ?>" 
                               href="<?= esc(buildPaginationUrl(1, 'RESPONDIDO')) ?>">
                            <i class="bi bi-circle-fill text-success me-2"></i>Respondidos
                        </a></li>
                        <li><a class="dropdown-item <?= $status === 'CERRADO' ? 'active' : '' ?>" 
                               href="<?= esc(buildPaginationUrl(1, 'CERRADO')) ?>">
                            <i class="bi bi-circle-fill text-secondary me-2"></i>Cerrados
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Tarjeta de Asignación Automática (Solo Supervisores) -->
    <?php if ($roleIsSupervisor): ?>
    <div class="card border-dashed mb-4">
        <div class="card-body p-3">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex align-items-center">
                        <div class="me-3">
                            <div class="bg-warning-subtle rounded-3 p-2">
                                <i class="bi bi-lightning-charge-fill text-warning fs-4"></i>
                            </div>
                        </div>
                        <div>
                            <h6 class="mb-1 fw-bold">Casos pendientes de asignación</h6>
                            <p class="text-muted mb-0 small">
                                <span class="badge bg-warning-subtle text-warning-emphasis rounded-pill me-2">
                                    <i class="bi bi-person-x me-1"></i>Sin asignar: <strong id="unassignedCount"><?= number_format((int)($unassignedCount ?? 0), 0, ',', '.') ?></strong>
                                </span>
                                Casos con estado <strong>NUEVO</strong> que requieren asignación a agentes
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    <button type="button"
                            class="btn btn-warning btn-lg px-4"
                            id="btnAutoAssign"
                            data-url="<?= esc($autoAssignUrl) ?>"
                            data-csrf="<?= esc($csrfToken) ?>">
                        <i class="bi bi-lightning-charge me-2"></i>Auto-asignar
                    </button>
                    <div class="form-text mt-1">
                        Distribuye automáticamente entre agentes disponibles
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================== -->
    <!-- SECCIÓN NUEVA: INFORMACIÓN DE PAGINACIÓN -->
    <!-- ============================== -->
    <?php if($totalCases > 0): ?>
    <div class="alert alert-light border mb-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted">
                        <i class="bi bi-list-ul me-1"></i>
                        Mostrando 
                        <strong><?= $startRow ?>-<?= $endRow ?></strong> 
                        de 
                        <strong><?= number_format($totalCases, 0, ',', '.') ?></strong> 
                        casos
                    </span>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle py-0" 
                                type="button" data-bs-toggle="dropdown">
                            <?= $perPage ?> por página
                        </button>
                        <ul class="dropdown-menu">
                            <?php 
                            $perPageOptions = [10, 20, 50, 100];
                            foreach ($perPageOptions as $option): 
                            ?>
                            <li><a class="dropdown-item <?= $perPage == $option ? 'active' : '' ?>" 
                                   href="?<?= http_build_query(array_merge($_GET, ['per_page' => $option, 'page' => 1])) ?>">
                                <?= $option ?> por página
                            </a></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-md-6 text-end">
                <span class="text-muted">
                    Página 
                    <strong><?= number_format($currentPage, 0, ',', '.') ?></strong> 
                    de 
                    <strong><?= number_format($totalPages, 0, ',', '.') ?></strong>
                </span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabla de Casos -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-transparent border-bottom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">Listado de Casos</h5>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="input-group input-group-sm" style="width: 250px;">
                        <span class="input-group-text bg-transparent border-end-0">
                            <i class="bi bi-search text-muted"></i>
                        </span>
                        <input type="text" class="form-control border-start-0" 
                               placeholder="Buscar casos..." id="searchInput">
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" type="button" id="refreshBtn">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
            </div>
        </div>
        
        <div class="card-body p-0">
            <?php if (empty($cases)): ?>
            <div class="text-center py-5">
                <div class="mb-4">
                    <i class="bi bi-inbox display-1 text-muted opacity-50"></i>
                </div>
                <h4 class="text-muted mb-2">No hay casos disponibles</h4>
                <p class="text-muted mb-4">
                    <?php if($status): ?>
                        No se encontraron casos con estado "<?= esc($status) ?>"
                    <?php else: ?>
                        La bandeja está vacía o no tienes casos asignados
                    <?php endif; ?>
                </p>
                <a href="<?= esc(buildPaginationUrl(1, null)) ?>" class="btn btn-brand">
                    <i class="bi bi-arrow-clockwise me-1"></i>Ver todos los casos
                </a>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="casesTable">
                    <thead>
                        <tr class="table-light">
                            <th class="ps-4" style="width: 180px;">
                                <div class="d-flex align-items-center">
                                    <span># Caso</span>
                                    <button class="btn btn-sm btn-link p-0 ms-1 text-muted" type="button" 
                                            data-bs-toggle="tooltip" title="Ordenar">
                                        <i class="bi bi-arrow-down-up"></i>
                                    </button>
                                </div>
                            </th>
                            <th>Asunto</th>
                            <th style="width: 200px;">Solicitante</th>
                            <th style="width: 140px;" class="text-center">Estado</th>
                            <th style="width: 120px;" class="text-center">SLA</th>
                            <th style="width: 150px;">Recibido</th>
                            <th style="width: 150px;" class="pe-4">Últ. Actividad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cases as $c): ?>
                        <?php
                            $caseId = (int)($c['id'] ?? 0);
                            $caseNumber = (string)($c['case_number'] ?? '');
                            $subject = (string)($c['subject'] ?? '');
                            $reqName = (string)($c['requester_name'] ?? '');
                            $reqEmail = (string)($c['requester_email'] ?? '');
                            $assignedUserId = $c['assigned_user_id'] ?? null;
                            
                            $statusCode = strtoupper((string)($c['status_code'] ?? ''));
                            $statusName = (string)($c['status_name'] ?? $statusCode);
                            $statusClass = badge_status_class($statusCode);
                            
                            [$slaBadge, $slaLabel] = badge_sla((string)($c['sla_bucket'] ?? $c['sla_state'] ?? ''));
                            $receivedAt = formatDate($c['received_at'] ?? '');
                            $lastActivity = formatDate($c['last_activity_at'] ?? '');
                            
                            // Colores según estado
                            $priorityClass = match($statusCode) {
                                'NUEVO' => 'border-start border-primary border-3',
                                'ASIGNADO' => 'border-start border-warning border-3',
                                'EN_PROCESO' => 'border-start border-info border-3',
                                'RESPONDIDO' => 'border-start border-success border-3',
                                default => 'border-start border-secondary border-3'
                            };
                        ?>
                        <tr class="case-row <?= $priorityClass ?>" 
                            data-id="<?= $caseId ?>"
                            data-number="<?= esc($caseNumber) ?>"
                            data-subject="<?= esc($subject) ?>"
                            data-requester="<?= esc($reqName) ?>"
                            data-status="<?= esc($statusCode) ?>">
                            <td class="ps-4">
                                <a href="<?= esc(url('/cases/' . $caseId)) ?>" 
                                   class="text-decoration-none d-block">
                                    <div class="fw-bold text-primary"><?= esc($caseNumber) ?></div>
                                    <?php if (!empty($assignedUserId) && $roleIsSupervisor): ?>
                                    <div class="text-muted small mt-1">
                                        <i class="bi bi-person-circle me-1"></i>
                                        <span class="fst-italic">Asignado</span>
                                    </div>
                                    <?php endif; ?>
                                </a>
                            </td>
                            
                            <td>
                                <div class="d-flex">
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold text-truncate" style="max-width: 300px;">
                                            <?= esc($subject) ?>
                                        </div>
                                        <?php if ($roleIsSupervisor && !empty($assignedUserId)): ?>
                                        <div class="text-muted small mt-1">
                                            <i class="bi bi-person-check me-1"></i>
                                            <span class="fst-italic">Asignado</span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ms-2">
                                        <span class="badge bg-light text-dark border small">
                                            <?= esc($caseId) ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="me-3">
                                        <div class="avatar-sm bg-brand-subtle text-brand rounded-circle d-flex align-items-center justify-content-center">
                                            <i class="bi bi-person fs-6"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="fw-semibold"><?= esc($reqName) ?></div>
                                        <div class="text-muted small text-truncate" style="max-width: 180px;">
                                            <?= esc($reqEmail) ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            
                            <td class="text-center">
                                <span class="badge rounded-pill <?= $statusClass ?> px-3 py-2">
                                    <?= esc($statusName) ?>
                                </span>
                            </td>
                            
                            <td class="text-center">
                                <span class="badge rounded-pill <?= $slaBadge ?> px-3 py-2">
                                    <?= $slaLabel ?>
                                </span>
                            </td>
                            
                            <td>
                                <div class="text-nowrap">
                                    <div class="fw-medium"><?= $receivedAt ?></div>
                                    <?php if ($c['received_at']): ?>
                                    <div class="text-muted small">
                                        <?= date('H:i', strtotime($c['received_at'])) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <td class="pe-4">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="text-nowrap">
                                        <div class="fw-medium"><?= $lastActivity ?></div>
                                        <?php if ($c['last_activity_at']): ?>
                                        <div class="text-muted small">
                                            <?= date('H:i', strtotime($c['last_activity_at'])) ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <a href="<?= esc(url('/cases/' . $caseId)) ?>" 
                                       class="btn btn-sm btn-outline-brand ms-2">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- ============================== -->
        <!-- SECCIÓN NUEVA: PAGINACIÓN -->
        <!-- ============================== -->
        <?php if (!empty($cases) && $totalPages > 1): ?>
        <div class="card-footer bg-transparent border-top">
            <div class="d-flex justify-content-between align-items-center">
                <div class="text-muted small">
                    Página <strong><?= $currentPage ?></strong> de <strong><?= $totalPages ?></strong>
                    • 
                    <?= number_format($totalCases, 0, ',', '.') ?> 
                    <?= $totalCases === 1 ? 'caso' : 'casos' ?> en total
                </div>
                
                <nav aria-label="Paginación de casos">
                    <ul class="pagination pagination-sm mb-0">
                        <!-- Primera página -->
                        <li class="page-item <?= !$hasPrev ? 'disabled' : '' ?>">
                            <a class="page-link" 
                               href="<?= esc(buildPaginationUrl(1, $status)) ?>"
                               aria-label="Primera">
                                <i class="bi bi-chevron-double-left"></i>
                            </a>
                        </li>
                        
                        <!-- Página anterior -->
                        <li class="page-item <?= !$hasPrev ? 'disabled' : '' ?>">
                            <a class="page-link" 
                               href="<?= esc(buildPaginationUrl($currentPage - 1, $status)) ?>"
                               aria-label="Anterior">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        
                        <!-- Páginas numeradas -->
                        <?php
                        // Mostrar máximo 5 páginas alrededor de la actual
                        $startPage = max(1, $currentPage - 2);
                        $endPage = min($totalPages, $currentPage + 2);
                        
                        if ($startPage > 1) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                        
                        for ($i = $startPage; $i <= $endPage; $i++): 
                        ?>
                            <li class="page-item <?= $i == $currentPage ? 'active' : '' ?>">
                                <a class="page-link" 
                                   href="<?= esc(buildPaginationUrl($i, $status)) ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($endPage < $totalPages): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        
                        <!-- Página siguiente -->
                        <li class="page-item <?= !$hasNext ? 'disabled' : '' ?>">
                            <a class="page-link" 
                               href="<?= esc(buildPaginationUrl($currentPage + 1, $status)) ?>"
                               aria-label="Siguiente">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        
                        <!-- Última página -->
                        <li class="page-item <?= !$hasNext ? 'disabled' : '' ?>">
                            <a class="page-link" 
                               href="<?= esc(buildPaginationUrl($totalPages, $status)) ?>"
                               aria-label="Última">
                                <i class="bi bi-chevron-double-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
        <?php elseif (!empty($cases)): ?>
        <div class="card-footer bg-transparent border-top">
            <div class="d-flex justify-content-between align-items-center">
                <div class="text-muted small">
                    Mostrando <strong><?= $casesCount ?></strong> 
                    <?= $casesCount === 1 ? 'caso' : 'casos' ?>
                    <?php if($status): ?>
                        con estado "<?= esc($status) ?>"
                    <?php endif; ?>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-sm btn-outline-secondary" id="exportBtn">
                        <i class="bi bi-download me-1"></i>Exportar
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" id="printBtn">
                        <i class="bi bi-printer me-1"></i>Imprimir
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================== -->
<!-- JAVASCRIPT ACTUALIZADO CON PAGINACIÓN -->
<!-- ============================== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Función para construir URLs manteniendo parámetros
    function buildUrl(page) {
        const url = new URL(window.location.href);
        url.searchParams.set('page', page);
        return url.toString();
    }

    // Navegación a página específica
    const goToPageInput = document.getElementById('goToPage');
    const goToPageBtn = document.getElementById('goToPageBtn');
    
    if (goToPageInput && goToPageBtn) {
        goToPageBtn.addEventListener('click', function() {
            const page = parseInt(goToPageInput.value);
            const maxPage = parseInt(goToPageInput.max);
            
            if (page >= 1 && page <= maxPage) {
                window.location.href = buildUrl(page);
            } else {
                Swal.fire({
                    title: 'Página inválida',
                    text: `Por favor ingresa un número entre 1 y ${maxPage}`,
                    icon: 'warning'
                });
            }
        });
        
        goToPageInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                goToPageBtn.click();
            }
        });
    }

    // Auto-asignación
    const btnAutoAssign = document.getElementById('btnAutoAssign');
    if (btnAutoAssign) {
        btnAutoAssign.addEventListener('click', async function () {
            const url = btnAutoAssign.dataset.url;
            const csrf = btnAutoAssign.dataset.csrf;

            const { value: confirm } = await Swal.fire({
                title: '¿Auto-asignar casos pendientes?',
                html: `
                    <div class="text-start">
                        <p>Esta acción distribuirá los casos <strong>NUEVOS</strong> entre los agentes disponibles según:</p>
                        <ul class="text-start mb-0">
                            <li>Menor carga actual de casos</li>
                            <li>Fecha de última asignación</li>
                            <li>Disponibilidad del agente</li>
                        </ul>
                        <div class="alert alert-warning mt-3 mb-0 p-2">
                            <i class="bi bi-info-circle me-1"></i>
                            Solo se asignarán casos sin agente asignado previamente.
                        </div>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, auto-asignar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#f59e0b',
                showLoaderOnConfirm: true,
                preConfirm: async () => {
                    const form = new FormData();
                    form.append('_csrf', csrf);

                    try {
                        const resp = await fetch(url, {
                            method: 'POST',
                            body: form,
                            headers: { 'X-Requested-With': 'fetch' }
                        });
                        
                        if (!resp.ok) throw new Error('Error en la solicitud');
                        return await resp.json();
                    } catch (error) {
                        Swal.showValidationMessage('Error de conexión');
                        return null;
                    }
                }
            });

            if (!confirm) return;

            const data = confirm;
            
            if (data.ok) {
                let html = '';
                let icon = 'info';
                let title = 'Proceso completado';
                
                switch(data.code) {
                    case 'ASSIGNED':
                        icon = 'success';
                        title = '✅ Auto-asignación exitosa';
                        html = `
                            <div class="text-start">
                                <div class="alert alert-success p-2 mb-3">
                                    <i class="bi bi-check-circle me-1"></i>
                                    <strong>${data.assigned} caso(s)</strong> asignados correctamente
                                </div>
                                <div class="row">
                                    <div class="col-6">
                                        <div class="card border-success border">
                                            <div class="card-body text-center p-2">
                                                <div class="h4 mb-0 text-success">${data.assigned}</div>
                                                <small class="text-muted">Asignados</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="card border-warning border">
                                            <div class="card-body text-center p-2">
                                                <div class="h4 mb-0 text-warning">${data.skipped}</div>
                                                <small class="text-muted">Omitidos</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        setTimeout(() => window.location.reload(), 2000);
                        break;
                        
                    case 'NO_PENDING':
                        icon = 'info';
                        title = 'Sin pendientes';
                        html = `<p>No hay casos NUEVOS para asignar en este momento.</p>`;
                        break;
                        
                    case 'NO_AGENTS':
                        icon = 'warning';
                        title = 'Sin agentes disponibles';
                        html = `
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                No hay agentes elegibles para asignación.
                                <div class="mt-2 small">
                                    Verifica que existan usuarios con rol <strong>AGENTE</strong> y estén habilitados.
                                </div>
                            </div>
                        `;
                        break;
                        
                    default:
                        html = `<p>${data.message || 'Operación completada'}</p>`;
                }
                
                await Swal.fire({
                    title: title,
                    html: html,
                    icon: icon,
                    confirmButtonText: 'Aceptar'
                });
                
            } else {
                await Swal.fire({
                    title: 'Error',
                    text: data.message || 'No se pudo completar la operación',
                    icon: 'error',
                    confirmButtonText: 'Aceptar'
                });
            }
            
            btnAutoAssign.disabled = false;
        });
    }

    // Búsqueda en tiempo real
    const searchInput = document.getElementById('searchInput');
    const casesTable = document.getElementById('casesTable');
    
    if (searchInput && casesTable) {
        let searchTimeout;
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const searchTerm = this.value.toLowerCase().trim();
                const rows = casesTable.querySelectorAll('tbody tr.case-row');
                let visibleCount = 0;
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    const match = text.includes(searchTerm);
                    row.style.display = match ? '' : 'none';
                    if (match) visibleCount++;
                });
                
                // Mostrar mensaje si no hay resultados
                const noResultsRow = casesTable.querySelector('tr.no-results');
                if (searchTerm && visibleCount === 0) {
                    if (!noResultsRow) {
                        const tr = document.createElement('tr');
                        tr.className = 'no-results';
                        tr.innerHTML = `
                            <td colspan="7" class="text-center py-4">
                                <i class="bi bi-search display-6 text-muted opacity-50 mb-3"></i>
                                <h5 class="text-muted">No se encontraron casos</h5>
                                <p class="text-muted small">No hay casos que coincidan con "${searchTerm}"</p>
                                <button class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('searchInput').value=''; document.getElementById('searchInput').dispatchEvent(new Event('input'));">
                                    Limpiar búsqueda
                                </button>
                            </td>
                        `;
                        casesTable.querySelector('tbody').appendChild(tr);
                    }
                } else if (noResultsRow) {
                    noResultsRow.remove();
                }
            }, 300);
        });
    }

    // Botón de refresh
    const refreshBtn = document.getElementById('refreshBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() {
            this.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
            setTimeout(() => {
                window.location.reload();
            }, 500);
        });
    }

    // Exportar e imprimir
    document.getElementById('exportBtn')?.addEventListener('click', function() {
        Swal.fire({
            title: 'Exportar lista',
            text: '¿Deseas exportar la lista actual de casos?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Exportar a CSV',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = '<?= esc(url('/reports/generate?' . http_build_query(array_merge($_GET, ['format' => 'csv']))) ) ?>';
            }
        });
    });

    document.getElementById('printBtn')?.addEventListener('click', function() {
        window.print();
    });

    // Tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Efecto hover en filas
    const caseRows = document.querySelectorAll('.case-row');
    caseRows.forEach(row => {
        row.addEventListener('click', function(e) {
            // Solo navegar si no se hizo clic en un enlace o botón
            if (!e.target.closest('a') && !e.target.closest('button')) {
                const caseId = this.dataset.id;
                if (caseId) {
                    window.location.href = `<?= url('/cases/') ?>${caseId}`;
                }
            }
        });
        
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'rgba(76, 175, 80, 0.03)';
            this.style.cursor = 'pointer';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
        });
    });
});
</script>

<!-- Estilos CSS -->
<style>
.inbox-container {
    padding: 1.5rem;
    background: linear-gradient(180deg, #f8f9fa 0%, #ffffff 100px);
}

.inbox-icon {
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.bg-brand { background-color: #4CAF50 !important; }
.bg-brand-subtle { background-color: rgba(76, 175, 80, 0.1) !important; }
.text-brand { color: #4CAF50 !important; }

.btn-brand {
    background-color: #4CAF50;
    border-color: #4CAF50;
    color: white;
}

.btn-brand:hover {
    background-color: #3f9c44;
    border-color: #3f9c44;
    color: white;
}

.btn-outline-brand {
    color: #4CAF50;
    border-color: #4CAF50;
}

.btn-outline-brand:hover {
    background-color: #4CAF50;
    border-color: #4CAF50;
    color: white;
}

.card.border-dashed {
    border: 2px dashed #dee2e6 !important;
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
}

.avatar-sm {
    width: 36px;
    height: 36px;
}

.case-row {
    transition: all 0.2s ease;
}

.badge {
    font-weight: 500;
    letter-spacing: 0.3px;
}

.border-start {
    border-left-width: 4px !important;
}

/* Paginación */
.page-item.active .page-link {
    background-color: #4CAF50;
    border-color: #4CAF50;
}

.page-link {
    color: #4CAF50;
}

.page-link:hover {
    color: #3f9c44;
}

/* Spin animation */
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.bi.spin {
    animation: spin 1s linear infinite;
}

/* Responsive */
@media (max-width: 768px) {
    .inbox-container {
        padding: 1rem;
    }
    
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .card-header .input-group {
        width: 200px !important;
    }
    
    .pagination {
        flex-wrap: wrap;
        justify-content: center;
        margin-top: 1rem;
    }
    
    .card-footer .d-flex {
        flex-direction: column;
        gap: 1rem;
        align-items: stretch !important;
    }
    
    .card-footer nav {
        order: -1;
        margin-bottom: 1rem;
    }
}

@media print {
    .btn, .dropdown, .card-footer, #searchInput, #refreshBtn, .pagination, .alert {
        display: none !important;
    }
    
    .case-row {
        break-inside: avoid;
    }
}
</style>