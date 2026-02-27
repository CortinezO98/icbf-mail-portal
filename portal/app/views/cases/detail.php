<?php
declare(strict_types=1);

use App\Auth\Auth;
use App\Auth\Csrf;
use function App\Config\url;

$isSupervisor = Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN');

// Vars seguras
$case   = $case ?? [];
$flash  = $flash ?? null; // ya no se usa aquí (lo maneja layout)
$_csrf  = $_csrf ?? '';

// -----------------------------
// Render correcto de correos
// -----------------------------
function normalize_text(string $s): string {
  // Decodifica entidades y normaliza saltos
  $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $s = str_replace(["\r\n", "\r"], "\n", $s);
  return $s;
}

function sanitize_email_html(string $html): string {
  $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $html = trim($html);
  if ($html === '') return '';

  $dom = new DOMDocument();
  libxml_use_internal_errors(true);

  $dom->loadHTML(
    '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
    LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
  );

  libxml_clear_errors();
  libxml_use_internal_errors(false);

  $xpath = new DOMXPath($dom);

  // 1) Elimina tags peligrosos
  foreach (['script','style','iframe','object','embed','link','meta','base'] as $tag) {
    $nodes = $dom->getElementsByTagName($tag);
    // NodeList "vivo": remover al revés
    for ($i = $nodes->length - 1; $i >= 0; $i--) {
      $node = $nodes->item($i);
      if ($node && $node->parentNode) {
        $node->parentNode->removeChild($node);
      }
    }
  }

  // 2) Quita atributos peligrosos (onload, onclick, etc) y javascript:
  foreach ($xpath->query('//@*') as $attr) {
    $name = strtolower($attr->nodeName);
    $val  = trim((string)$attr->nodeValue);

    // Eventos inline (on*)
    if (str_starts_with($name, 'on')) {
      $attr->ownerElement?->removeAttributeNode($attr);
      continue;
    }

    // Bloquea javascript: en href/src
    if (in_array($name, ['href','src'], true)) {
      $low = strtolower($val);
      if (str_starts_with($low, 'javascript:') || str_starts_with($low, 'data:')) {
        $attr->ownerElement?->removeAttributeNode($attr);
        continue;
      }
    }

    // Quita estilos inline para no dañar UI
    if ($name === 'style') {
      $attr->ownerElement?->removeAttributeNode($attr);
      continue;
    }
  }

  // 3) Allowlist de tags (lo demás lo aplana a texto)
  $allowed = [
    'a','p','br','div','span','strong','b','em','i','u',
    'ul','ol','li','blockquote','hr',
    'table','thead','tbody','tr','td','th',
    'h1','h2','h3','h4','h5','h6',
    'pre','code'
  ];

  $all = $dom->getElementsByTagName('*');
  for ($i = $all->length - 1; $i >= 0; $i--) {
    $el = $all->item($i);
    if (!$el) continue;

    $tag = strtolower($el->tagName);
    if (!in_array($tag, $allowed, true)) {
      $text = $dom->createTextNode($el->textContent ?? '');
      $el->parentNode?->replaceChild($text, $el);
      continue;
    }

    if ($tag === 'a') {
      $el->setAttribute('target', '_blank');
      $el->setAttribute('rel', 'noopener noreferrer');
    }
  }

  $body = $dom->getElementsByTagName('body')->item(0);
  if (!$body) return '';

  $out = '';
  foreach ($body->childNodes as $child) {
    $out .= $dom->saveHTML($child);
  }
  return trim($out);
}

function render_message_body(array $m): string {
  $html = (string)($m['body_html'] ?? '');
  if ($html !== '') {
    $safe = sanitize_email_html($html);
    if ($safe !== '') {
      return '<div class="email-body email-body--html">' . $safe . '</div>';
    }
  }

  $txt = (string)($m['body_text'] ?? '');
  $txt = normalize_text($txt);
  if ($txt !== '') {
    return '<div class="email-body email-body--text">' . esc($txt) . '</div>';
  }

  return '<div class="email-body text-muted small">—</div>';
}

function msg_when(array $m): string {
  return (string)($m['received_at'] ?? $m['sent_at'] ?? $m['created_at'] ?? '');
}

function badge_status_class(string $code): string {
  return match (strtoupper($code)) {
    'NUEVO' => 'badge-status--nuevo',
    'ASIGNADO' => 'badge-status--asignado',
    'EN_PROCESO' => 'badge-status--enproceso',
    'ESCALATED', 'ESCALADO' => 'badge-status--escalated',
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

$statusCode  = strtoupper((string)($case['status_code'] ?? ''));
$statusName  = (string)($case['status_name'] ?? $statusCode);
$statusClass = badge_status_class($statusCode);

[$slaBadge, $slaLabel] = badge_sla((string)($case['sla_state'] ?? ''));

$caseId = (int)($case['id'] ?? 0);

$isAssignedAgent = Auth::check()
  && Auth::hasRole('AGENTE')
  && !Auth::hasRole('SUPERVISOR')
  && !Auth::hasRole('ADMIN')
  && ((int)($case['assigned_user_id'] ?? 0) === (int)Auth::id());

$isCaseOwner = Auth::check() && ((int)($case['assigned_user_id'] ?? 0) === (int)Auth::id());
$canAgentFlowActions = $isAssignedAgent;

// Ayudas UI por estado
$showStart      = $canAgentFlowActions && $statusCode === 'ASIGNADO';
$showInProc     = $canAgentFlowActions && $statusCode === 'EN_PROCESO';
$showEscFinish  = $canAgentFlowActions && $statusCode === 'ESCALATED';
$showClose      = $canAgentFlowActions && $statusCode === 'RESPONDIDO';

?>

<style>
/* ---- estilos del cuerpo de correo (NO rompen nada) ---- */
.msg .body {
  background: #fff;
  border: 1px solid rgba(0,0,0,.08);
  border-radius: 10px;
  padding: 12px 14px;
  overflow: auto;
}

.email-body {
  font-size: 0.95rem;
  line-height: 1.35rem;
  color: #1f2937;
}

/* Texto plano: respeta espacios y saltos */
.email-body--text {
  white-space: pre-wrap;
  word-break: break-word;
}

/* HTML: que no reviente el layout */
.email-body--html img {
  max-width: 100%;
  height: auto;
}

.email-body--html table {
  width: 100%;
  border-collapse: collapse;
}

.email-body--html td,
.email-body--html th {
  border: 1px solid rgba(0,0,0,.12);
  padding: 6px 8px;
  vertical-align: top;
}

.email-body--html blockquote {
  border-left: 3px solid rgba(0,0,0,.15);
  padding-left: 10px;
  margin-left: 0;
  color: #374151;
}
</style>

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
              $dir  = strtoupper((string)($m['direction'] ?? ''));
              $from = (string)($m['from_email'] ?? '');
              $when = msg_when($m);

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
                <?= render_message_body($m) ?>
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
                $ctype    = (string)($a['content_type'] ?? '');
                $size     = (string)($a['size_bytes'] ?? '');
                $attId    = (int)($a['id'] ?? 0);
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
    <!-- ACCIONES DEL CASO (SOLO AGENTE ASIGNADO) -->
    <?php if ($canAgentFlowActions): ?>
      <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold">
          Acciones del caso
        </div>

        <div class="card-body">
          <?php if ($showStart): ?>
            <form method="POST" action="<?= esc(url('/cases/' . $caseId . '/start')) ?>">
              <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">
              <button class="btn btn-success w-100"
                      type="submit"
                      data-confirm="true"
                      data-confirm-title="Iniciar gestión"
                      data-confirm-text="Se marcará el inicio de gestión para métricas ANS. ¿Continuar?">
                <i class="bi bi-play-circle me-1"></i>Iniciar gestión
              </button>
              <div class="form-text mt-2">
                Este botón habilita el flujo de gestión (escalar / finalizar) y registra la métrica.
              </div>
            </form>
          <?php endif; ?>

          <?php if ($showInProc): ?>
            <form method="POST" action="<?= esc(url('/cases/' . $caseId . '/escalate')) ?>" class="mb-3">
              <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">

              <label class="form-label">
                Observación de escalamiento <span class="text-danger">*</span>
              </label>
              <textarea
                name="escalated_note"
                class="form-control"
                rows="3"
                required
                maxlength="2000"
                placeholder="Qué necesitas, a quién escalas y por qué..."></textarea>

              <button class="btn btn-warning w-100 mt-2"
                      type="submit"
                      data-confirm="true"
                      data-confirm-title="Escalar"
                      data-confirm-text="Se registrará el escalamiento en la trazabilidad. ¿Continuar?">
                <i class="bi bi-arrow-up-right-circle me-1"></i>Escalar
              </button>
            </form>

            <hr>

            <form method="POST" action="<?= esc(url('/cases/' . $caseId . '/finish')) ?>">
              <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">
              <button class="btn btn-primary w-100"
                      type="submit"
                      data-confirm="true"
                      data-confirm-title="Finalizar gestión"
                      data-confirm-text="Se marcará el caso como RESPONDIDO. ¿Continuar?">
                <i class="bi bi-check2-circle me-1"></i>Finalizar gestión
              </button>
              <div class="form-text mt-2">
                Al finalizar, el caso pasa a RESPONDIDO y se registra la métrica de primera respuesta.
              </div>
            </form>
          <?php endif; ?>

          <?php if ($showEscFinish): ?>
            <form method="POST" action="<?= esc(url('/cases/' . $caseId . '/finish-escalation')) ?>">
              <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">

              <button class="btn btn-primary w-100"
                      type="submit"
                      data-confirm="true"
                      data-confirm-title="Finalizar escalamiento"
                      data-confirm-text="El caso volverá a EN_PROCESO para continuar la gestión. ¿Continuar?">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Finalizar escalamiento
              </button>

              <div class="form-text mt-2">
                Finaliza el estado ESCALATED y devuelve el caso a EN_PROCESO para continuar con la gestión.
              </div>
            </form>
          <?php endif; ?>

          <?php if ($showClose): ?>
            <form method="POST" action="<?= esc(url('/cases/' . $caseId . '/close')) ?>">
              <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">

              <label class="form-label">
                Radicado <span class="text-danger">*</span>
              </label>
              <input
                name="closed_ticket"
                class="form-control"
                required
                maxlength="60"
                placeholder="Ej: 123456 o ICBF-2026-000123">

              <label class="form-label mt-2">
                Observación de cierre <span class="text-danger">*</span>
              </label>
              <textarea
                name="closed_note"
                class="form-control"
                rows="3"
                required
                maxlength="4000"
                placeholder="Qué se hizo, respuesta final, evidencia..."></textarea>

              <button class="btn btn-danger w-100 mt-3"
                      type="submit"
                      data-confirm="true"
                      data-confirm-title="Cerrar caso"
                      data-confirm-text="El caso quedará en estado CERRADO. ¿Continuar?">
                <i class="bi bi-lock-fill me-1"></i>Cerrar caso
              </button>
            </form>
          <?php endif; ?>

          <?php if (!$showStart && !$showInProc && !$showEscFinish && !$showClose): ?>
            <div class="text-muted small">
              No hay acciones disponibles para tu perfil en el estado actual.
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- ASIGNAR CASO (SOLO SUPERVISOR/ADMIN) -->
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
                    $agId   = (int)($ag['id'] ?? 0);
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
              $type    = (string)($e['event_type'] ?? '');
              $source  = (string)($e['source'] ?? '');
              $actor   = (string)($e['actor_name'] ?? '—');
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