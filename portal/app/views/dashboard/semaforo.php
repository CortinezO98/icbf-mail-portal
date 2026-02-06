<?php
declare(strict_types=1);

use function App\Config\url;



function fmt_dt(?string $dt): string {
    if (!$dt) return '—';
    $ts = strtotime($dt);
    if ($ts === false) return esc($dt);
    return date('d/m/Y H:i', $ts);
}

function fmt_date(?string $dt): string {
    if (!$dt) return '—';
    $ts = strtotime($dt);
    if ($ts === false) return esc($dt);
    return date('d/m/Y', $ts);
}

function n(mixed $v): int {
    return (int)($v ?? 0);
}

$estado = strtoupper((string)($estado ?? ''));
$cases = is_array($cases ?? null) ? $cases : [];

$meta = [
    'ROJO' => [
        'title' => 'Casos en ROJO',
        'subtitle' => 'Prioridad alta / riesgo de incumplimiento',
        'badge' => 'bg-danger',
        'icon' => 'bi-exclamation-octagon-fill',
        'tableRowClass' => 'table-danger',
    ],
    'AMARILLO' => [
        'title' => 'Casos en AMARILLO',
        'subtitle' => 'Próximos a vencer',
        'badge' => 'bg-warning text-dark',
        'icon' => 'bi-exclamation-triangle-fill',
        'tableRowClass' => 'table-warning',
    ],
    'VERDE' => [
        'title' => 'Casos en VERDE',
        'subtitle' => 'Dentro de plazo',
        'badge' => 'bg-success',
        'icon' => 'bi-check-circle-fill',
        'tableRowClass' => '',
    ],
];

$cfg = $meta[$estado] ?? [
    'title' => 'Semáforo',
    'subtitle' => 'Listado',
    'badge' => 'bg-secondary',
    'icon' => 'bi-traffic-light',
    'tableRowClass' => '',
];

$total = count($cases);
?>

<div class="dashboard-container">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h1 class="h3 mb-0 fw-bold text-dark">
                    <i class="bi <?= esc($cfg['icon']) ?> me-2"></i><?= esc($cfg['title']) ?>
                </h1>
                <span class="badge <?= esc($cfg['badge']) ?> px-3 py-2">
                    <?= esc($estado ?: '—') ?>
                </span>
            </div>
            <div class="text-muted mt-1">
                <?= esc($cfg['subtitle']) ?> •
                <span class="fw-semibold"><?= $total ?></span> caso<?= $total === 1 ? '' : 's' ?>
            </div>
        </div>

        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="<?= esc(url('/dashboard')) ?>">
                <i class="bi bi-arrow-left me-1"></i>Volver al tablero
            </a>
            <button class="btn btn-outline-dark" type="button" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Imprimir
            </button>
        </div>
    </div>

    <!-- Controls -->
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-center">
                <div class="col-lg-6">
                    <div class="input-group">
                        <span class="input-group-text bg-light">
                            <i class="bi bi-search"></i>
                        </span>
                        <input
                            id="searchInput"
                            type="text"
                            class="form-control"
                            placeholder="Buscar por radicado, asunto, solicitante, correo o agente…"
                            autocomplete="off"
                        >
                        <button class="btn btn-outline-secondary" type="button" id="clearBtn" title="Limpiar">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="form-text">
                        Tip: escribe 2-3 letras para filtrar rápido (no consulta a BD, es en pantalla).
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="d-flex justify-content-lg-end gap-2 flex-wrap">
                        <span class="badge bg-light text-dark border px-3 py-2">
                            <i class="bi bi-clock me-1"></i>
                            Actualizado: <?= esc(date('d/m/Y H:i')) ?>
                        </span>

                        <a class="btn btn-brand" href="<?= esc(url('/cases')) ?>">
                            <i class="bi bi-inbox me-1"></i>Ir a bandeja
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="fw-semibold">
                <i class="bi bi-list-check me-1"></i>Listado de casos
            </div>
            <div class="small text-muted">
                Mostrando <span id="shownCount" class="fw-semibold"><?= (int)$total ?></span> de <?= (int)$total ?>
            </div>
        </div>

        <div class="card-body p-0">
            <?php if (empty($cases)): ?>
                <div class="p-4 text-center">
                    <div class="display-6 text-muted mb-2">
                        <i class="bi bi-emoji-smile"></i>
                    </div>
                    <div class="fw-semibold">No hay casos para este semáforo</div>
                    <div class="text-muted small mt-1">
                        Puedes volver al tablero o revisar la bandeja.
                    </div>
                    <div class="mt-3 d-flex justify-content-center gap-2 flex-wrap">
                        <a class="btn btn-outline-secondary" href="<?= esc(url('/dashboard')) ?>">
                            <i class="bi bi-arrow-left me-1"></i>Volver
                        </a>
                        <a class="btn btn-brand" href="<?= esc(url('/cases')) ?>">
                            <i class="bi bi-inbox me-1"></i>Bandeja
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="casesTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 110px;">Caso</th>
                                <th>Asunto / Solicitante</th>
                                <th style="width: 170px;">Estado</th>
                                <th style="width: 190px;">Agente</th>
                                <th style="width: 150px;">Recibido</th>
                                <th style="width: 120px;" class="text-end">Días</th>
                                <th style="width: 170px;">Vence (SLA)</th>
                                <th style="width: 120px;" class="text-end">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cases as $c): ?>
                            <?php
                                $caseId = (int)($c['id'] ?? 0);
                                $caseNumber = (string)($c['case_number'] ?? $caseId);
                                $subject = (string)($c['subject'] ?? '');
                                $reqName = (string)($c['requester_name'] ?? '');
                                $reqEmail = (string)($c['requester_email'] ?? '');
                                $statusName = (string)($c['status_name'] ?? '');
                                $statusCode = (string)($c['status_code'] ?? '');
                                $assignedTo = (string)($c['assigned_to'] ?? '—');
                                $receivedAt = (string)($c['received_at'] ?? '');
                                $days = n($c['dias_desde_recibido'] ?? 0);
                                $dueAt = (string)($c['sla_due_at'] ?? '');
                                $breached = (int)($c['breached'] ?? 0);

                                $rowClass = $cfg['tableRowClass'];
                                if ($breached === 1) {
                                    // refuerza visualmente si ya incumplió
                                    $rowClass = 'table-danger';
                                }

                                $statusBadge = 'bg-secondary';
                                if ($statusCode === 'NUEVO') $statusBadge = 'bg-info text-dark';
                                elseif ($statusCode === 'ASIGNADO') $statusBadge = 'bg-primary';
                                elseif ($statusCode === 'EN_PROCESO') $statusBadge = 'bg-warning text-dark';
                                elseif ($statusCode === 'RESPONDIDO') $statusBadge = 'bg-success';
                            ?>
                            <tr class="case-row <?= esc($rowClass) ?>">
                                <td class="fw-semibold">
                                    <?= esc($caseNumber) ?>
                                    <?php if ($breached === 1): ?>
                                        <div class="small text-danger">
                                            <i class="bi bi-x-octagon me-1"></i>Incumplido
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="fw-semibold text-truncate" style="max-width: 520px;">
                                        <?= esc($subject !== '' ? $subject : '(Sin asunto)') ?>
                                    </div>
                                    <div class="small text-muted">
                                        <i class="bi bi-person me-1"></i><?= esc($reqName !== '' ? $reqName : '—') ?>
                                        <span class="mx-2">•</span>
                                        <i class="bi bi-envelope me-1"></i><?= esc($reqEmail !== '' ? $reqEmail : '—') ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="badge <?= esc($statusBadge) ?>">
                                        <?= esc($statusName !== '' ? $statusName : ($statusCode !== '' ? $statusCode : '—')) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-light text-dark border">
                                            <i class="bi bi-person-badge me-1"></i>
                                            <?= esc($assignedTo) ?>
                                        </span>
                                    </div>
                                </td>

                                <td class="small">
                                    <?= esc(fmt_dt($receivedAt)) ?>
                                </td>

                                <td class="text-end">
                                    <span class="badge bg-dark">
                                        <?= (int)$days ?>
                                    </span>
                                </td>

                                <td class="small">
                                    <?= esc($dueAt ? fmt_dt($dueAt) : '—') ?>
                                </td>

                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= esc(url('/cases/' . $caseId)) ?>"
                                       title="Ver detalle">
                                        <i class="bi bi-eye me-1"></i>Ver
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($cases)): ?>
        <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="small text-muted">
                <i class="bi bi-info-circle me-1"></i>
                Semáforo calculado desde <span class="fw-semibold">received_at</span> y tracking (<span class="fw-semibold">case_sla_tracking</span>).
            </div>
            <div class="small text-muted">
                <span class="badge bg-success me-1">VERDE</span> 0–1 días
                <span class="badge bg-warning text-dark ms-2 me-1">AMARILLO</span> 2–3 días
                <span class="badge bg-danger ms-2 me-1">ROJO</span> 4+ días
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const input = document.getElementById('searchInput');
    const clearBtn = document.getElementById('clearBtn');
    const table = document.getElementById('casesTable');
    const shown = document.getElementById('shownCount');

    if (!input || !table) return;

    function norm(s) {
        return (s || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function filter() {
        const q = norm(input.value.trim());
        const rows = table.querySelectorAll('tbody tr');
        let visible = 0;

        rows.forEach(tr => {
            const txt = norm(tr.innerText);
            const ok = q === '' || txt.includes(q);
            tr.style.display = ok ? '' : 'none';
            if (ok) visible++;
        });

        if (shown) shown.textContent = String(visible);
    }

    input.addEventListener('input', filter);
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            input.value = '';
            filter();
            input.focus();
        });
    }

    // foco rápido
    window.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            input.focus();
        }
    });

    filter();
})();
</script>

<style>
/* Mantiene coherencia con dashboard/index.php */
.dashboard-container { padding: 1rem; }
.card { border-radius: 10px; border: 1px solid #e9ecef; }
.table-responsive { border-radius: 10px; }
.case-row td { vertical-align: middle; }
@media print {
    #searchInput, #clearBtn, .btn, .card-footer { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
}
</style>
