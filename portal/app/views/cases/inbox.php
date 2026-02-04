<?php
use App\Auth\Auth;
use function App\Config\url;

$roleIsSupervisor = Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="m-0">Bandeja</h3>

  <div class="d-flex gap-2">
    <?php if ($roleIsSupervisor): ?>
      <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(url('/cases?status=NUEVO')) ?>">Nuevos</a>
      <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(url('/cases?status=ASIGNADO')) ?>">Asignados</a>
      <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(url('/cases?status=EN_PROCESO')) ?>">En proceso</a>
      <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(url('/cases?status=RESPONDIDO')) ?>">Respondidos</a>
      <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(url('/cases?status=CERRADO')) ?>">Cerrados</a>
    <?php else: ?>
      <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(url('/cases')) ?>">Mis casos</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Caso</th>
          <th>Asunto</th>
          <th>Solicitante</th>
          <th>Estado</th>
          <th>ANS</th>
          <th>Recibido</th>
          <th>Últ. actividad</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($cases)): ?>
        <tr><td colspan="7" class="text-center text-muted p-4">Sin resultados</td></tr>
      <?php else: ?>
        <?php foreach ($cases as $c): ?>
          <tr>
            <td>
              <a href="<?= htmlspecialchars(url('/cases/' . $c['id'])) ?>">
                <?= htmlspecialchars($c['case_number']) ?>
              </a>
            </td>
            <td><?= htmlspecialchars($c['subject'] ?? '') ?></td>
            <td>
              <?= htmlspecialchars($c['requester_name'] ?? '') ?><br>
              <span class="text-muted small"><?= htmlspecialchars($c['requester_email'] ?? '') ?></span>
            </td>
            <td><?= htmlspecialchars($c['status_name'] ?? $c['status_code'] ?? '') ?></td>
            <td><?= htmlspecialchars($c['sla_state'] ?? '') ?></td>
            <td class="text-nowrap"><?= htmlspecialchars($c['received_at'] ?? '') ?></td>
            <td class="text-nowrap"><?= htmlspecialchars($c['last_activity_at'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
