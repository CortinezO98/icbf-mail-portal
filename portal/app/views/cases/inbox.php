<?php
declare(strict_types=1);

use App\Auth\Auth;
use App\Auth\Csrf;
use function App\Config\url;

$roleIsSupervisor = Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN');
$status = $status ?? null;

function esc($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function badge_status_class(string $code): string {
  return match (strtoupper($code)) {
    'NUEVO' => 'badge-status--nuevo',
    'ASIGNADO' => 'badge-status--asignado',
    'EN_PROCESO' => 'badge-status--enproceso',
    'RESPONDIDO' => 'badge-status--respondido',
    'CERRADO' => 'badge-status--cerrado',
    default => '',
  };
}

function badge_sla(string $sla): array {
  $sla = strtoupper(trim($sla));
  return match ($sla) {
    'OK' => ['text-bg-success', 'OK'],
    'WARN' => ['text-bg-warning', 'Por vencer'],
    'BREACH' => ['text-bg-danger', 'Vencido'],
    default => ['text-bg-secondary', $sla !== '' ? $sla : '—'],
  };
}

$csrfToken = Csrf::token();
$autoAssignUrl = url('/cases/auto-assign');
?>

<div class="page-title">
  <div>
    <h3>Bandeja</h3>
    <div class="text-muted small">
      <?= $roleIsSupervisor ? 'Vista de supervisión' : 'Vista de agente' ?>
      <?= $status ? '• Filtro: ' . esc($status) : '' ?>
    </div>
  </div>

  <div class="d-flex flex-wrap gap-2">
    <?php if ($roleIsSupervisor): ?>
      <a class="btn btn-outline-brand btn-sm" href="<?= esc(url('/cases?status=NUEVO')) ?>">Nuevos</a>
      <a class="btn btn-outline-brand btn-sm" href="<?= esc(url('/cases?status=ASIGNADO')) ?>">Asignados</a>
      <a class="btn btn-outline-brand btn-sm" href="<?= esc(url('/cases?status=EN_PROCESO')) ?>">En proceso</a>
      <a class="btn btn-outline-brand btn-sm" href="<?= esc(url('/cases?status=RESPONDIDO')) ?>">Respondidos</a>
      <a class="btn btn-outline-secondary btn-sm" href="<?= esc(url('/cases?status=CERRADO')) ?>">Cerrados</a>
    <?php else: ?>
      <a class="btn btn-outline-brand btn-sm" href="<?= esc(url('/cases')) ?>">Mis casos</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($roleIsSupervisor): ?>
  <div class="card mb-3">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <div class="fw-bold">Sin asignar</div>
        <div class="text-muted small">
          Casos <strong>NUEVO</strong> sin agente asignado
        </div>
      </div>

      <div class="d-flex align-items-center gap-2">
        <span class="badge text-bg-secondary" id="unassignedCount">
          <?= (int)($unassignedCount ?? 0) ?>
        </span>

        <button type="button"
                class="btn btn-brand btn-sm"
                id="btnAutoAssign"
                data-url="<?= esc($autoAssignUrl) ?>"
                data-csrf="<?= esc($csrfToken) ?>">
          <i class="bi bi-lightning-charge me-1"></i>Auto-asignar
        </button>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th style="width: 160px;">Caso</th>
          <th>Asunto</th>
          <th style="width: 240px;">Solicitante</th>
          <th style="width: 150px;">Estado</th>
          <th style="width: 120px;">ANS</th>
          <th style="width: 170px;" class="text-nowrap">Recibido</th>
          <th style="width: 170px;" class="text-nowrap">Últ. actividad</th>
        </tr>
      </thead>

      <tbody>
        <?php if (empty($cases)): ?>
          <tr>
            <td colspan="7" class="p-0">
              <div class="empty">
                <i class="bi bi-inbox"></i>
                <div class="fw-semibold mt-2">Sin resultados</div>
                <div class="small">No hay casos para los filtros actuales.</div>
              </div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($cases as $c): ?>
            <?php
              $caseId = (int)($c['id'] ?? 0);
              $caseNumber = (string)($c['case_number'] ?? '');
              $subject = (string)($c['subject'] ?? '');
              $reqName = (string)($c['requester_name'] ?? '');
              $reqEmail = (string)($c['requester_email'] ?? '');

              $statusCode = strtoupper((string)($c['status_code'] ?? ''));
              $statusName = (string)($c['status_name'] ?? $statusCode);

              $statusClass = badge_status_class($statusCode);
              [$slaBadge, $slaLabel] = badge_sla((string)($c['sla_state'] ?? ''));

              $receivedAt = (string)($c['received_at'] ?? '');
              $lastActivity = (string)($c['last_activity_at'] ?? '');

              // ✅ Para que esto funcione, tu SELECT en CasesRepo::listInbox()
              // debe incluir: c.assigned_user_id
              $assignedUserId = $c['assigned_user_id'] ?? null;
            ?>
            <tr>
              <td>
                <a href="<?= esc(url('/cases/' . $caseId)) ?>" class="fw-bold">
                  <?= esc($caseNumber) ?>
                </a>
              </td>

              <td>
                <div class="fw-semibold"><?= esc($subject) ?></div>
                <?php if (!empty($assignedUserId) && $roleIsSupervisor): ?>
                  <div class="text-muted small">
                    <i class="bi bi-person-check me-1"></i>
                    Asignado
                  </div>
                <?php endif; ?>
              </td>

              <td>
                <div class="fw-semibold"><?= esc($reqName) ?></div>
                <div class="text-muted small"><?= esc($reqEmail) ?></div>
              </td>

              <td>
                <span class="badge badge-status <?= esc($statusClass) ?>">
                  <?= esc($statusName ?: $statusCode) ?>
                </span>
              </td>

              <td>
                <span class="badge <?= esc($slaBadge) ?>">
                  <?= esc($slaLabel) ?>
                </span>
              </td>

              <td class="text-nowrap"><?= esc($receivedAt) ?></td>
              <td class="text-nowrap"><?= esc($lastActivity) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- SweetAlert2 (CDN)
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script> -->

<script>
(function () {
  const btn = document.getElementById('btnAutoAssign');
  if (!btn) return;

  btn.addEventListener('click', async function () {
    const url = btn.dataset.url;
    const csrf = btn.dataset.csrf;

    const confirmResult = await Swal.fire({
      title: '¿Auto-asignar casos pendientes?',
      text: 'Se repartirán los casos NUEVOS a los agentes según menor carga.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Sí, auto-asignar',
      cancelButtonText: 'Cancelar'
    });

    if (!confirmResult.isConfirmed) return;

    btn.disabled = true;

    try {
      const form = new FormData();
      form.append('_csrf', csrf);

      const resp = await fetch(url, {
        method: 'POST',
        body: form,
        headers: { 'X-Requested-With': 'fetch' }
      });

      const data = await resp.json().catch(() => ({}));

      // Manejo por HTTP status / ok
      if (!resp.ok || !data.ok) {
        // Caso CSRF típico (419)
        if (resp.status === 419 || data.code === 'CSRF') {
          await Swal.fire({
            title: 'Sesión expirada',
            text: 'Tu sesión o token CSRF expiró. Recarga la página e inténtalo de nuevo.',
            icon: 'warning'
          });
          return;
        }

        await Swal.fire({
          title: 'Error',
          text: (data && (data.message || data.error)) ? (data.message || data.error) : 'No se pudo completar la operación.',
          icon: 'error'
        });
        return;
      }

      const assigned = Number(data.assigned || 0);
      const skipped  = Number(data.skipped || 0);

      // ✅ casos solicitados:
      if (data.code === 'ASSIGNED') {
        await Swal.fire({
          title: 'Auto-asignación completada',
          html: `
            <div class="text-start">
              <div><b>Asignados:</b> ${assigned}</div>
              <div><b>Omitidos:</b> ${skipped}</div>
            </div>
          `,
          icon: 'success'
        });
        window.location.reload();
        return;
      }

      if (data.code === 'NO_PENDING') {
        await Swal.fire({
          title: 'Sin pendientes',
          text: 'No hay casos NUEVOS para asignar en este momento.',
          icon: 'info'
        });
        return;
      }

      if (data.code === 'NO_AGENTS') {
        await Swal.fire({
          title: 'Sin agentes registrados/habilitados',
          text: 'No hay agentes elegibles para asignación. Verifica que existan usuarios con rol AGENTE y estén habilitados.',
          icon: 'warning'
        });
        return;
      }

      // fallback
      await Swal.fire({
        title: 'Proceso finalizado',
        text: data.message || 'Operación terminada.',
        icon: 'info'
      });

    } catch (e) {
      await Swal.fire({
        title: 'Error',
        text: 'Fallo inesperado (red o servidor).',
        icon: 'error'
      });
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>
