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
?>

<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Casos asignados por agente</h1>
            <p class="text-muted mb-0">
                Consulta los casos actualmente asignados a un agente y reasígnalos de forma masiva a otro agente.
            </p>
        </div>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="alert alert-<?= esc((string)($flash['type'] ?? 'info')) === 'error' ? 'danger' : esc((string)($flash['type'] ?? 'info')) ?> alert-dismissible fade show" role="alert">
            <?= esc((string)($flash['message'] ?? '')) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <form method="GET" action="<?= esc(url('/cases/by-agent')) ?>" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Agente origen</label>
                    <select name="agent_id" class="form-select" required>
                        <option value="">Selecciona un agente</option>
                        <?php foreach ($agents as $agent): ?>
                            <option value="<?= (int)$agent['id'] ?>" <?= $selectedAgentId === (int)$agent['id'] ? 'selected' : '' ?>>
                                <?= esc((string)($agent['full_name'] ?? $agent['username'] ?? 'Agente')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-5">
                    <label class="form-label fw-semibold">Buscar dentro de los casos</label>
                    <input
                        type="text"
                        name="q"
                        class="form-control"
                        value="<?= esc($q) ?>"
                        placeholder="Número de caso, asunto, solicitante o correo"
                    >
                </div>

                <div class="col-md-2 d-grid">
                    <button class="btn btn-primary">
                        <i class="bi bi-search me-1"></i> Consultar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selectedAgentId > 0): ?>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Total casos asignados</div>
                        <div class="display-6 fw-bold"><?= (int)($summary['total_assigned'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" action="<?= esc(url('/cases/by-agent/reassign')) ?>">
            <input type="hidden" name="_csrf" value="<?= esc((string)($_csrf ?? '')) ?>">
            <input type="hidden" name="source_agent_id" value="<?= $selectedAgentId ?>">

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <h2 class="h5 mb-1">Listado de casos</h2>
                        <p class="text-muted mb-0 small">Solo se muestran casos en estado ASIGNADO del agente seleccionado.</p>
                    </div>

                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <select name="target_agent_id" class="form-select" style="min-width: 280px;" required>
                            <option value="">Selecciona agente destino</option>
                            <?php foreach ($agents as $agent): ?>
                                <?php if ((int)$agent['id'] === $selectedAgentId) continue; ?>
                                <option value="<?= (int)$agent['id'] ?>">
                                    <?= esc((string)($agent['full_name'] ?? $agent['username'] ?? 'Agente')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="btn btn-warning fw-semibold">
                            <i class="bi bi-arrow-left-right me-1"></i> Reasignar seleccionados
                        </button>
                    </div>
                </div>

                <div class="card-body p-0">
                    <?php if (empty($cases)): ?>
                        <div class="p-4 text-center text-muted">
                            No se encontraron casos asignados para este agente.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 50px;">
                                            <input type="checkbox" id="check-all">
                                        </th>
                                        <th>Caso</th>
                                        <th>Asunto</th>
                                        <th>Solicitante</th>
                                        <th>Correo</th>
                                        <th>Asignado</th>
                                        <th>Última actividad</th>
                                        <th>Estado</th>
                                        <th>Detalle</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cases as $case): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="case_ids[]" value="<?= (int)$case['id'] ?>" class="case-check">
                                            </td>
                                            <td class="fw-semibold"><?= esc((string)($case['case_number'] ?? '')) ?></td>
                                            <td><?= esc((string)($case['subject'] ?? '')) ?></td>
                                            <td><?= esc((string)($case['requester_name'] ?? '')) ?></td>
                                            <td><?= esc((string)($case['requester_email'] ?? '')) ?></td>
                                            <td><?= esc((string)($case['assigned_at'] ?? '')) ?></td>
                                            <td><?= esc((string)($case['last_activity_at'] ?? '')) ?></td>
                                            <td>
                                                <span class="badge text-bg-warning">
                                                    <?= esc((string)($case['status_name'] ?? 'ASIGNADO')) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="<?= esc(url('/cases/' . (int)$case['id'])) ?>" class="btn btn-sm btn-outline-primary">
                                                    Ver caso
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
            <nav>
                <ul class="pagination">
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

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            items.forEach(item => {
                item.checked = checkAll.checked;
            });
        });
    }
});
</script>