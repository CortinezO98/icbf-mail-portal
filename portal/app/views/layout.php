<?php
declare(strict_types=1);

use App\Auth\Auth;
use App\Auth\Csrf;
use function App\Config\url;
use function App\Config\load_config;

require_once __DIR__ . '/_helpers.php';

$layoutConfig = load_config();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$authPaths = [
    '/login',
    '/forgot-password',
    '/reset-password',
];

$isAuthPage = in_array($path, $authPaths, true);

$normRole = static fn(string $r): string => strtoupper(trim($r));

$user = Auth::user() ?? [];
$fullName = (string)($user['full_name'] ?? $user['username'] ?? '');

$userRoles = [];
if (Auth::check()) {
    $userRoles = Auth::roles() ?: [];
    $userRoles = array_values(array_unique(array_filter(array_map($normRole, $userRoles))));
    if (empty($userRoles) && isset($pdo) && $pdo instanceof PDO && !empty($user['id'])) {
        try {
            $sql = "
                SELECT r.code
                FROM user_roles ur
                JOIN roles r ON r.id = ur.role_id
                WHERE ur.user_id = :uid
                ORDER BY r.code
            ";
            $st = $pdo->prepare($sql);
            $st->execute([':uid' => (int)$user['id']]);
            $userRoles = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $userRoles = array_values(array_unique(array_filter(array_map($normRole, $userRoles))));
            $_SESSION['user']['roles'] = $userRoles;
        } catch (\Throwable $e) {
            $userRoles = [];
        }
    }

    if (empty($userRoles)) {
        $maybe = $user['roles'] ?? $user['roles_label'] ?? null;
        if (is_string($maybe) && trim($maybe) !== '') {
            $tmp = preg_split('/[,;|]/', $maybe) ?: [];
            $userRoles = array_values(array_unique(array_filter(array_map($normRole, $tmp))));
            $_SESSION['user']['roles'] = $userRoles;
        }
    }
}

$userRolesLabel = $userRoles ? implode(', ', $userRoles) : '';
$hasRole = static fn(string $code): bool => in_array($normRole($code), $userRoles, true);

$roleIsSupervisor = $hasRole('SUPERVISOR') || $hasRole('ADMIN');
$roleIsAgent      = $hasRole('AGENTE') || $hasRole('AGENT');

$enableSemaforoRoutes = false;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Sistema de gestión de correspondencia ICBF">
    <title>ICBF - Portal de Correos</title>
    <link rel="icon" type="image/png" href="<?= esc(url('/assets/img/logo_icbf.png')) ?>">
    <link rel="apple-touch-icon" href="<?= esc(url('/assets/img/logo_icbf.png')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"
          rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link href="<?= esc(url('/assets/css/app.css?v=2')) ?>" rel="stylesheet">

    <style>
        :root {
            --color-primary: #4CAF50;
            --color-primary-dark: #3f9c44;
            --color-primary-soft: rgba(76, 175, 80, 0.12);
        }

        .navbar-brand { letter-spacing: 0.2px; }

        .nav-link.active {
            font-weight: 600;
            border-bottom: 2px solid rgba(255, 255, 255, 0.85);
            padding-bottom: 0.35rem;
        }

        .badge-role {
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.25);
            font-size: 0.75rem;
        }

        .app-shell {
            min-height: calc(100vh - 56px);
            background: linear-gradient(180deg, #f8f9fa 0%, #ffffff 220px);
        }

        .nav-link:focus-visible {
            outline: 2px solid #ffffff;
            outline-offset: 2px;
        }

        button:focus-visible,
        a:focus-visible {
            outline: 2px solid var(--color-primary-dark);
            outline-offset: 2px;
        }

        .swal2-html-container code{
            padding: .15rem .35rem;
            border-radius: .35rem;
            background: rgba(0,0,0,.06);
        }

        .agent-presence-toggle {
            min-width: 170px;
            justify-content: flex-start;
            border-color: rgba(255,255,255,.45);
        }
        .agent-presence-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            flex: 0 0 auto;
        }
        .agent-presence-menu .dropdown-item {
            display: flex;
            align-items: center;
            gap: .65rem;
            min-width: 210px;
        }
    </style>
</head>

<body class="bg-light <?= $isAuthPage ? 'page-login' : 'page-app' ?>">
<?php if (!$isAuthPage): ?>
    <nav class="navbar navbar-expand-lg navbar-dark"
         style="background-color: var(--color-primary);"
         aria-label="Navegación principal">
        <div class="container-fluid">
            <a class="navbar-brand fw-semibold d-flex align-items-center gap-2"
               href="<?= esc(url('/cases')) ?>"
               aria-label="ICBF Mail - Inicio">
                <i class="bi bi-envelope-paper" aria-hidden="true"></i>
                <span>ICBF Mail</span>
            </a>

            <button class="navbar-toggler"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#mainNavbar"
                    aria-controls="mainNavbar"
                    aria-expanded="false"
                    aria-label="Alternar navegación">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="mainNavbar">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?= is_active_prefix($path, '/cases') && $path !== '/cases/by-agent' ? 'active' : '' ?>"
                           href="<?= esc($roleIsSupervisor ? url('/cases?status=NUEVO') : url('/cases')) ?>"
                           aria-current="<?= is_active_prefix($path, '/cases') ? 'page' : 'false' ?>">
                            <i class="bi bi-inbox me-1" aria-hidden="true"></i>
                            <?= $roleIsSupervisor ? 'Bandeja principal' : 'Mi bandeja' ?>
                        </a>
                    </li>
                    <?php if ($roleIsSupervisor): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= esc(url('/cases?status=ALL')) ?>">
                                <i class="bi bi-collection me-1" aria-hidden="true"></i>
                                Todos los casos
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (Auth::hasRole('ADMIN') || Auth::hasRole('SUPERVISOR')): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= is_active_prefix($path, '/cases/by-agent') ? 'active' : '' ?>"
                            href="<?= esc(url('/cases/by-agent')) ?>">
                                <i class="bi bi-people me-2"></i>
                                Casos por agente
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if ($roleIsSupervisor): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= is_active_prefix($path, '/agents/status') ? 'active' : '' ?>"
                               href="<?= esc(url('/agents/status')) ?>">
                                <i class="bi bi-person-workspace me-1" aria-hidden="true"></i>
                                Estado asesores
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if ($roleIsSupervisor || $roleIsAgent): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= is_active_prefix($path, '/dashboard') ? 'active' : '' ?>"
                               href="<?= esc(url('/dashboard')) ?>"
                               aria-current="<?= is_active_prefix($path, '/dashboard') ? 'page' : 'false' ?>">
                                <i class="bi bi-speedometer2 me-1" aria-hidden="true"></i>
                                <?= $roleIsSupervisor ? 'Tablero ANS' : 'Mis métricas ANS' ?>
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if ($roleIsSupervisor): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= is_active_prefix($path, '/reports') ? 'active' : '' ?>"
                               href="<?= esc(url('/reports')) ?>"
                               aria-current="<?= is_active_prefix($path, '/reports') ? 'page' : 'false' ?>">
                                <i class="bi bi-file-earmark-bar-graph me-1" aria-hidden="true"></i>
                                Reportes
                            </a>
                        </li>

                        <?php if ($hasRole('ADMIN')): ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle <?= is_active_prefix($path, '/admin') ? 'active' : '' ?>"
                                   href="#"
                                   role="button"
                                   data-bs-toggle="dropdown"
                                   aria-expanded="false">
                                    <i class="bi bi-gear me-1"></i>Administración
                                </a>
                                <ul class="dropdown-menu">
                                    <li>
                                        <a class="dropdown-item <?= $path === '/admin/users' ? 'active' : '' ?>"
                                           href="<?= esc(url('/admin/users')) ?>">
                                            <i class="bi bi-people me-2"></i>Usuarios
                                        </a>
                                    </li>
                                    <li><a class="dropdown-item" href="#"><i class="bi bi-shield-check me-2"></i>Roles y Permisos</a></li>
                                    <li><a class="dropdown-item" href="#"><i class="bi bi-sliders me-2"></i>Configuración</a></li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <?php if ($enableSemaforoRoutes): ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle <?= is_active_prefix($path, '/dashboard/semaforo') ? 'active' : '' ?>"
                                   href="#"
                                   role="button"
                                   data-bs-toggle="dropdown"
                                   aria-expanded="false"
                                   id="semaforoDropdown">
                                    <i class="bi bi-traffic-light me-1" aria-hidden="true"></i>
                                    Semáforo
                                </a>
                                <ul class="dropdown-menu" aria-labelledby="semaforoDropdown">
                                    <li>
                                        <a class="dropdown-item" href="<?= esc(url('/dashboard/semaforo/verde')) ?>">
                                            <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                                            Verde
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="<?= esc(url('/dashboard/semaforo/amarillo')) ?>">
                                            <i class="bi bi-exclamation-triangle-fill text-warning me-2" aria-hidden="true"></i>
                                            Amarillo
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="<?= esc(url('/dashboard/semaforo/rojo')) ?>">
                                            <i class="bi bi-exclamation-octagon-fill text-danger me-2" aria-hidden="true"></i>
                                            Rojo
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>

                <div class="d-flex align-items-center gap-3">
                    <?php if (Auth::check()): ?>
                        <?php if ($roleIsAgent): ?>
                            <div class="dropdown" id="agentPresenceWidget">
                                <button class="btn btn-outline-light btn-sm dropdown-toggle d-flex align-items-center gap-2 agent-presence-toggle"
                                        type="button"
                                        id="agentPresenceButton"
                                        data-bs-toggle="dropdown"
                                        aria-expanded="false">
                                    <span class="agent-presence-dot" id="agentPresenceDot" style="background:#93c5fd"></span>
                                    <span id="agentPresenceLabel">En línea No ACD</span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end agent-presence-menu" aria-labelledby="agentPresenceButton">
                                    <li><button class="dropdown-item" type="button" data-presence-code="DISPONIBLE" data-presence-name="Disponible" data-presence-color="#22c55e"><span class="agent-presence-dot" style="background:#22c55e"></span>Disponible</button></li>
                                    <li><button class="dropdown-item" type="button" data-presence-code="EN_LINEA_NO_ACD" data-presence-name="En línea No ACD" data-presence-color="#93c5fd"><span class="agent-presence-dot" style="background:#93c5fd"></span>En línea No ACD</button></li>
                                    <li><button class="dropdown-item" type="button" data-presence-code="ALMORZANDO" data-presence-name="Almorzando" data-presence-color="#fbbf24"><span class="agent-presence-dot" style="background:#fbbf24"></span>Almorzando</button></li>
                                    <li><button class="dropdown-item" type="button" data-presence-code="AUSENTE" data-presence-name="Ausente" data-presence-color="#fbbf24"><span class="agent-presence-dot" style="background:#fbbf24"></span>Ausente</button></li>
                                    <li><button class="dropdown-item" type="button" data-presence-code="BANO" data-presence-name="Baño" data-presence-color="#fbbf24"><span class="agent-presence-dot" style="background:#fbbf24"></span>Baño</button></li>
                                    <li><button class="dropdown-item" type="button" data-presence-code="CAPACITACION" data-presence-name="Capacitación" data-presence-color="#fbbf24"><span class="agent-presence-dot" style="background:#fbbf24"></span>Capacitación</button></li>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <div class="text-white small text-end">
                            <div class="fw-semibold" id="userFullName"><?= esc($fullName) ?></div>
                            <div class="opacity-75">
                                <span class="badge badge-role" aria-label="Roles asignados">
                                    <?= esc($userRolesLabel !== '' ? $userRolesLabel : 'SIN ROLES') ?>
                                </span>
                            </div>
                        </div>

                        <form method="post"
                              action="<?= esc(url('/logout')) ?>"
                              class="m-0"
                              aria-label="Formulario de cierre de sesión">
                            <input type="hidden" name="_csrf" value="<?= esc(Csrf::token()) ?>">
                            <button class="btn btn-outline-light btn-sm"
                                    type="submit"
                                    aria-label="Cerrar sesión"
                                    data-confirm="true"
                                    data-confirm-title="Cerrar sesión"
                                    data-confirm-text="¿Seguro que deseas cerrar sesión?"
                                    data-confirm-icon="question">
                                <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>
                                Salir
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>
<?php endif; ?>

<main class="<?= $isAuthPage ? '' : 'container py-4 app-shell' ?>" role="main" id="mainContent">
    <?php include $viewPath; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"
        defer></script>

<script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0" defer></script>

<script>

window.addEventListener('DOMContentLoaded', function () {

  <?php if (!empty($flash) && is_array($flash)): ?>
    const type    = <?= json_encode($flash['type'] ?? 'info') ?>;
    const message = <?= json_encode($flash['message'] ?? '') ?>;
    const details = <?= json_encode($flash['details'] ?? null) ?>;

    const iconMap = { success: 'success', error: 'error', warning: 'warning', info: 'info' };
    const icon = iconMap[type] || 'info';

    const payload = {
        icon,
        title: (icon === 'success' ? 'Listo' : (icon === 'error' ? 'Error' : 'Atención')),
        confirmButtonText: 'Aceptar'
    };

    const safe = (v) => String(v ?? '')
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");

    // Si el mensaje ya trae HTML, respétalo; si no, conviértelo a HTML seguro
    let html = /<\/?[a-z][\s\S]*>/i.test(message) ? message : `<div>${safe(message)}</div>`;

    // ✅ Si hay detalles técnicos, los anexamos (para debug/admin)
    if (details && typeof details === 'object') {
        const lines = [
        `ID: ${safe(details.id ?? '')}`,
        `Mensaje: ${safe(details.message ?? '')}`,
        `Código: ${safe(details.code ?? '')}`,
        `Archivo: ${safe(details.file ?? '')}`,
        details.sqlstate ? `SQLSTATE: ${safe(details.sqlstate)}` : null,
        details.driver_code ? `Driver Code: ${safe(details.driver_code)}` : null,
        details.driver_message ? `Driver Message: ${safe(details.driver_message)}` : null,
        ].filter(Boolean).join('\n');

        html += `
        <hr>
        <details style="text-align:left">
            <summary><b>Ver detalles técnicos</b></summary>
            <pre style="white-space:pre-wrap; font-size:12px; margin-top:8px; margin-bottom:0;">
    ${lines}
            </pre>
        </details>
        `;
    }

    payload.html = html;

    const fireFlash = () => (window.Swal ? Swal.fire(payload) : Promise.resolve(alert(message)));
    <?php else: ?>
    const fireFlash = () => Promise.resolve();
    <?php endif; ?>

  const importErrors = <?php echo json_encode($_SESSION['_import_errors'] ?? [], JSON_UNESCAPED_UNICODE); ?>;
  const importTotal  = <?php echo (int)($_SESSION['_import_errors_total'] ?? 0); ?>;

  const fireImportErrors = () => {
    if (!Array.isArray(importErrors) || importErrors.length === 0) return Promise.resolve();

    const escapeHtml = (s) => String(s)
      .replaceAll('&','&amp;').replaceAll('<','&lt;')
      .replaceAll('>','&gt;').replaceAll('"','&quot;')
      .replaceAll("'","&#039;");

    const items = importErrors.map(e => `<li style="margin-bottom:6px;">${escapeHtml(e)}</li>`).join('');

    const footer = (importTotal > importErrors.length)
      ? `<div style="margin-top:10px; font-size:12px; opacity:.75;">
           Mostrando ${importErrors.length} de ${importTotal} errores.
         </div>`
      : '';

    if (!window.Swal) {
      alert(importErrors.join("\n"));
      return Promise.resolve();
    }

    return Swal.fire({
      icon: 'warning',
      title: 'Errores de importación',
      html: `
        <div style="text-align:left; max-height:260px; overflow:auto; padding-right:6px;">
          <ol style="padding-left:18px; margin:0;">
            ${items}
          </ol>
          ${footer}
        </div>
      `,
      confirmButtonText: 'Cerrar'
    });
  };

  fireFlash().then(() => fireImportErrors());
});


<?php if ($roleIsAgent && Auth::check()): ?>
// R1 - Presencia de agente: selector + heartbeat.
window.addEventListener('DOMContentLoaded', function () {
  const presenceUrl = <?= json_encode(url('/agent/presence')) ?>;
  const heartbeatUrl = <?= json_encode(url('/agent/heartbeat')) ?>;
  const csrf = <?= json_encode(Csrf::token()) ?>;
  const heartbeatSeconds = <?= (int)($layoutConfig['agent_presence']['heartbeat_seconds'] ?? 30) ?>;
  const button = document.getElementById('agentPresenceButton');
  const dot = document.getElementById('agentPresenceDot');
  const label = document.getElementById('agentPresenceLabel');

  const applyPresence = (presence) => {
    if (!presence) return;
    if (label) label.textContent = String(presence.name || 'En línea No ACD');
    if (dot) dot.style.background = String(presence.color_hex || '#93c5fd');
    document.querySelectorAll('[data-presence-code]').forEach(el => {
      el.classList.toggle('active', String(el.dataset.presenceCode) === String(presence.code));
    });
  };

  const postForm = async (url, values = {}) => {
    const body = new URLSearchParams({_csrf: csrf, ...values});
    const response = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json'},
      body: body.toString(),
      cache: 'no-store',
      credentials: 'same-origin'
    });
    const data = await response.json().catch(() => ({ok:false, message:'Respuesta inválida'}));
    if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo actualizar el estado');
    return data;
  };

  const heartbeat = async () => {
    if (document.visibilityState === 'hidden') return;
    try {
      const data = await postForm(heartbeatUrl);
      // Si hubo una reconexión después del stale timeout, el backend fuerza
      // EN_LINEA_NO_ACD. Reflejamos inmediatamente ese cambio en pantalla.
      if (data && data.presence) applyPresence(data.presence);
    } catch (_) {}
  };

  fetch(presenceUrl, {headers:{'Accept':'application/json'}, cache:'no-store', credentials:'same-origin'})
    .then(r => r.ok ? r.json() : Promise.reject())
    .then(data => { if (data && data.ok) applyPresence(data.presence); })
    .catch(() => {});

  document.querySelectorAll('[data-presence-code]').forEach(item => {
    item.addEventListener('click', async () => {
      if (button) button.disabled = true;
      try {
        const data = await postForm(presenceUrl, {status_code: item.dataset.presenceCode});
        applyPresence(data.presence);
      } catch (error) {
        if (window.Swal) {
          Swal.fire({icon:'error', title:'No se pudo cambiar el estado', text:String(error.message || error)});
        }
      } finally {
        if (button) button.disabled = false;
      }
    });
  });

  heartbeat();
  const timer = window.setInterval(heartbeat, Math.max(10, heartbeatSeconds) * 1000);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') heartbeat();
  });
  window.addEventListener('pagehide', () => window.clearInterval(timer));
});
<?php endif; ?>

document.addEventListener('click', function (e) {
  const el = e.target.closest('[data-confirm="true"]');
  if (!el) return;

  const title = el.dataset.confirmTitle || 'Confirmar acción';
  const text  = el.dataset.confirmText  || '¿Deseas continuar?';
  const icon  = el.dataset.confirmIcon  || 'warning';

  const form = el.closest('form');
  if (form) {
    e.preventDefault();
    if (!window.Swal) return form.submit();

    Swal.fire({
      icon,
      title,
      text,
      showCancelButton: true,
      confirmButtonText: 'Sí, continuar',
      cancelButtonText: 'Cancelar',
      reverseButtons: true
    }).then((r) => {
      if (r.isConfirmed) form.submit();
    });
    return;
  }

  const href = el.dataset.confirmHref || el.getAttribute('href');
  if (href && href !== '#') {
    e.preventDefault();
    if (!window.Swal) return (window.location.href = href);

    Swal.fire({
      icon,
      title,
      text,
      showCancelButton: true,
      confirmButtonText: 'Sí, continuar',
      cancelButtonText: 'Cancelar',
      reverseButtons: true
    }).then((r) => {
      if (r.isConfirmed) window.location.href = href;
    });
  }
});
</script>

<?php
unset($_SESSION['_import_errors'], $_SESSION['_import_errors_total']);
?>

</body>
</html>
