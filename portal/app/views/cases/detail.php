<?php
declare(strict_types=1);

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

$statusCode = strtoupper((string)($case['status_code'] ?? ''));
$statusName = (string)($case['status_name'] ?? $statusCode);
$statusClass = badge_status_class($statusCode);

[$slaBadge, $slaLabel] = badge_sla((string)($case['sla_state'] ?? ''));

$caseId = (int)($case['id'] ?? 0);
?>
<div class="page-title">
  <div>
    <h3 class="m-0"><?= esc($case['case_number'] ?? '') ?></h3>
    <div class="text-muted small d-flex flex-wrap gap-2 align-items-center mt-1">
      <span class="badge badge-status <?= esc($statusClass) ?>"><?= esc($statusName ?: $statusCode) ?></span>
      <span class="badge <?= esc($slaBadge) ?>">ANS: <?= esc($slaLabel) ?></span>
      <span class="text-muted">• Recibido: <?= esc($case['received_at'] ?? '—') ?></span>
    </div>
  </div>

  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(url('/cases')) ?>">
      <i class="bi bi-arrow-left me-1"></i>Volver
    </a>
  </div>
</div>

<div class="row g-3">
  <!-- Columna principal -->
  <div class="col-lg-8">

    <!-- Detalle -->
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Detalle</span>
        <span class="text-muted small">
          Vence: <?= esc($case['due_at'] ?? '—') ?>
        </span>
      </div>

      <div class="card-body">
        <div class="row g-3">
          <div class="col-12">
            <div class="text-muted small">Asunto</div>
            <div class="fw-semibold"><?= esc($case['subject'] ?? '') ?></div>
          </div>

          <div class="col-md-6">
            <div class="text-muted small">Solicitante</div>
            <div class="fw-semibold"><?= esc($case['requester_name'] ?? '—') ?></div>
            <div class="text-muted small"><?= esc($case['requester_email'] ?? '') ?></div>
          </div>

          <div class="col-md-6">
            <div class="text-muted small">Asignado a</div>
            <div class="fw-semibold"><?= esc($case['assigned_user_name'] ?? '—') ?></div>
            <?php if (!empty($case['assigned_at'])): ?>
              <div class="text-muted small">Asignado: <?= esc($case['assigned_at']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Hilo de mensajes -->
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Hilo de mensajes</span>
        <span class="text-muted small">
          <?= !empty($messages) ? count($messages) . ' mensaje(s)' : '—' ?>
        </span>
      </div>

      <div class="card-body">
        <?php if (empty($messages)): ?>
          <div class="empty">
            <i class="bi bi-chat-left-text"></i>
            <div class="fw-semibold mt-2">Sin mensajes</div>
            <div class="small">Aún no hay correos registrados en el caso.</div>
          </div>
        <?php else: ?>
          <?php foreach ($messages as $m): ?>
            <?php
              $dir = strtoupper((string)($m['direction'] ?? ''));
              $from = (string)($m['from_email'] ?? '');
              $when = msg_when($m);
              $body = safe_message_body($m);

              $dirBadge = $dir === 'OUT' ? 'text-bg-secondary' : 'text-bg-primary';
              $dirLabel = $dir !== '' ? $dir : 'MSG';
            ?>

            <div class="msg mb-2">
              <div class="meta">
                <div class="d-flex flex-wrap gap-2 align-items-center">
                  <span class="badge <?= esc($dirBadge) ?>"><?= esc($dirLabel) ?></span>
                  <span class="fw-semibold"><?= esc($from) ?></span>
                </div>
                <div><?= esc($when) ?></div>
              </div>

              <div class="body">
                <?= nl2br(esc($body)) ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Adjuntos -->
    <div class="card">
      <div class="card-header">Adjuntos</div>

      <div class="card-body">
        <?php if (empty($attachments)): ?>
          <div class="empty">
            <i class="bi bi-paperclip"></i>
            <div class="fw-semibold mt-2">Sin adjuntos</div>
            <div class="small">Este caso no tiene archivos adjuntos.</div>
          </div>
        <?php else: ?>
          <div class="list-group">
            <?php foreach ($attachments as $a): ?>
              <?php
                $filename = (string)($a['filename'] ?? '');
                $ctype = (string)($a['content_type'] ?? '');
                $size = (string)($a['size_bytes'] ?? '');
                $attId = (int)($a['id'] ?? 0);
              ?>
              <div class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                  <div class="fw-semibold"><?= esc($filename) ?></div>
                  <div class="text-muted small">
                    <?= esc($ctype) ?> • <?= esc($size) ?> bytes
                  </div>
                </div>

                <a class="btn btn-brand btn-sm" href="<?= esc(url('/attachments/' . $attId . '/download')) ?>">
                  <i class="bi bi-download me-1"></i>Descargar
                </a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Sidebar -->
  <div class="col-lg-4">

    <?php if ($isSupervisor): ?>
      <div class="card mb-3">
        <div class="card-header">Asignar caso</div>
        <div class="card-body">
          <form method="post" action="<?= esc(url('/cases/' . $caseId . '/assign')) ?>">
            <input type="hidden" name="_csrf" value="<?= esc(Csrf::token()) ?>">

            <div class="mb-2">
              <label class="form-label">Agente</label>
              <select class="form-select" name="agent_id" required>
                <option value="">Seleccione...</option>
                <?php foreach ($agents as $ag): ?>
                  <?php
                    $agId = (int)($ag['id'] ?? 0);
                    $agName = (string)($ag['full_name'] ?? '');
                    $agUser = (string)($ag['username'] ?? '');
                  ?>
                  <option value="<?= $agId ?>">
                    <?= esc($agName . ' (' . $agUser . ')') ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">
                Solo supervisores/administradores pueden asignar casos.
              </div>
            </div>

            <button class="btn btn-brand w-100" type="submit">
              <i class="bi bi-person-check me-1"></i>Asignar
            </button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <!-- Trazabilidad -->
    <div class="card">
      <div class="card-header">Trazabilidad</div>
      <div class="card-body">
        <?php if (empty($events)): ?>
          <div class="empty">
            <i class="bi bi-clock-history"></i>
            <div class="fw-semibold mt-2">Sin eventos</div>
            <div class="small">Aún no hay cambios registrados para este caso.</div>
          </div>
        <?php else: ?>
          <?php foreach ($events as $e): ?>
            <?php
              $created = (string)($e['created_at'] ?? '');
              $type = (string)($e['event_type'] ?? '');
              $source = (string)($e['source'] ?? '');
              $actor = (string)($e['actor_name'] ?? '—');
              $details = (string)($e['details_json'] ?? '');
            ?>
            <div class="event-item mb-2">
              <div class="small text-muted"><?= esc($created) ?></div>
              <div class="d-flex flex-wrap gap-2 align-items-center">
                <strong><?= esc($type) ?></strong>
                <span class="text-muted small">• <?= esc($source) ?></span>
              </div>
              <div class="small text-muted">Actor: <?= esc($actor) ?></div>

              <?php if ($details !== ''): ?>
                <pre class="mt-2 mb-0 small"><?= esc($details) ?></pre>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>
