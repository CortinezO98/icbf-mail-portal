<?php
declare(strict_types=1);

use App\Auth\Auth;
use function App\Config\url;

$title = 'Casos asignados por agente';

$roleIsSupervisor = Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN');

$agents = $agents ?? [];
$targetAgents = $targetAgents ?? [];
$cases = $cases ?? [];
$selectedAgentId = (int)($selectedAgentId ?? 0);
$q = trim((string)($q ?? ''));
$summary = $summary ?? ['total_assigned' => 0];
$pagination = $pagination ?? [
    'page' => 1,
    'per_page' => 20,
    'total_rows' => 0,
    'total_pages' => 1,
    'has_prev' => false,
    'has_next' => false,
    'offset' => 0,
];

if (!function_exists('buildByAgentUrl')) {
    function buildByAgentUrl(array $overrides = []): string
    {
        $params = is_array($_GET ?? null) ? $_GET : [];

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
}

if (!function_exists('buildByAgentPaginationUrl')) {
    function buildByAgentPaginationUrl(int $page): string
    {
        return buildByAgentUrl([
            'page' => $page > 1 ? $page : null,
        ]);
    }
}

if (!function_exists('badge_status_class_by_agent')) {
    function badge_status_class_by_agent(string $code): string
    {
        return match (strtoupper(trim($code))) {
            'NUEVO' => 'bg-primary-subtle text-primary border-primary',
            'ASIGNADO' => 'bg-warning-subtle text-warning-emphasis border-warning',
            'EN_PROCESO' => 'bg-info-subtle text-info-emphasis border-info',
            'ESPERANDO_INFO' => 'bg-warning-subtle text-warning-emphasis border-warning',
            'ESCALADO', 'ESCALATED' => 'bg-danger-subtle text-danger-emphasis border-danger',
            'RESPONDIDO' => 'bg-success-subtle text-success-emphasis border-success',
            'CERRADO' => 'bg-secondary-subtle text-secondary border-secondary',
            default => 'bg-light text-dark border',
        };
    }
}

if (!function_exists('formatByAgentDate')) {
    function formatByAgentDate(?string $value): string
    {
        if (!$value) {
            return '—';
        }

        try {
            $dt = new DateTime($value);
            return $dt->format('d/m/Y');
        } catch (\Throwable $e) {
            return (string)$value;
        }
    }
}

if (!function_exists('formatByAgentHour')) {
    function formatByAgentHour(?string $value): string
    {
        if (!$value) {
            return '';
        }

        try {
            $dt = new DateTime($value);
            return $dt->format('H:i');
        } catch (\Throwable $e) {
            return '';
        }
    }
}

$selectedAgentName = '';
foreach ($agents as $agent) {
    if ((int)($agent['id'] ?? 0) === $selectedAgentId) {
        $selectedAgentName = (string)($agent['full_name'] ?? $agent['username'] ?? 'Agente');
        break;
    }
}

$totalCases = (int)($pagination['total_rows'] ?? 0);
$currentPage = max(1, (int)($pagination['page'] ?? 1));
$totalPages = max(1, (int)($pagination['total_pages'] ?? 1));
$perPage = max(1, (int)($pagination['per_page'] ?? 20));
$hasPrev = (bool)($pagination['has_prev'] ?? false);
$hasNext = (bool)($pagination['has_next'] ?? false);
$startRow = $totalCases > 0 ? (($currentPage - 1) * $perPage + 1) : 0;
$endRow = $totalCases > 0 ? min($currentPage * $perPage, $totalCases) : 0;
$casesCount = count($cases ?? []);
?>

<div class="inbox-container">
    <div class="row align-items-center mb-4">
        <div class="col-md-7">
            <div class="d-flex align-items-center">
                <div class="me-3">
                    <div class="inbox-icon bg-brand rounded-3 p-3">
                        <i class="bi bi-people-fill text-white fs-4"></i>
                    </div>
                </div>
                <div>
                    <h1 class="h2 mb-1 fw-bold">Casos asignados por agente</h1>
                    <div class="text-muted d-flex align-items-center gap-2 flex-wrap">
                        <span>
                            <i class="bi bi-person-badge me-1"></i>
                            <?= $selectedAgentName !== '' ? esc($selectedAgentName) : 'Sin agente seleccionado' ?>
                        </span>

                        <?php if ($q !== ''): ?>
                            <span class="text-muted">•</span>
                            <span>
                                <i class="bi bi-search me-1"></i>
                                Búsqueda: "<?= esc($q) ?>"
                            </span>
                        <?php endif; ?>

                        <span class="text-muted">•</span>
                        <span>
                            <i class="bi bi-diagram-3 me-1"></i>
                            <?= $roleIsSupervisor ? 'Vista de supervisión' : 'Vista operativa' ?>
                        </span>

                        <?php if ($selectedAgentId > 0): ?>
                            <span class="text-muted">•</span>
                            <span class="badge bg-brand-subtle text-brand rounded-pill">
                                <?= number_format((int)($summary['total_assigned'] ?? 0), 0, ',', '.') ?>
                                <?= (int)($summary['total_assigned'] ?? 0) === 1 ? 'caso' : 'casos' ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-5">
            <div class="d-flex justify-content-end gap-2 flex-wrap">
                <a href="<?= esc(url('/cases')) ?>" class="btn btn-outline-brand">
                    <i class="bi bi-arrow-left me-1"></i>Volver a bandeja
                </a>
            </div>
        </div>
    </div>

    <?php if (!empty($flash)): ?>
        <?php
            $flashType = (string)($flash['type'] ?? 'info');
            $alertClass = match ($flashType) {
                'error' => 'danger',
                'success' => 'success',
                'warning' => 'warning',
                default => 'info',
            };
        ?>
        <div class="alert alert-<?= esc($alertClass) ?> alert-dismissible fade show border mb-4" role="alert">
            <?= esc((string)($flash['message'] ?? '')) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-transparent border-bottom">
            <div class="row g-2 align-items-center">
                <div class="col-lg-4">
                    <h5 class="mb-0">Consulta por agente</h5>
                </div>

                <div class="col-lg-8">
                    <form method="GET" action="<?= esc(url('/cases/by-agent')) ?>" class="row g-2 justify-content-end">
                        <?php if ($perPage): ?>
                            <input type="hidden" name="per_page" value="<?= esc((string)$perPage) ?>">
                        <?php endif; ?>

                        <div class="col-md-4">
                            <select name="agent_id" class="form-select form-select-sm" required>
                                <option value="">Selecciona un agente</option>
                                <?php foreach ($agents as $agent): ?>
                                    <option value="<?= (int)$agent['id'] ?>" <?= $selectedAgentId === (int)$agent['id'] ? 'selected' : '' ?>>
                                        <?= esc((string)($agent['full_name'] ?? $agent['username'] ?? 'Agente')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-5">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-transparent border-end-0">
                                    <i class="bi bi-search text-muted"></i>
                                </span>
                                <input type="text"
                                       class="form-control border-start-0"
                                       placeholder="Buscar por caso, asunto, nombre o correo..."
                                       name="q"
                                       value="<?= esc($q) ?>"
                                       autocomplete="off">
                            </div>
                        </div>

                        <div class="col-md-auto">
                            <button class="btn btn-sm btn-brand w-100" type="submit">
                                <i class="bi bi-search me-1"></i>Buscar
                            </button>
                        </div>

                        <div class="col-md-auto">
                            <a class="btn btn-sm btn-outline-secondary w-100"
                               href="<?= esc(buildByAgentUrl([
                                   'q' => null,
                                   'page' => null
                               ])) ?>">
                                <i class="bi bi-eraser me-1"></i>Limpiar
                            </a>
                        </div>

                        <div class="col-md-auto">
                            <button class="btn btn-sm btn-outline-secondary w-100" type="button" id="refreshByAgentBtn">
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($selectedAgentId > 0): ?>
            <div class="card-body border-bottom bg-light-subtle">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <div class="bg-brand-subtle rounded-3 p-2">
                                    <i class="bi bi-collection text-brand fs-4"></i>
                                </div>
                            </div>
                            <div>
                                <div class="small text-muted">Total casos asignados</div>
                                <div class="fw-bold fs-4"><?= number_format((int)($summary['total_assigned'] ?? 0), 0, ',', '.') ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <div class="bg-primary-subtle rounded-3 p-2">
                                    <i class="bi bi-person-check text-primary fs-4"></i>
                                </div>
                            </div>
                            <div>
                                <div class="small text-muted">Agente consultado</div>
                                <div class="fw-semibold"><?= esc($selectedAgentName !== '' ? $selectedAgentName : 'No definido') ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <div class="bg-warning-subtle rounded-3 p-2">
                                    <i class="bi bi-funnel text-warning fs-4"></i>
                                </div>
                            </div>
                            <div>
                                <div class="small text-muted">Filtro actual</div>
                                <div class="fw-semibold"><?= $q !== '' ? esc($q) : 'Sin búsqueda adicional' ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card-body p-0">
            <?php if ($selectedAgentId <= 0): ?>
                <div class="text-center py-5">
                    <div class="mb-4">
                        <i class="bi bi-person-lines-fill display-1 text-muted opacity-50"></i>
                    </div>
                    <h4 class="text-muted mb-2">Selecciona un agente</h4>
                    <p class="text-muted mb-0">
                        Escoge un agente para visualizar los casos que tiene asignados actualmente.
                    </p>
                </div>
            <?php elseif (empty($cases)): ?>
                <div class="text-center py-5">
                    <div class="mb-4">
                        <i class="bi bi-inbox display-1 text-muted opacity-50"></i>
                    </div>
                    <h4 class="text-muted mb-2">No hay casos disponibles</h4>
                    <p class="text-muted mb-0">
                        No se encontraron casos para el agente seleccionado con los filtros aplicados.
                    </p>
                </div>
            <?php else: ?>
                <?php if ($totalCases > 0): ?>
                    <div class="alert alert-light border-0 border-bottom rounded-0 mb-0">
                        <div class="row align-items-center g-2">
                            <div class="col-md-6">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
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
                                            <?php foreach ([10, 20, 50, 100] as $option): ?>
                                                <li>
                                                    <a class="dropdown-item <?= $perPage === $option ? 'active' : '' ?>"
                                                       href="<?= esc(buildByAgentUrl([
                                                           'per_page' => $option,
                                                           'page' => null
                                                       ])) ?>">
                                                        <?= $option ?> por página
                                                    </a>
                                                </li>
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

                <form method="POST" action="<?= esc(url('/cases/by-agent/reassign')) ?>" id="bulk-reassign-form">
                    <input type="hidden" name="_csrf" value="<?= esc((string)($_csrf ?? '')) ?>">
                    <input type="hidden" name="source_agent_id" value="<?= $selectedAgentId ?>">

                    <div class="border-bottom p-3 bg-white">
                        <div class="row g-3 align-items-end">
                            <div class="col-lg-5">
                                <label class="form-label fw-semibold">Reasignar casos seleccionados a</label>
                                <select name="target_agent_id" class="form-select">
                                    <option value="">Selecciona agente destino</option>
                                    <?php foreach ($targetAgents as $agent): ?>
                                        <?php if ((int)$agent['id'] === $selectedAgentId) continue; ?>
                                        <option value="<?= (int)$agent['id'] ?>">
                                            <?= esc((string)($agent['full_name'] ?? $agent['username'] ?? 'Agente')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-lg-4">
                                <div class="small text-muted mb-2">Casos seleccionados</div>
                                <div class="fw-semibold">
                                    <span id="selected-counter">0</span> caso(s)
                                </div>
                            </div>

                            <div class="col-lg-3 d-grid">
                                <button type="submit" class="btn btn-warning" id="btn-reassign">
                                    <i class="bi bi-arrow-left-right me-1"></i>Reasignar seleccionados
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="byAgentTable">
                            <thead>
                                <tr class="table-light">
                                    <th class="ps-4" style="width: 50px;">
                                        <input type="checkbox" id="check-all">
                                    </th>
                                    <th style="width: 180px;"># Caso</th>
                                    <th>Asunto</th>
                                    <th style="width: 210px;">Solicitante</th>
                                    <th style="width: 140px;" class="text-center">Estado</th>
                                    <th style="width: 150px;">Asignado</th>
                                    <th style="width: 150px;" class="pe-4">Últ. Actividad</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cases as $case): ?>
                                    <?php
                                        $caseId = (int)($case['id'] ?? 0);
                                        $caseNumber = (string)($case['case_number'] ?? '');
                                        $subject = (string)($case['subject'] ?? '');
                                        $requesterName = (string)($case['requester_name'] ?? '');
                                        $requesterEmail = (string)($case['requester_email'] ?? '');
                                        $statusCode = strtoupper(trim((string)($case['status_code'] ?? '')));
                                        $statusName = (string)($case['status_name'] ?? $statusCode ?: 'Sin estado');
                                        $statusClass = badge_status_class_by_agent($statusCode !== '' ? $statusCode : $statusName);

                                        $assignedAtRaw = (string)($case['assigned_at'] ?? '');
                                        $lastActivityRaw = (string)($case['last_activity_at'] ?? '');

                                        $priorityClass = match ($statusCode) {
                                            'NUEVO' => 'border-start border-primary border-3',
                                            'ASIGNADO' => 'border-start border-warning border-3',
                                            'EN_PROCESO' => 'border-start border-info border-3',
                                            'RESPONDIDO' => 'border-start border-success border-3',
                                            'ESPERANDO_INFO' => 'border-start border-warning border-3',
                                            'ESCALADO', 'ESCALATED' => 'border-start border-danger border-3',
                                            'CERRADO' => 'border-start border-secondary border-3',
                                            default => 'border-start border-secondary border-3',
                                        };
                                    ?>
                                    <tr class="case-row <?= esc($priorityClass) ?>" data-id="<?= $caseId ?>">
                                        <td class="ps-4">
                                            <input type="checkbox" name="case_ids[]" value="<?= $caseId ?>" class="case-check">
                                        </td>

                                        <td>
                                            <a href="<?= esc(url('/cases/' . $caseId)) ?>" class="text-decoration-none d-block">
                                                <div class="fw-bold text-primary"><?= esc($caseNumber) ?></div>
                                                <div class="text-muted small mt-1">ID interno: <?= $caseId ?></div>
                                            </a>
                                        </td>

                                        <td>
                                            <div class="d-flex">
                                                <div class="flex-grow-1">
                                                    <div class="fw-semibold text-truncate" style="max-width: 320px;" title="<?= esc($subject) ?>">
                                                        <?= esc($subject) ?>
                                                    </div>
                                                    <div class="text-muted small mt-1 text-truncate" style="max-width: 320px;">
                                                        <i class="bi bi-envelope me-1"></i><?= esc($requesterEmail) ?>
                                                    </div>
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
                                                    <div class="fw-semibold"><?= esc($requesterName) ?></div>
                                                    <div class="text-muted small text-truncate" style="max-width: 180px;">
                                                        <?= esc($requesterEmail) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <td class="text-center">
                                            <span class="badge rounded-pill <?= esc($statusClass) ?> px-3 py-2">
                                                <?= esc($statusName) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <div class="text-nowrap">
                                                <div class="fw-medium"><?= esc(formatByAgentDate($assignedAtRaw)) ?></div>
                                                <?php if ($assignedAtRaw !== ''): ?>
                                                    <div class="text-muted small"><?= esc(formatByAgentHour($assignedAtRaw)) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <td class="pe-4">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div class="text-nowrap">
                                                    <div class="fw-medium"><?= esc(formatByAgentDate($lastActivityRaw)) ?></div>
                                                    <?php if ($lastActivityRaw !== ''): ?>
                                                        <div class="text-muted small"><?= esc(formatByAgentHour($lastActivityRaw)) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <a href="<?= esc(url('/cases/' . $caseId)) ?>" class="btn btn-sm btn-outline-brand ms-2">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!empty($cases) && $totalPages > 1): ?>
            <div class="card-footer bg-transparent border-top">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="text-muted small">
                        Página <strong><?= $currentPage ?></strong> de <strong><?= $totalPages ?></strong>
                        •
                        <?= number_format($totalCases, 0, ',', '.') ?>
                        <?= $totalCases === 1 ? 'caso' : 'casos' ?> en total
                    </div>

                    <nav aria-label="Paginación de casos por agente">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= !$hasPrev ? 'disabled' : '' ?>">
                                <a class="page-link"
                                   href="<?= esc(buildByAgentPaginationUrl(1)) ?>"
                                   aria-label="Primera">
                                    <i class="bi bi-chevron-double-left"></i>
                                </a>
                            </li>

                            <li class="page-item <?= !$hasPrev ? 'disabled' : '' ?>">
                                <a class="page-link"
                                   href="<?= esc(buildByAgentPaginationUrl($currentPage - 1)) ?>"
                                   aria-label="Anterior">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>

                            <?php
                            $startPage = max(1, $currentPage - 2);
                            $endPage = min($totalPages, $currentPage + 2);

                            if ($startPage > 1) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }

                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                                <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= esc(buildByAgentPaginationUrl($i)) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($endPage < $totalPages): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>

                            <li class="page-item <?= !$hasNext ? 'disabled' : '' ?>">
                                <a class="page-link"
                                   href="<?= esc(buildByAgentPaginationUrl($currentPage + 1)) ?>"
                                   aria-label="Siguiente">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>

                            <li class="page-item <?= !$hasNext ? 'disabled' : '' ?>">
                                <a class="page-link"
                                   href="<?= esc(buildByAgentPaginationUrl($totalPages)) ?>"
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
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="text-muted small">
                        Mostrando <strong><?= $casesCount ?></strong>
                        <?= $casesCount === 1 ? 'caso' : 'casos' ?>
                        del agente
                        <strong><?= esc($selectedAgentName !== '' ? $selectedAgentName : 'seleccionado') ?></strong>
                        <?php if ($q !== ''): ?>
                            para la búsqueda "<?= esc($q) ?>"
                        <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button class="btn btn-sm btn-outline-secondary" id="printByAgentBtn">
                            <i class="bi bi-printer me-1"></i>Imprimir
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const checkAll = document.getElementById('check-all');
    const items = document.querySelectorAll('.case-check');
    const counter = document.getElementById('selected-counter');
    const form = document.getElementById('bulk-reassign-form');
    const refreshBtn = document.getElementById('refreshByAgentBtn');

    function updateCounter() {
        let total = 0;
        items.forEach(item => {
            if (item.checked) total++;
        });
        if (counter) {
            counter.textContent = total;
        }
    }

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            items.forEach(item => {
                item.checked = checkAll.checked;
            });
            updateCounter();
        });
    }

    items.forEach(item => {
        item.addEventListener('change', updateCounter);
    });

    if (form) {
        form.addEventListener('submit', function (e) {
            const checked = document.querySelectorAll('.case-check:checked').length;
            const targetAgent = form.querySelector('[name="target_agent_id"]')?.value ?? '';

            if (checked === 0) {
                e.preventDefault();
                alert('Debes seleccionar al menos un caso para reasignar.');
                return;
            }

            if (targetAgent === '') {
                e.preventDefault();
                alert('Debes seleccionar un agente destino.');
            }
        });
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            this.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
            setTimeout(() => {
                window.location.reload();
            }, 500);
        });
    }

    document.getElementById('printByAgentBtn')?.addEventListener('click', function () {
        window.print();
    });

    const caseRows = document.querySelectorAll('.case-row');
    caseRows.forEach(row => {
        row.addEventListener('click', function(e) {
            if (!e.target.closest('a') && !e.target.closest('button') && !e.target.closest('input') && !e.target.closest('select') && !e.target.closest('label')) {
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

    updateCounter();
});
</script>

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

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.bi.spin {
    animation: spin 1s linear infinite;
}

@media (max-width: 768px) {
    .inbox-container {
        padding: 1rem;
    }

    .table-responsive {
        font-size: 0.875rem;
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
    .btn,
    .card-footer,
    .pagination,
    .alert,
    form,
    #refreshByAgentBtn {
        display: none !important;
    }

    .case-row {
        break-inside: avoid;
    }
}
</style>