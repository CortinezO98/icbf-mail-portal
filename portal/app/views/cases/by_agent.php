<?php
declare(strict_types=1);

use function App\Config\url;

// Validación y normalización de datos
$agents = $agents ?? [];
$cases = $cases ?? [];
$selectedAgentId = (int)($selectedAgentId ?? 0);
$searchTerm = trim((string)($q ?? ''));
$summary = $summary ?? ['total_assigned' => 0];
$pagination = array_merge([
    'page' => 1,
    'per_page' => 20,
    'total_rows' => 0,
    'total_pages' => 1,
    'has_prev' => false,
    'has_next' => false,
    'offset' => 0,
], $pagination ?? []);

// Helpers de UI
function buildAgentFilterUrl(array $overrides = []): string
{
    $params = array_filter($_GET ?? [], fn($value) => $value !== '' && $value !== null);
    
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    
    $query = http_build_query($params);
    return url('/cases/by-agent' . ($query !== '' ? '?' . $query : ''));
}

function formatDateTime(?string $value): string
{
    if (empty($value)) {
        return '—';
    }
    
    try {
        $dt = new DateTime($value);
        return $dt->format('d/m/Y H:i');
    } catch (Throwable) {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

function getStatusBadgeClasses(?string $statusName): array
{
    $status = strtoupper(trim((string)$statusName));
    
    return match ($status) {
        'ASIGNADO' => [
            'class' => 'bg-warning-subtle text-warning-emphasis',
            'icon' => 'bi-person-check-fill',
        ],
        'EN_PROCESO' => [
            'class' => 'bg-primary-subtle text-primary-emphasis',
            'icon' => 'bi-gear-fill',
        ],
        'ESCALADO', 'ESCALATED' => [
            'class' => 'bg-info-subtle text-info-emphasis',
            'icon' => 'bi-arrow-up-circle-fill',
        ],
        'RESPONDIDO' => [
            'class' => 'bg-success-subtle text-success-emphasis',
            'icon' => 'bi-check-circle-fill',
        ],
        'CERRADO' => [
            'class' => 'bg-secondary-subtle text-secondary-emphasis',
            'icon' => 'bi-lock-fill',
        ],
        default => [
            'class' => 'bg-light text-dark',
            'icon' => 'bi-circle-fill',
        ],
    };
}

// Obtener nombre del agente seleccionado
$selectedAgentName = '';
foreach ($agents as $agent) {
    if ((int)($agent['id'] ?? 0) === $selectedAgentId) {
        $selectedAgentName = (string)($agent['full_name'] ?? $agent['username'] ?? 'Agente');
        break;
    }
}
?>

<div class="agent-cases-container">
    <!-- Hero Section - Optimizado para legibilidad y jerarquía visual -->
    <div class="agent-hero mb-4">
        <div class="hero-content">
            <span class="hero-badge">
                <i class="bi bi-people-fill me-1"></i>
                Supervisión operativa
            </span>
            <h1 class="hero-title">Casos asignados por agente</h1>
            <p class="hero-description">
                Consulta la carga operativa de los agentes, revisa el estado actual de sus casos
                y ejecuta reasignaciones masivas de forma controlada y segura.
            </p>
        </div>
        
        <?php if ($selectedAgentId > 0): ?>
            <div class="agent-info-card">
                <span class="agent-info-label">Agente consultado</span>
                <span class="agent-info-name"><?= htmlspecialchars($selectedAgentName, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Flash Messages -->
    <?php if (!empty($flash)): ?>
        <?php 
        $flashType = $flash['type'] ?? 'info';
        $alertClass = match($flashType) {
            'error' => 'danger',
            'success' => 'success',
            'warning' => 'warning',
            default => 'info',
        };
        ?>
        <div class="alert alert-<?= $alertClass ?> alert-dismissible fade show shadow-sm mb-4" role="alert">
            <i class="bi bi-<?= $alertClass === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> me-2"></i>
            <?= htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filtros de búsqueda -->
    <div class="card filter-card mb-4">
        <div class="card-body">
            <form method="GET" action="<?= htmlspecialchars(url('/cases/by-agent'), ENT_QUOTES, 'UTF-8') ?>" class="row g-3">
                <div class="col-lg-5">
                    <label class="form-label fw-semibold">
                        <i class="bi bi-person-badge me-1"></i>
                        Agente origen
                    </label>
                    <select name="agent_id" class="form-select form-select-lg" required>
                        <option value="">Selecciona un agente</option>
                        <?php foreach ($agents as $agent): ?>
                            <?php $agentId = (int)($agent['id'] ?? 0); ?>
                            <option value="<?= $agentId ?>" <?= $selectedAgentId === $agentId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($agent['full_name'] ?? $agent['username'] ?? 'Agente', ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-5">
                    <label class="form-label fw-semibold">
                        <i class="bi bi-search me-1"></i>
                        Buscar en casos
                    </label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white border-end-0">
                            <i class="bi bi-filter text-muted"></i>
                        </span>
                        <input
                            type="text"
                            name="q"
                            class="form-control border-start-0"
                            value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Número de caso, asunto, solicitante o correo"
                        >
                    </div>
                </div>

                <div class="col-lg-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        <i class="bi bi-search me-1"></i>
                        Consultar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selectedAgentId > 0): ?>
        <!-- Resumen de métricas -->
        <div class="row g-3 mb-4">
            <div class="col-lg-4">
                <div class="metric-card">
                    <div class="metric-icon bg-primary bg-opacity-10">
                        <i class="bi bi-briefcase-fill text-primary"></i>
                    </div>
                    <div class="metric-content">
                        <span class="metric-label">Total casos asignados</span>
                        <span class="metric-value"><?= (int)($summary['total_assigned'] ?? 0) ?></span>
                        <span class="metric-trend">Casos activos en gestión</span>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="metric-card">
                    <div class="metric-icon bg-info bg-opacity-10">
                        <i class="bi bi-person-vcard-fill text-info"></i>
                    </div>
                    <div class="metric-content">
                        <span class="metric-label">Agente actual</span>
                        <span class="metric-value"><?= htmlspecialchars($selectedAgentName, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="metric-trend">Carga operativa actual</span>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="metric-card">
                    <div class="metric-icon bg-warning bg-opacity-10">
                        <i class="bi bi-funnel-fill text-warning"></i>
                    </div>
                    <div class="metric-content">
                        <span class="metric-label">Filtro aplicado</span>
                        <span class="metric-value"><?= $searchTerm !== '' ? htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') : 'Sin filtro' ?></span>
                        <span class="metric-trend">Búsqueda específica en casos</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Formulario de reasignación masiva -->
        <form method="POST" action="<?= htmlspecialchars(url('/cases/by-agent/reassign'), ENT_QUOTES, 'UTF-8') ?>" id="bulkReassignForm">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="source_agent_id" value="<?= $selectedAgentId ?>">

            <div class="card cases-table-card">
                <!-- Cabecera con acciones -->
                <div class="card-header">
                    <div class="row g-3 align-items-center">
                        <div class="col-lg-5">
                            <h2 class="card-title">
                                <i class="bi bi-table me-2"></i>
                                Listado de casos
                            </h2>
                            <p class="card-subtitle">
                                Solo se muestran casos en estado <span class="status-badge status-badge--asignado">ASIGNADO</span>
                            </p>
                        </div>

                        <div class="col-lg-4">
                            <label class="form-label fw-semibold mb-2">
                                <i class="bi bi-send me-1"></i>
                                Agente destino
                            </label>
                            <select name="target_agent_id" class="form-select" required>
                                <option value="">Selecciona agente destino</option>
                                <?php foreach ($agents as $agent): ?>
                                    <?php if ((int)$agent['id'] === $selectedAgentId) continue; ?>
                                    <option value="<?= (int)$agent['id'] ?>">
                                        <?= htmlspecialchars($agent['full_name'] ?? $agent['username'] ?? 'Agente', ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-lg-3">
                            <button type="submit" class="btn btn-warning w-100" id="reassignBtn">
                                <i class="bi bi-arrow-left-right me-1"></i>
                                Reasignar seleccionados
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Contador de selección -->
                <div class="selection-bar">
                    <i class="bi bi-check2-square me-2"></i>
                    <span id="selectedCount">0</span> caso(s) seleccionado(s)
                </div>

                <!-- Tabla de casos -->
                <div class="table-responsive">
                    <?php if (empty($cases)): ?>
                        <div class="empty-state">
                            <i class="bi bi-inbox empty-state-icon"></i>
                            <h3 class="empty-state-title">No se encontraron casos</h3>
                            <p class="empty-state-text">
                                El agente seleccionado no tiene casos asignados con los filtros actuales.
                            </p>
                        </div>
                    <?php else: ?>
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th width="50">
                                        <input type="checkbox" id="checkAll" class="form-check-input">
                                    </th>
                                    <th>Caso</th>
                                    <th>Asunto</th>
                                    <th>Solicitante</th>
                                    <th>Correo</th>
                                    <th>Fecha asignación</th>
                                    <th>Última actividad</th>
                                    <th>Estado</th>
                                    <th width="100" class="text-center">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cases as $case): ?>
                                    <?php 
                                    $caseId = (int)($case['id'] ?? 0);
                                    $statusName = (string)($case['status_name'] ?? 'ASIGNADO');
                                    $statusInfo = getStatusBadgeClasses($statusName);
                                    ?>
                                    <tr>
                                        <td>
                                            <input 
                                                type="checkbox" 
                                                name="case_ids[]" 
                                                value="<?= $caseId ?>" 
                                                class="case-checkbox form-check-input"
                                            >
                                        </td>

                                        <td>
                                            <div class="fw-bold text-primary">
                                                <?= htmlspecialchars($case['case_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                            <small class="text-muted">ID: <?= $caseId ?></small>
                                        </td>

                                        <td>
                                            <div class="subject-cell" title="<?= htmlspecialchars($case['subject'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($case['subject'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($case['requester_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                        </td>

                                        <td>
                                            <a href="mailto:<?= htmlspecialchars($case['requester_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($case['requester_email'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        </td>

                                        <td>
                                            <?= formatDateTime($case['assigned_at'] ?? null) ?>
                                        </td>

                                        <td>
                                            <?= formatDateTime($case['last_activity_at'] ?? null) ?>
                                        </td>

                                        <td>
                                            <span class="status-badge <?= $statusInfo['class'] ?>">
                                                <i class="bi <?= $statusInfo['icon'] ?> me-1"></i>
                                                <?= htmlspecialchars($statusName, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>

                                        <td class="text-center">
                                            <a 
                                                href="<?= htmlspecialchars(url('/cases/' . $caseId), ENT_QUOTES, 'UTF-8') ?>" 
                                                class="btn btn-sm btn-outline-primary"
                                                title="Ver detalle del caso"
                                            >
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <!-- Paginación -->
        <?php if (!empty($cases) && $pagination['total_pages'] > 1): ?>
            <nav class="pagination-wrapper" aria-label="Paginación de casos">
                <ul class="pagination">
                    <li class="page-item <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(buildAgentFilterUrl(['page' => max(1, $pagination['page'] - 1)]), ENT_QUOTES, 'UTF-8') ?>">
                            <i class="bi bi-chevron-left"></i>
                            Anterior
                        </a>
                    </li>

                    <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                        <?php if ($i === 1 || $i === $pagination['total_pages'] || abs($i - $pagination['page']) <= 2): ?>
                            <li class="page-item <?= $i === $pagination['page'] ? 'active' : '' ?>">
                                <a class="page-link" href="<?= htmlspecialchars(buildAgentFilterUrl(['page' => $i]), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php elseif ($i === 2 && $pagination['page'] > 4): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php elseif ($i === $pagination['total_pages'] - 1 && $pagination['page'] < $pagination['total_pages'] - 3): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(buildAgentFilterUrl(['page' => $pagination['page'] + 1]), ENT_QUOTES, 'UTF-8') ?>">
                            Siguiente
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
/* Variables locales */
.agent-cases-container {
    padding: 1.5rem;
    background: #f8f9fc;
    min-height: 100vh;
}

/* Hero Section */
.agent-hero {
    background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
    border-radius: 24px;
    padding: 2rem 2.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: white;
    box-shadow: 0 10px 30px rgba(13, 110, 253, 0.2);
}

.hero-badge {
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(10px);
    padding: 0.4rem 1rem;
    border-radius: 100px;
    font-size: 0.85rem;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    margin-bottom: 1rem;
}

.hero-title {
    font-size: 2.2rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    line-height: 1.2;
}

.hero-description {
    font-size: 1rem;
    opacity: 0.9;
    max-width: 600px;
    margin-bottom: 0;
}

.agent-info-card {
    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    padding: 1.2rem 1.8rem;
    border-radius: 18px;
    text-align: right;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.agent-info-label {
    display: block;
    font-size: 0.8rem;
    opacity: 0.8;
    margin-bottom: 0.3rem;
}

.agent-info-name {
    font-size: 1.3rem;
    font-weight: 600;
    line-height: 1.2;
}

/* Cards */
.filter-card,
.cases-table-card {
    border: none;
    border-radius: 20px;
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.02);
    overflow: hidden;
}

.filter-card {
    background: white;
}

/* Métricas */
.metric-card {
    background: white;
    border-radius: 20px;
    padding: 1.5rem;
    display: flex;
    gap: 1rem;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.02);
    transition: transform 0.2s;
    height: 100%;
}

.metric-card:hover {
    transform: translateY(-2px);
}

.metric-icon {
    width: 60px;
    height: 60px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    flex-shrink: 0;
}

.metric-content {
    flex: 1;
}

.metric-label {
    font-size: 0.85rem;
    color: #6c757d;
    display: block;
    margin-bottom: 0.3rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.metric-value {
    font-size: 1.8rem;
    font-weight: 700;
    color: #212529;
    display: block;
    line-height: 1.2;
    margin-bottom: 0.2rem;
}

.metric-trend {
    font-size: 0.8rem;
    color: #6c757d;
}

/* Tabla */
.cases-table-card .card-header {
    background: white;
    border-bottom: 1px solid #e9ecef;
    padding: 1.5rem;
}

.card-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 0.3rem;
    color: #212529;
}

.card-subtitle {
    font-size: 0.9rem;
    color: #6c757d;
    margin-bottom: 0;
}

.selection-bar {
    background: #f8f9fa;
    padding: 0.8rem 1.5rem;
    font-size: 0.9rem;
    color: #495057;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    align-items: center;
}

/* Badges de estado */
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.5rem 1rem;
    border-radius: 100px;
    font-size: 0.85rem;
    font-weight: 500;
    border: 1px solid transparent;
}

.status-badge--asignado {
    background: #fff3cd;
    color: #856404;
    border-color: #ffeaa7;
}

/* Tabla estilos mejorados */
.table {
    margin-bottom: 0;
}

.table thead th {
    background: #f8f9fa;
    color: #495057;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    border-top: none;
    padding: 1rem 0.75rem;
    white-space: nowrap;
}

.table tbody td {
    padding: 1rem 0.75rem;
    vertical-align: middle;
}

.table tbody tr:hover {
    background: rgba(13, 110, 253, 0.02);
}

.subject-cell {
    max-width: 350px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Paginación */
.pagination-wrapper {
    margin-top: 2rem;
    display: flex;
    justify-content: center;
}

.pagination {
    gap: 0.3rem;
    background: white;
    padding: 0.8rem 1.2rem;
    border-radius: 50px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
}

.page-link {
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 40px !important;
    color: #6c757d;
    font-weight: 500;
    transition: all 0.2s;
}

.page-link:hover {
    background: #f8f9fa;
    color: #0d6efd;
    transform: translateY(-1px);
}

.page-item.active .page-link {
    background: #0d6efd;
    color: white;
    box-shadow: 0 5px 10px rgba(13, 110, 253, 0.3);
}

.page-item.disabled .page-link {
    background: transparent;
    color: #dee2e6;
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    background: white;
}

.empty-state-icon {
    font-size: 4rem;
    color: #dee2e6;
    margin-bottom: 1.5rem;
}

.empty-state-title {
    font-size: 1.3rem;
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.5rem;
}

.empty-state-text {
    color: #6c757d;
    max-width: 400px;
    margin: 0 auto;
}

/* Responsive */
@media (max-width: 992px) {
    .agent-hero {
        flex-direction: column;
        text-align: center;
        gap: 1.5rem;
        padding: 2rem 1.5rem;
    }
    
    .hero-title {
        font-size: 1.8rem;
    }
    
    .hero-description {
        max-width: 100%;
    }
    
    .agent-info-card {
        text-align: center;
        width: 100%;
    }
    
    .metric-card {
        flex-direction: column;
        text-align: center;
    }
    
    .metric-icon {
        margin: 0 auto;
    }
}
</style>

<script>
(function() {
    'use strict';
    
    document.addEventListener('DOMContentLoaded', function() {
        const checkAll = document.getElementById('checkAll');
        const checkboxes = document.querySelectorAll('.case-checkbox');
        const selectedCount = document.getElementById('selectedCount');
        const reassignForm = document.getElementById('bulkReassignForm');
        const reassignBtn = document.getElementById('reassignBtn');

        // Actualizar contador de selección
        function updateSelectedCount() {
            const checked = document.querySelectorAll('.case-checkbox:checked').length;
            if (selectedCount) {
                selectedCount.textContent = checked;
            }
        }

        // Manejar checkbox "Seleccionar todos"
        if (checkAll) {
            checkAll.addEventListener('change', function() {
                checkboxes.forEach(checkbox => {
                    checkbox.checked = checkAll.checked;
                });
                updateSelectedCount();
            });
        }

        // Manejar cambios en checkboxes individuales
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                if (checkAll) {
                    checkAll.checked = checkboxes.length > 0 && 
                        Array.from(checkboxes).every(cb => cb.checked);
                }
                updateSelectedCount();
            });
        });

        // Validar formulario antes de enviar
        if (reassignForm) {
            reassignForm.addEventListener('submit', function(e) {
                const checked = document.querySelectorAll('.case-checkbox:checked').length;
                const targetAgent = document.querySelector('select[name="target_agent_id"]');
                
                if (checked === 0) {
                    e.preventDefault();
                    showNotification('error', 'Debes seleccionar al menos un caso para reasignar.');
                    return;
                }
                
                if (!targetAgent || !targetAgent.value) {
                    e.preventDefault();
                    showNotification('error', 'Debes seleccionar un agente destino.');
                    targetAgent?.focus();
                    return;
                }

                // Confirmación con SweetAlert si está disponible
                if (window.Swal) {
                    e.preventDefault();
                    
                    Swal.fire({
                        title: '¿Confirmar reasignación?',
                        html: `
                            Se reasignarán <strong>${checked}</strong> caso(s) 
                            de <strong>${document.querySelector('option[value="' + targetAgent.value + '"]')?.textContent || 'agente destino'}</strong>.
                        `,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#ffc107',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Sí, reasignar',
                        cancelButtonText: 'Cancelar',
                        reverseButtons: true
                    }).then((result) => {
                        if (result.isConfirmed) {
                            reassignForm.submit();
                        }
                    });
                }
            });
        }

        // Función para mostrar notificaciones
        function showNotification(type, message) {
            if (window.Swal) {
                Swal.fire({
                    icon: type,
                    title: type === 'error' ? 'Error' : 'Atención',
                    text: message,
                    timer: 3000,
                    showConfirmButton: false
                });
            } else {
                alert(message);
            }
        }

        // Inicializar contador
        updateSelectedCount();
    });
})();
</script>