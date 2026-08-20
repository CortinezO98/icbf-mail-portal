<?php
declare(strict_types=1);

use function App\Config\url;

$agents = is_array($agents ?? null) ? $agents : [];
$summary = is_array($summary ?? null) ? $summary : [];
$maxActiveCases = max(1, (int)($maxActiveCases ?? 2));
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1">Estado de conexión de asesores</h1>
        <p class="text-muted mb-0">
            Seguimiento operativo de presencia, carga activa y capacidad de asignación.
        </p>
    </div>
    <div class="text-muted small">
        <i class="bi bi-arrow-repeat me-1"></i>
        Actualización automática cada 15 segundos
    </div>
</div>

<div class="row g-3 mb-4" id="presenceKpis">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Agentes disponibles</div>
                <div class="fs-3 fw-bold" data-kpi="available_agents"><?= (int)($summary['available_agents'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Capacidad libre</div>
                <div class="fs-3 fw-bold" data-kpi="available_capacity"><?= (int)($summary['available_capacity'] ?? 0) ?></div>
                <div class="small text-muted">correos</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Bandeja principal</div>
                <div class="fs-3 fw-bold" data-kpi="pending_queue"><?= (int)($summary['pending_queue'] ?? 0) ?></div>
                <div class="small text-muted">sin asignar</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Total agentes</div>
                <div class="fs-3 fw-bold" data-kpi="total_agents"><?= (int)($summary['total_agents'] ?? count($agents)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="h5 mb-0">Asesores</h2>
            <div class="small text-muted">Carga máxima configurada: <?= $maxActiveCases ?> casos activos por asesor</div>
        </div>
        <span class="badge text-bg-light border" id="presenceUpdatedAt">Ahora</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th>Asesor</th>
                <th>Estado</th>
                <th>Tiempo en estado</th>
                <th>Última conexión</th>
                <th class="text-center">Carga</th>
                <th class="text-center">Cupo libre</th>
                <th class="text-center">Asignable</th>
            </tr>
            </thead>
            <tbody id="presenceTableBody">
            <?php foreach ($agents as $agent): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars((string)($agent['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="small text-muted"><?= htmlspecialchars((string)($agent['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                    </td>
                    <td>
                        <span class="d-inline-flex align-items-center gap-2">
                            <span class="rounded-circle d-inline-block" style="width:10px;height:10px;background:<?= htmlspecialchars((string)($agent['effective_color_hex'] ?? '#94a3b8'), ENT_QUOTES, 'UTF-8') ?>"></span>
                            <?= htmlspecialchars((string)($agent['effective_status_name'] ?? 'Desconectado'), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td class="text-muted small" data-status-age="<?= (int)($agent['effective_status_age_seconds'] ?? 0) ?>">—</td>
                    <td class="text-muted small"><?= htmlspecialchars((string)($agent['last_seen_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-center"><strong><?= (int)($agent['active_cases'] ?? 0) ?>/<?= $maxActiveCases ?></strong></td>
                    <td class="text-center"><?= (int)($agent['free_slots'] ?? 0) ?></td>
                    <td class="text-center">
                        <?php if ((int)($agent['is_assignable_now'] ?? 0) === 1): ?>
                            <span class="badge text-bg-success">Sí</span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">No</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
window.addEventListener('DOMContentLoaded', () => {
    const dataUrl = <?= json_encode(url('/agents/status/data')) ?>;
    const maxCases = <?= (int)$maxActiveCases ?>;

    const escapeHtml = (v) => String(v ?? '')
        .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;').replaceAll("'", '&#039;');

    const durationSeconds = (value) => {
        const seconds = Math.max(0, Number(value || 0));
        if (seconds < 60) return `${seconds}s`;
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return `${minutes} min`;
        const hours = Math.floor(minutes / 60);
        const rest = minutes % 60;
        return `${hours}h ${rest}m`;
    };

    const render = (payload) => {
        const summary = payload.summary || {};
        document.querySelectorAll('[data-kpi]').forEach(el => {
            el.textContent = String(summary[el.dataset.kpi] ?? 0);
        });

        const tbody = document.getElementById('presenceTableBody');
        const rows = Array.isArray(payload.agents) ? payload.agents : [];
        tbody.innerHTML = rows.map(a => `
            <tr>
                <td><div class="fw-semibold">${escapeHtml(a.full_name)}</div><div class="small text-muted">${escapeHtml(a.username)}</div></td>
                <td><span class="d-inline-flex align-items-center gap-2"><span class="rounded-circle d-inline-block" style="width:10px;height:10px;background:${escapeHtml(a.effective_color_hex || '#94a3b8')}"></span>${escapeHtml(a.effective_status_name || 'Desconectado')}</span></td>
                <td class="text-muted small">${durationSeconds(a.effective_status_age_seconds)}</td>
                <td class="text-muted small">${escapeHtml(a.last_seen_at || '—')}</td>
                <td class="text-center"><strong>${Number(a.active_cases || 0)}/${maxCases}</strong></td>
                <td class="text-center">${Number(a.free_slots || 0)}</td>
                <td class="text-center">${Number(a.is_assignable_now || 0) === 1 ? '<span class="badge text-bg-success">Sí</span>' : '<span class="badge text-bg-secondary">No</span>'}</td>
            </tr>`).join('');

        document.getElementById('presenceUpdatedAt').textContent = payload.generated_at || 'Ahora';
    };

    const refresh = async () => {
        try {
            const response = await fetch(dataUrl, {headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if (!response.ok) return;
            render(await response.json());
        } catch (_) {}
    };

    setInterval(refresh, 15000);
    document.querySelectorAll('[data-status-age]').forEach(el => {
        el.textContent = durationSeconds(el.dataset.statusAge);
    });
});
</script>
