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

  if (!class_exists('DOMDocument')) {
    // Fallback mínimo si DOM no está disponible
    return trim(strip_tags($html, '<p><br><div><span><b><strong><i><em><u><ul><ol><li><blockquote><hr><table><thead><tbody><tr><td><th><pre><code><h1><h2><h3><h4><h5><h6><a><img>'));
  }

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
    for ($i = $nodes->length - 1; $i >= 0; $i--) {
      $node = $nodes->item($i);
      if ($node && $node->parentNode) {
        $node->parentNode->removeChild($node);
      }
    }
  }

  // 2) Quita atributos peligrosos y protocolos no permitidos
  foreach ($xpath->query('//@*') as $attr) {
    $name = strtolower($attr->nodeName);
    $val  = trim((string)$attr->nodeValue);

    if (str_starts_with($name, 'on')) {
      $attr->ownerElement?->removeAttributeNode($attr);
      continue;
    }

    if (in_array($name, ['href','src'], true)) {
      $low = strtolower($val);

      // Bloquea javascript: y data: (data: es típico XSS)
      if (str_starts_with($low, 'javascript:') || str_starts_with($low, 'data:')) {
        $attr->ownerElement?->removeAttributeNode($attr);
        continue;
      }

      // Permite cid: (inline email). Si NO lo quieres, bórralo aquí también.
      // if (str_starts_with($low, 'cid:')) { ... }
    }

    // Quita estilos inline
    if ($name === 'style') {
      $attr->ownerElement?->removeAttributeNode($attr);
      continue;
    }
  }

  // 3) Allowlist de tags
  $allowed = [
    'a','p','br','div','span','strong','b','em','i','u',
    'ul','ol','li','blockquote','hr',
    'table','thead','tbody','tr','td','th',
    'h1','h2','h3','h4','h5','h6',
    'pre','code',
    'img' // ✅ clave para correos
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

    if ($tag === 'img') {
      // Solo deja src/alt (quita el resto)
      $src = $el->getAttribute('src');
      $alt = $el->getAttribute('alt');

      foreach (iterator_to_array($el->attributes ?? []) as $a) {
        $el->removeAttribute($a->nodeName);
      }

      if ($src !== '') $el->setAttribute('src', $src);
      if ($alt !== '') $el->setAttribute('alt', $alt);

      // Si src quedó vacío, elimina el img
      if (trim($el->getAttribute('src')) === '') {
        $el->parentNode?->removeChild($el);
      }
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
  $txt  = (string)($m['body_text'] ?? '');

  // 1) Si hay HTML
  if (trim($html) !== '') {
    $safe = sanitize_email_html($html);

    if (trim($safe) !== '') {
      return '<div class="email-body email-body--html">' . $safe . '</div>';
    }

    // 🔥 Fallback inteligente: convierte bloques HTML en saltos reales
    $plain = $html;

    // Convertir bloques a saltos
    $plain = preg_replace('/<\/p>/i', "\n\n", $plain);
    $plain = preg_replace('/<br\s*\/?>/i', "\n", $plain);
    $plain = preg_replace('/<\/div>/i', "\n", $plain);
    $plain = preg_replace('/<\/h[1-6]>/i', "\n\n", $plain);
    $plain = preg_replace('/<\/li>/i', "\n", $plain);

    // Quitar lo demás
    $plain = strip_tags($plain);

    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = normalize_text($plain);
    $plain = trim($plain);

    if ($plain !== '') {
      return '<div class="email-body email-body--text">' . esc($plain) . '</div>';
    }
  }

  // 2) Texto plano directo
  $txt = normalize_text($txt);
  if (trim($txt) !== '') {
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
    'OK' => ['badge-sla--ok', 'OK'],
    'WARN' => ['badge-sla--warn', 'Por vencer'],
    'BREACH' => ['badge-sla--breach', 'Vencido'],
    default => ['badge-sla--default', $sla !== '' ? $sla : '—'],
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
/* ===== ESTILOS MODERNOS PARA LA VISTA DEL CASO ===== */
:root {
  --border-light: #e9ecef;
  --text-muted: #6c757d;
  --text-body: #212529;
  --bg-light: #f8f9fa;
  --primary: #0d6efd;
  --success: #198754;
  --warning: #ffc107;
  --danger: #dc3545;
  --secondary: #6c757d;
  --info: #0dcaf0;
}

/* Tarjetas principales */
.card {
  background: #fff;
  border: none;
  border-radius: 1rem;
  box-shadow: 0 4px 12px rgba(0,0,0,0.05);
  transition: box-shadow 0.2s ease;
  margin-bottom: 1.5rem;
  overflow: hidden;
}

.card:hover {
  box-shadow: 0 8px 24px rgba(0,0,0,0.1);
}

.card-header {
  background: #fff;
  border-bottom: 1px solid var(--border-light);
  padding: 1.25rem 1.5rem;
  font-weight: 600;
  color: var(--text-body);
  font-size: 1rem;
  letter-spacing: 0.02em;
}

.card-body {
  padding: 1.5rem;
}

/* Título de página */
.page-title {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
  margin-bottom: 2rem;
}

.page-title h3 {
  font-size: 1.75rem;
  font-weight: 700;
  color: var(--text-body);
  margin: 0;
}

/* Etiquetas de estado */
.badge-status,
.badge-sla--ok,
.badge-sla--warn,
.badge-sla--breach,
.badge-sla--default {
  display: inline-block;
  padding: 0.35rem 1rem;
  font-size: 0.75rem;
  font-weight: 600;
  line-height: 1;
  text-align: center;
  white-space: nowrap;
  vertical-align: baseline;
  border-radius: 2rem;
  letter-spacing: 0.03em;
  text-transform: uppercase;
}

.badge-status--nuevo { background: #e7f5ff; color: #0d6efd; }
.badge-status--asignado { background: #e9ecef; color: #495057; }
.badge-status--enproceso { background: #fff3cd; color: #856404; }
.badge-status--escalated { background: #f8d7da; color: #721c24; }
.badge-status--respondido { background: #d1e7dd; color: #0f5132; }
.badge-status--cerrado { background: #e2d9f3; color: #4a2c8f; }

.badge-sla--ok { background: #d1e7dd; color: #0f5132; }
.badge-sla--warn { background: #fff3cd; color: #856404; }
.badge-sla--breach { background: #f8d7da; color: #721c24; }
.badge-sla--default { background: #e9ecef; color: #495057; }

/* Metadatos del caso */
.case-metadata {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  align-items: center;
  margin-top: 0.5rem;
}

.case-metadata .item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--text-muted);
  font-size: 0.9rem;
}

/* Grid de detalles del caso */
.detail-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1.5rem;
}

.detail-item {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.detail-item .label {
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  color: var(--text-muted);
}

.detail-item .value {
  font-weight: 500;
  color: var(--text-body);
}

/* Hilo de mensajes estilo chat */
.thread {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.msg {
  display: flex;
  flex-direction: column;
  max-width: 90%;
}

.msg--in {
  align-self: flex-start;
}

.msg--out {
  align-self: flex-end;
}

.msg .meta {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.5rem;
  font-size: 0.85rem;
  color: var(--text-muted);
}

.msg .from {
  font-weight: 600;
  color: var(--text-body);
}

.msg .body {
  background: #fff;
  border: 1px solid var(--border-light);
  border-radius: 1.25rem;
  padding: 1.25rem;
  box-shadow: 0 2px 6px rgba(0,0,0,0.02);
}

.msg--in .body {
  background: var(--bg-light);
  border-top-left-radius: 0.25rem;
}

.msg--out .body {
  background: #e7f5ff;
  border-color: #cfe2ff;
  border-top-right-radius: 0.25rem;
}

/* Contenido del correo */
.email-body {
  font-size: 0.95rem;
  line-height: 1.5;
  color: var(--text-body);
}

.email-body--text {
  white-space: pre-wrap;
  word-break: break-word;
  font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
}

.email-body--html img {
  max-width: 100%;
  height: auto;
  border-radius: 0.5rem;
}

.email-body--html table {
  width: 100%;
  border-collapse: collapse;
  margin: 1rem 0;
}

.email-body--html td,
.email-body--html th {
  border: 1px solid var(--border-light);
  padding: 0.75rem;
  vertical-align: top;
}

.email-body--html blockquote {
  border-left: 3px solid var(--border-light);
  padding-left: 1rem;
  margin: 1rem 0;
  color: var(--text-muted);
}

/* Lista de adjuntos */
.attachments-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.attachment-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
  padding: 0.75rem;
  background: var(--bg-light);
  border-radius: 0.75rem;
  border: 1px solid var(--border-light);
}

.attachment-info {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.attachment-name {
  font-weight: 500;
  color: var(--text-body);
}

.attachment-meta {
  font-size: 0.8rem;
  color: var(--text-muted);
}

/* Botones modernos */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  font-size: 0.9rem;
  font-weight: 500;
  line-height: 1.5;
  text-align: center;
  text-decoration: none;
  vertical-align: middle;
  cursor: pointer;
  user-select: none;
  border: 1px solid transparent;
  border-radius: 2rem;
  transition: all 0.15s ease-in-out;
}

.btn-sm {
  padding: 0.25rem 1rem;
  font-size: 0.85rem;
  border-radius: 2rem;
}

.btn-brand {
  background: var(--primary);
  color: white;
  border: none;
}

.btn-brand:hover {
  background: #0b5ed7;
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(13,110,253,0.2);
}

.btn-outline-secondary {
  background: transparent;
  border-color: var(--border-light);
  color: var(--text-body);
}

.btn-outline-secondary:hover {
  background: var(--bg-light);
  border-color: var(--text-muted);
}

.btn-success {
  background: var(--success);
  color: white;
  border: none;
}

.btn-warning {
  background: var(--warning);
  color: #000;
  border: none;
}

.btn-danger {
  background: var(--danger);
  color: white;
  border: none;
}

.btn-primary {
  background: var(--primary);
  color: white;
  border: none;
}

/* Formularios */
.form-label {
  display: block;
  margin-bottom: 0.5rem;
  font-weight: 500;
  color: var(--text-body);
}

.form-control {
  display: block;
  width: 100%;
  padding: 0.5rem 0.75rem;
  font-size: 0.95rem;
  line-height: 1.5;
  color: var(--text-body);
  background: #fff;
  border: 1px solid var(--border-light);
  border-radius: 0.75rem;
  transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
}

.form-control:focus {
  border-color: var(--primary);
  outline: 0;
  box-shadow: 0 0 0 0.25rem rgba(13,110,253,0.1);
}

.form-text {
  margin-top: 0.5rem;
  font-size: 0.8rem;
  color: var(--text-muted);
}

/* Trazabilidad */
.timeline {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.event-item {
  padding: 1rem;
  background: var(--bg-light);
  border-radius: 0.75rem;
  border-left: 4px solid var(--primary);
}

.event-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}

.event-type {
  font-weight: 600;
  color: var(--text-body);
}

.event-source {
  font-size: 0.8rem;
  color: var(--text-muted);
}

.event-meta {
  font-size: 0.8rem;
  color: var(--text-muted);
}

.event-details {
  margin-top: 0.5rem;
  padding: 0.5rem;
  background: #fff;
  border-radius: 0.5rem;
  font-size: 0.85rem;
  white-space: pre-wrap;
  word-break: break-word;
}

/* Estados vacíos */
.empty-state {
  text-align: center;
  padding: 3rem 1rem;
  color: var(--text-muted);
}

.empty-state i {
  font-size: 3rem;
  margin-bottom: 1rem;
  opacity: 0.5;
}

.empty-state .title {
  font-weight: 600;
  margin-bottom: 0.25rem;
  color: var(--text-body);
}

.empty-state .description {
  font-size: 0.9rem;
}
</style>

<div class="page-title">
  <div>
    <h3><?= esc($case['case_number'] ?? '') ?></h3>
    <div class="case-metadata">
      <span class="badge-status <?= esc($statusClass) ?>"><?= esc($statusName ?: $statusCode) ?></span>
      <span class="<?= esc($slaBadge) ?>">ANS: <?= esc($slaLabel) ?></span>
      <span class="item">
        <i class="bi bi-calendar3"></i>
        Recibido: <?= esc($case['received_at'] ?? '—') ?>
      </span>
    </div>
  </div>

  <div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(url('/cases')) ?>">
      <i class="bi bi-arrow-left"></i>
      Volver
    </a>
  </div>
</div>

<div class="row g-4">
  <!-- Columna principal -->
  <div class="col-lg-8">

    <!-- Detalle del caso -->
    <div class="card">
      <div class="card-header">
        <i class="bi bi-info-circle me-2"></i>
        Detalle del caso
        <span class="ms-auto text-muted small">
          Vence: <?= esc($case['due_at'] ?? '—') ?>
        </span>
      </div>

      <div class="card-body">
        <div class="detail-grid">
          <div class="detail-item">
            <span class="label">Asunto</span>
            <span class="value"><?= esc($case['subject'] ?? '') ?></span>
          </div>

          <div class="detail-item">
            <span class="label">Solicitante</span>
            <span class="value"><?= esc($case['requester_name'] ?? '—') ?></span>
            <span class="text-muted small"><?= esc($case['requester_email'] ?? '') ?></span>
          </div>

          <div class="detail-item">
            <span class="label">Asignado a</span>
            <span class="value"><?= esc($case['assigned_user_name'] ?? '—') ?></span>
            <?php if (!empty($case['assigned_at'])): ?>
              <span class="text-muted small">Asignado: <?= esc($case['assigned_at']) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Hilo de mensajes -->
    <div class="card">
      <div class="card-header">
        <i class="bi bi-chat-dots me-2"></i>
        Hilo de mensajes
        <span class="ms-auto text-muted small">
          <?= !empty($messages) ? count($messages) . ' mensaje(s)' : '—' ?>
        </span>
      </div>

      <div class="card-body">
        <?php if (empty($messages)): ?>
          <div class="empty-state">
            <i class="bi bi-chat-left-text"></i>
            <div class="title">Sin mensajes</div>
            <div class="description">Aún no hay correos registrados en el caso.</div>
          </div>
        <?php else: ?>
          <div class="thread">
            <?php foreach ($messages as $m): ?>
              <?php
                $dir  = strtoupper((string)($m['direction'] ?? ''));
                $from = (string)($m['from_email'] ?? '');
                $when = msg_when($m);
                $isOutbound = $dir === 'OUT';
              ?>

              <div class="msg <?= $isOutbound ? 'msg--out' : 'msg--in' ?>">
                <div class="meta">
                  <span class="from"><?= esc($from) ?></span>
                  <span><?= esc($when) ?></span>
                </div>

                <div class="body">
                  <?= render_message_body($m) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Adjuntos -->
    <div class="card">
      <div class="card-header">
        <i class="bi bi-paperclip me-2"></i>
        Adjuntos
      </div>

      <div class="card-body">
        <?php if (empty($attachments)): ?>
          <div class="empty-state">
            <i class="bi bi-paperclip"></i>
            <div class="title">Sin adjuntos</div>
            <div class="description">Este caso no tiene archivos adjuntos.</div>
          </div>
        <?php else: ?>
          <div class="attachments-list">
            <?php foreach ($attachments as $a): ?>
              <?php
                $filename = (string)($a['filename'] ?? '');
                $ctype    = (string)($a['content_type'] ?? '');
                $size     = (string)($a['size_bytes'] ?? '');
                $attId    = (int)($a['id'] ?? 0);
              ?>
              <div class="attachment-item">
                <div class="attachment-info">
                  <span class="attachment-name">
                    <i class="bi bi-file-earmark me-2"></i>
                    <?= esc($filename) ?>
                  </span>
                  <span class="attachment-meta">
                    <?= esc($ctype) ?> • <?= esc($size) ?> bytes
                  </span>
                </div>

                <a class="btn btn-brand btn-sm" href="<?= esc(url('/attachments/' . $attId . '/download')) ?>">
                  <i class="bi bi-download"></i>
                  Descargar
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
      <div class="card">
        <div class="card-header">
          <i class="bi bi-lightning-charge me-2"></i>
          Acciones del caso
        </div>

        <div class="card-body d-flex flex-column gap-3">
          <?php if ($showStart): ?>
            <form method="POST" action="<?= esc(url('/cases/' . $caseId . '/start')) ?>">
              <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">
              <button class="btn btn-success w-100"
                      type="submit"
                      data-confirm="true"
                      data-confirm-title="Iniciar gestión"
                      data-confirm-text="Se marcará el inicio de gestión para métricas ANS. ¿Continuar?">
                <i class="bi bi-play-circle"></i>
                Iniciar gestión
              </button>
              <div class="form-text">
                Habilita el flujo de gestión y registra la métrica.
              </div>
            </form>
          <?php endif; ?>

          <?php if ($showInProc): ?>
            <form method="POST" action="<?= esc(url('/cases/' . $caseId . '/escalate')) ?>">
              <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">

              <div class="mb-3">
                <label class="form-label">
                  Observación de escalamiento <span class="text-danger">*</span>
                </label>
                <textarea
                  name="escalated_note"
                  class="form-control"
                  rows="3"
                  required
                  maxlength="2000"
                  placeholder="¿Qué necesitas? ¿A quién escalas? ¿Por qué?"></textarea>
              </div>

              <button class="btn btn-warning w-100 mb-3"
                      type="submit"
                      data-confirm="true"
                      data-confirm-title="Escalar"
                      data-confirm-text="Se registrará el escalamiento en la trazabilidad. ¿Continuar?">
                <i class="bi bi-arrow-up-right-circle"></i>
                Escalar
              </button>
            </form>

            <form method="POST" action="<?= esc(url('/cases/' . $caseId . '/finish')) ?>">
              <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">
              <button class="btn btn-primary w-100"
                      type="submit"
                      data-confirm="true"
                      data-confirm-title="Finalizar gestión"
                      data-confirm-text="Se marcará el caso como RESPONDIDO. ¿Continuar?">
                <i class="bi bi-check2-circle"></i>
                Finalizar gestión
              </button>
              <div class="form-text">
                El caso pasa a RESPONDIDO y se registra la métrica de primera respuesta.
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
                <i class="bi bi-arrow-counterclockwise"></i>
                Finalizar escalamiento
              </button>

              <div class="form-text">
                Finaliza ESCALATED y devuelve el caso a EN_PROCESO.
              </div>
            </form>
          <?php endif; ?>

          <?php if ($showClose): ?>
            <form method="POST" action="<?= esc(url('/cases/' . $caseId . '/close')) ?>">
              <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">

              <div class="mb-3">
                <label class="form-label">
                  Radicado <span class="text-danger">*</span>
                </label>
                <input
                  name="closed_ticket"
                  class="form-control"
                  required
                  maxlength="60"
                  placeholder="Ej: 123456 o ICBF-2026-000123">
              </div>

              <div class="mb-3">
                <label class="form-label">
                  Observación de cierre <span class="text-danger">*</span>
                </label>
                <textarea
                  name="closed_note"
                  class="form-control"
                  rows="3"
                  required
                  maxlength="4000"
                  placeholder="¿Qué se hizo? Respuesta final, evidencia..."></textarea>
              </div>

              <button class="btn btn-danger w-100"
                      type="submit"
                      data-confirm="true"
                      data-confirm-title="Cerrar caso"
                      data-confirm-text="El caso quedará en estado CERRADO. ¿Continuar?">
                <i class="bi bi-lock-fill"></i>
                Cerrar caso
              </button>
            </form>
          <?php endif; ?>

          <?php if (!$showStart && !$showInProc && !$showEscFinish && !$showClose): ?>
            <div class="text-muted small text-center">
              No hay acciones disponibles para tu perfil en el estado actual.
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- ASIGNAR CASO (SOLO SUPERVISOR/ADMIN) -->
    <?php if ($isSupervisor): ?>
      <div class="card">
        <div class="card-header">
          <i class="bi bi-person-plus me-2"></i>
          Asignar caso
        </div>
        <div class="card-body">
          <form method="post" action="<?= esc(url('/cases/' . $caseId . '/assign')) ?>">
            <input type="hidden" name="_csrf" value="<?= esc(Csrf::token()) ?>">

            <div class="mb-3">
              <label class="form-label">Agente</label>
              <select class="form-select" name="agent_id" required>
                <option value="">Seleccione un agente...</option>
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
              <i class="bi bi-person-check"></i>
              Asignar
            </button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <!-- Trazabilidad -->
    <div class="card">
      <div class="card-header">
        <i class="bi bi-clock-history me-2"></i>
        Trazabilidad
      </div>
      <div class="card-body">
        <?php if (empty($events)): ?>
          <div class="empty-state">
            <i class="bi bi-clock-history"></i>
            <div class="title">Sin eventos</div>
            <div class="description">Aún no hay cambios registrados para este caso.</div>
          </div>
        <?php else: ?>
          <div class="timeline">
            <?php foreach ($events as $e): ?>
              <?php
                $created = (string)($e['created_at'] ?? '');
                $type    = (string)($e['event_type'] ?? '');
                $source  = (string)($e['source'] ?? '');
                $actor   = (string)($e['actor_name'] ?? '—');
                $details = (string)($e['details_json'] ?? '');
              ?>
              <div class="event-item">
                <div class="event-header">
                  <span class="event-type"><?= esc($type) ?></span>
                  <span class="event-source"><?= esc($source) ?></span>
                </div>
                <div class="event-meta">
                  <?= esc($created) ?> • Actor: <?= esc($actor) ?>
                </div>
                <?php if ($details !== ''): ?>
                  <div class="event-details">
                    <?= esc($details) ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>