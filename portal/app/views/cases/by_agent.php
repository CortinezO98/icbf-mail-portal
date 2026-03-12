<?php
declare(strict_types=1);

use function App\Config\url;

$title = 'Casos asignados por agente';

$agents = $agents ?? [];
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

if (!function_exists('formatDateView')) {
    function formatDateView(?string $value): string
    {
        if (!$value) {
            return '—';
        }

        try {
            $dt = new DateTime($value);
            return $dt->format('d/m/Y h:i A');
        } catch (\Throwable $e) {
            return (string)$value;
        }
    }
}

if (!function_exists('statusBadgeClassByName')) {
    function statusBadgeClassByName(?string $statusName): string
    {
        $status = strtoupper(trim((string)$statusName));

        return match ($status) {
            'ASIGNADO' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
            'EN_PROCESO' => 'bg-primary-subtle text-primary-emphasis border border-primary-subtle',
            'ESCALADO', 'ESCALATED' => 'bg-info-subtle text-info-emphasis border border-info-subtle',
            'RESPONDIDO' => 'bg-success-subtle text-success-emphasis border border-success-subtle',
            'CERRADO' => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
            default => 'bg-light text-dark border',
        };
    }
}

$selectedAgentName = '';
foreach ($agents as $agent) {
    if ((int)($agent['id'] ?? 0) === $selectedAgentId) {
        $selectedAgentName = (string)($agent['full_name'] ?? $agent['username'] ?? 'Agente');
        break;
    }
}
?>

<style>
    .by-agent-hero {
        background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 45%, #084298 100%);
        border-radius: 18px;
        color: #fff;
        overflow: hidden;
        position: relative;
    }

    .by-agent-hero::after {
        content: "";
        position: absolute;
        inset: 0;
        background: radial-gradient(circle at top right, rgba(255,255,255,.18), transparent 35%);
        pointer-events: none;
    }

    .by-agent-card {
        border: 0;
        border-radius: 18px;
        box-shadow: 0 0.5rem 1.5rem rgba(18, 38, 63, 0.08);
    }

    .summary-tile {
        border-radius: 16px;
        border: 1px solid #e9ecef;
        background: #fff;
        height: 100%;
    }

    .summary-value {
        font-size: 2rem;
        font-weight: 700;
        line-height: 1;
    }

    .table thead th {
        white-space: nowrap;
        font-size: .875rem;
        vertical-align: middle;
    }

    .table tbody td {
        vertical-align: middle;
        font-size: .92rem;
    }

    .subject-cell {
        min-width: 320px;
        max-width: 420px;
    }

    .subject-text {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        line-height: 1.35;
    }

    .meta-text {
        font-size: .8rem;
        color: #6c757d;
    }

    .sticky-actions {
        position: sticky;
        top: 0;
        z-index: 10;
        background: #fff;
    }

    .selection-bar {
        border-top: 1px solid #e9ecef;
        background: #f8f9fa;
    }
</style>

<div class="container-fluid py-4">
    <div class="by-agent-hero p-4 p-lg-5 mb-4 shadow-sm">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <span class="badge rounded-pill bg-light text-primary fw-semibold mb-3">Supervisión operativa</span>
                <h1 class="h2 fw-bold mb-2">Casos asignados por agente</h1>
                <p class="mb-0 opacity-75">
                    Consulta rápidamente la carga operativa de un agente, revisa el estado actual de sus casos y ejecuta reasignaciones masivas de forma controlada.
                </p>
            </div>
            <div class="col-lg-4">
                <div class="bg-white bg-opacity-10 rounded-4 p-3">
                    <div class="small text-white-50 mb-1">Agente consultado</div>
                    <div class="fs-5 fw-semibold"><?= $selectedAgentName !== '' ? esc($selectedAgentName) : 'Sin seleccionar' ?></div>
                </div>
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
        <div class="alert alert-<?= esc($alertClass) ?> alert-dismissible fade show shadow-sm border-0" role="alert">
            <?= esc((string)($flash['message'] ?? '')) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card by-agent-card mb-4">
        <div class="card-body p-4">
            <form method="GET" action="<?= esc(url('/cases/by-agent')) ?>" class="row g-3 align-items-end">
                <div class="col-lg-4 col-md-6">
                    <label class="form-label fw-semibold">Agente origen</label>
                    <select name="agent_id" class="form-select form-select-lg" required>
                        <option value="">Selecciona un agente</option>
                        <?php foreach ($agents as $agent): ?>
                            <option value="<?= (int)$agent['id'] ?>" <?= $selectedAgentId === (int)$agent['id'] ? 'selected' : '' ?>>
                                <?= esc((string)($agent['full_name'] ?? $agent['username'] ?? 'Agente')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-5 col-md-6">
                    <label class="form-label fw-semibold">Buscar dentro de los casos</label>
                    <input
                        type="text"
                        name="q"
                        class="form-control form-control-lg"
                        value="<?= esc($q) ?>"
                        placeholder="Número de caso, asunto, solicitante o correo"
                    >
                </div>

                <div class="col-lg-3 d-grid">
                    <button class="btn btn-primary btn-lg fw-semibold">
                        <i class="bi bi-search me-1"></i> Consultar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selectedAgentId > 0): ?>
        <div class="row g-3 mb-4">
            <div class="col-lg-4 col-md-6">
                <div class="summary-tile p-4 shadow-sm">
                    <div class="text-muted small text-uppercase fw-semibold mb-2">Total de casos asignados</div>
                    <div class="summary-value text-primary"><?= (int)($summary['total_assigned'] ?? 0) ?></div>
                    <div class="meta-text mt-2">Casos activos asociados al agente seleccionado.</div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="summary-tile p-4 shadow-sm">
                    <div class="text-muted small text-uppercase fw-semibold mb-2">Agente actual</div>
                    <div class="fw-bold fs-5"><?= esc($selectedAgentName !== '' ? $selectedAgentName : 'No definido') ?></div>
                    <div class="meta-text mt-2">Vista consolidada para revisión y reasignación operativa.</div>
                </div>
            </div>

            <div class="col-lg-4 col-md-12">
                <div class="summary-tile p-4 shadow-sm">
                    <div class="text-muted small text-uppercase fw-semibold mb-2">Búsqueda aplicada</div>
                    <div class="fw-semibold fs-6"><?= $q !== '' ? esc($q) : 'Sin filtro adicional' ?></div>
                    <div class="meta-text mt-2">Usa la búsqueda para localizar un caso específico más rápido.</div>
                </div>
            </div>
        </div>

        <form method="POST" action="<?= esc(url('/cases/by-agent/reassign')) ?>" id="bulk-reassign-form">
            <input type="hidden" name="_csrf" value="<?= esc((string)($_csrf ?? '')) ?>">
            <input type="hidden" name="source_agent_id" value="<?= $selectedAgentId ?>">

            <div class="card by-agent-card mb-4">
                <div class="card-header bg-white border-0 p-4 pb-3 sticky-actions">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-5">
                            <h2 class="h5 fw-bold mb-1">Listado de casos</h2>
                            <p class="text-muted mb-0">
                                Visualiza los casos del agente seleccionado, incluyendo su estado actual y acceso directo al detalle.
                            </p>
                        </div>

                        <div class="col-lg-4">
                            <label class="form-label fw-semibold mb-2">Agente destino</label>
                            <select name="target_agent_id" class="form-select" required>
                                <option value="">Selecciona agente destino</option>
                                <?php foreach ($agents as $agent): ?>
                                    <?php if ((int)$agent['id'] === $selectedAgentId) continue; ?>
                                    <option value="<?= (int)$agent['id'] ?>">
                                        <?= esc((string)($agent['full_name'] ?? $agent['username'] ?? 'Agente')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-lg-3 d-grid">
                            <button type="submit" class="btn btn-warning fw-semibold" id="btn-reassign">
                                <i class="bi bi-arrow-left-right me-1"></i> Reasignar seleccionados
                            </button>
                        </div>
                    </div>
                </div>

                <div class="selection-bar px-4 py-2 small text-muted">
                    <span id="selected-counter">0</span> caso(s) seleccionado(s)
                </div>

                <div class="card-body p-0">
                    <?php if (empty($cases)): ?>
                        <div class="p-5 text-center">
                            <div class="mb-3">
                                <i class="bi bi-inbox fs-1 text-muted"></i>
                            </div>
                            <h3 class="h5 fw-bold">No se encontraron casos</h3>
                            <p class="text-muted mb-0">
                                El agente seleccionado no tiene casos disponibles con los filtros actuales.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 52px;">
                                            <input type="checkbox" id="check-all">
                                        </th>
                                        <th>Caso</th>
                                        <th>Asunto</th>
                                        <th>Solicitante</th>
                                        <th>Correo</th>
                                        <th>Fecha asignación</th>
                                        <th>Última actividad</th>
                                        <th>Estado</th>
                                        <th class="text-center">Detalle</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cases as $case): ?>
                                        <?php
                                            $statusName = (string)($case['status_name'] ?? 'Sin estado');
                                            $badgeClass = statusBadgeClassByName($statusName);
                                        ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="case_ids[]" value="<?= (int)$case['id'] ?>" class="case-check">
                                            </td>

                                            <td>
                                                <div class="fw-bold text-primary"><?= esc((string)($case['case_number'] ?? '')) ?></div>
                                                <div class="meta-text">ID interno: <?= (int)($case['id'] ?? 0) ?></div>
                                            </td>

                                            <td class="subject-cell">
                                                <div class="subject-text fw-semibold" title="<?= esc((string)($case['subject'] ?? '')) ?>">
                                                    <?= esc((string)($case['subject'] ?? '')) ?>
                                                </div>
                                            </td>

                                            <td>
                                                <div class="fw-semibold"><?= esc((string)($case['requester_name'] ?? '')) ?></div>
                                            </td>

                                            <td>
                                                <div><?= esc((string)($case['requester_email'] ?? '')) ?></div>
                                            </td>

                                            <td>
                                                <div><?= esc(formatDateView((string)($case['assigned_at'] ?? ''))) ?></div>
                                            </td>

                                            <td>
                                                <div><?= esc(formatDateView((string)($case['last_activity_at'] ?? ''))) ?></div>
                                            </td>

                                            <td>
                                                <span class="badge rounded-pill px-3 py-2 <?= esc($badgeClass) ?>">
                                                    <?= esc($statusName) ?>
                                                </span>
                                            </td>

                                            <td class="text-center">
                                                <a href="<?= esc(url('/cases/' . (int)$case['id'])) ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye me-1"></i> Ver
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <?php if (!empty($cases) && (int)($pagination['total_pages'] ?? 1) > 1): ?>
            <nav class="d-flex justify-content-center">
                <ul class="pagination shadow-sm">
                    <li class="page-item <?= !empty($pagination['has_prev']) ? '' : 'disabled' ?>">
                        <a class="page-link" href="<?= esc(buildByAgentUrl(['page' => max(1, ((int)$pagination['page']) - 1)])) ?>">Anterior</a>
                    </li>

                    <?php for ($i = 1; $i <= (int)$pagination['total_pages']; $i++): ?>
                        <li class="page-item <?= $i === (int)$pagination['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= esc(buildByAgentUrl(['page' => $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?= !empty($pagination['has_next']) ? '' : 'disabled' ?>">
                        <a class="page-link" href="<?= esc(buildByAgentUrl(['page' => ((int)$pagination['page']) + 1])) ?>">Siguiente</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const checkAll = document.getElementById('check-all');
    const items = document.querySelectorAll('.case-check');
    const counter = document.getElementById('selected-counter');
    const form = document.getElementById('bulk-reassign-form');

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
            if (checked === 0) {
                e.preventDefault();
                alert('Debes seleccionar al menos un caso para reasignar.');
            }
        });
    }

    updateCounter();
});
</script>