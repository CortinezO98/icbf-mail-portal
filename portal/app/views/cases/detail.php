<?php
use App\Auth\Auth;
use App\Auth\Csrf;
use function App\Config\url;

$isSupervisor = Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN');

function safe_message_body(array $m): string {
  $txt = (string)($m['body_text'] ?? '');
  if ($txt !== '') return $txt;

  $html = (string)($m['body_html'] ?? '');
  if ($html !== '') return trim(strip_tags($html));

  return '';
}

function msg_when(array $m): string {
  return (string)($m['received_at'] ?? $m['sent_at'] ?? $m['created_at'] ?? '');
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h3 class="m-0"><?= htmlspecialchars($case['case_number']) ?></h3>
    <div class="text-muted">
      <?= htmlspecialchars($case['status_name'] ?? $case['status_code'] ?? '') ?>
      • ANS: <?= htmlspecialchars($case['sla_state'] ?? '') ?>
      • Recibido: <?= htmlspecialchars($case['received_at'] ?? '') ?>
    </div>
  </div>
  <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(url('/cases')) ?>">Volver</a>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-3">
      <div class="card-header">Detalle</div>
      <div class="card-body">
        <div><strong>Asunto:</strong> <?= htmlspecialchars($case['subject'] ?? '') ?></div>
        <div><strong>Solicitante:</strong> <?= htmlspecialchars($case['requester_name'] ?? '') ?> (<?= htmlspecialchars($case['requester_email'] ?? '') ?>)</div>
        <div><strong>Asignado a:</strong> <?= htmlspecialchars($case['assigned_user_name'] ?? '—') ?></div>
        <div><strong>Vence:</strong> <?= htmlspecialchars($case['due_at'] ?? '—') ?></div>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header">Hilo de mensajes</div>
      <div class="card-body">
        <?php if (empty($messages)): ?>
          <div class="text-muted">Sin mensajes.</div>
        <?php else: ?>
          <?php foreach ($messages as $m): ?>
            <div class="border rounded p-2 mb-2">
              <div class="small text-muted d-flex justify-content-between">
                <div>
                  <strong><?= htmlspecialchars((string)($m['direction'] ?? '')) ?></strong>
                  • <?= htmlspecialchars((string)($m['from_email'] ?? '')) ?>
                </div>
                <div><?= htmlspecialchars(msg_when($m)) ?></div>
              </div>
              <div class="mt-2">
                <?php echo nl2br(htmlspecialchars(safe_message_body($m))); ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Adjuntos</div>
      <div class="card-body">
        <?php if (empty($attachments)): ?>
          <div class="text-muted">Sin adjuntos.</div>
        <?php else: ?>
          <ul class="list-group">
            <?php foreach ($attachments as $a): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                  <div><?= htmlspecialchars($a['filename'] ?? '') ?></div>
                  <div class="text-muted small"><?= htmlspecialchars($a['content_type'] ?? '') ?> • <?= htmlspecialchars((string)($a['size_bytes'] ?? '')) ?> bytes</div>
                </div>
                <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars(url('/attachments/' . $a['id'] . '/download')) ?>">
                  Descargar
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <?php if ($isSupervisor): ?>
      <div class="card mb-3">
        <div class="card-header">Asignar caso</div>
        <div class="card-body">
          <form method="post" action="<?= htmlspecialchars(url('/cases/' . $case['id'] . '/assign')) ?>">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(Csrf::token()) ?>">

            <div class="mb-2">
              <label class="form-label">Agente</label>
              <select class="form-select" name="agent_id" required>
                <option value="">Seleccione...</option>
                <?php foreach ($agents as $ag): ?>
                  <option value="<?= (int)$ag['id'] ?>"><?= htmlspecialchars($ag['full_name'] . ' (' . $ag['username'] . ')') ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <button class="btn btn-primary w-100" type="submit">Asignar</button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">Trazabilidad</div>
      <div class="card-body">
        <?php if (empty($events)): ?>
          <div class="text-muted">Sin eventos.</div>
        <?php else: ?>
          <ul class="list-group">
            <?php foreach ($events as $e): ?>
              <li class="list-group-item">
                <div class="small text-muted"><?= htmlspecialchars((string)($e['created_at'] ?? '')) ?></div>
                <div>
                  <strong><?= htmlspecialchars((string)($e['event_type'] ?? '')) ?></strong>
                  <span class="text-muted small">• <?= htmlspecialchars((string)($e['source'] ?? '')) ?></span>
                </div>
                <div class="small text-muted">Actor: <?= htmlspecialchars((string)($e['actor_name'] ?? '—')) ?></div>
                <?php if (!empty($e['details_json'])): ?>
                  <pre class="mt-2 mb-0 small bg-light p-2 rounded"><?= htmlspecialchars((string)$e['details_json']) ?></pre>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
