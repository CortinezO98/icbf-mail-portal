<?php
declare(strict_types=1);

use App\Auth\Auth;
use App\Auth\Csrf;
use function App\Config\url;

require_once __DIR__ . '/_helpers.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isLogin = str_ends_with($path, '/login') || $path === '/login';

/**
 * ✅ Normalizador de roles
 */
$normRole = static fn(string $r): string => strtoupper(trim($r));

/**
 * ✅ Usuario en sesión
 */
$user = Auth::user() ?? [];
$fullName = (string)($user['full_name'] ?? $user['username'] ?? '');

/**
 * ✅ Roles del usuario logueado:
 * sesión -> fallback BD si vienen vacíos
 * NOTA: aquí usamos $pdo si existe (llega vía extract($params) desde el controller)
 *
 * 🚨 IMPORTANTE:
 * Usamos $userRoles (NO $roles) para no pisar la variable $roles
 * que usan vistas como admin/users/create.php (lista de roles disponibles).
 */
$userRoles = [];
if (Auth::check()) {
    // 1) Roles desde sesión
    $userRoles = Auth::roles() ?: [];
    $userRoles = array_values(array_unique(array_filter(array_map($normRole, $userRoles))));

    // 2) Si vienen vacíos, fallback a BD
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

            // Guardamos en sesión para no consultar BD en cada request
            $_SESSION['user']['roles'] = $userRoles;
        } catch (\Throwable $e) {
            $userRoles = [];
        }
    }

    // 3) Último fallback: string "ADMIN,AGENTE"
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

/**
 * ✅ Helpers de roles (robustos)
 * Usan $userRoles ya normalizados (sesión o BD).
 */
$hasRole = static fn(string $code): bool => in_array($normRole($code), $userRoles, true);

$roleIsSupervisor = $hasRole('SUPERVISOR') || $hasRole('ADMIN');
$roleIsAgent      = $hasRole('AGENTE');

$enableSemaforoRoutes = false;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Sistema de gestión de correspondencia ICBF">
    <title>ICBF - Portal de Correos</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"
          rel="stylesheet">

    <!-- ✅ SweetAlert2 -->
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
    </style>
</head>

<body class="bg-light <?= $isLogin ? 'page-login' : 'page-app' ?>">
<?php if (!$isLogin): ?>
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
                        <a class="nav-link <?= is_active_prefix($path, '/cases') ? 'active' : '' ?>"
                           href="<?= esc(url('/cases')) ?>"
                           aria-current="<?= is_active_prefix($path, '/cases') ? 'page' : 'false' ?>">
                            <i class="bi bi-inbox me-1" aria-hidden="true"></i>
                            Bandeja
                        </a>
                    </li>

                    <!-- ✅ Dashboard Supervisor/Admin y Agente -->
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

                    <!-- ✅ Reportes solo Supervisor/Admin -->
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

<main class="<?= $isLogin ? '' : 'container py-4 app-shell' ?>" role="main" id="mainContent">
    <?php include $viewPath; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"
        defer></script>

<script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0" defer></script>

<script>
/**
 * ✅ Flash + Errores de importación + Confirmaciones globales
 */
window.addEventListener('DOMContentLoaded', function () {

  // 1) Flash (Swal) - soporta HTML y texto
  <?php if (!empty($flash) && is_array($flash)): ?>
    const type = <?= json_encode($flash['type'] ?? 'info') ?>;
    const message = <?= json_encode($flash['message'] ?? '') ?>;

    const iconMap = { success: 'success', error: 'error', warning: 'warning', info: 'info' };
    const icon = iconMap[type] || 'info';
    const looksHtml = /<\/?[a-z][\s\S]*>/i.test(message);

    const payload = {
      icon,
      title: (icon === 'success' ? 'Listo' : (icon === 'error' ? 'Error' : 'Atención')),
      confirmButtonText: 'Aceptar'
    };

    if (looksHtml) payload.html = message;
    else payload.text = message;

    const fireFlash = () => (window.Swal ? Swal.fire(payload) : Promise.resolve(alert(message)));
  <?php else: ?>
    const fireFlash = () => Promise.resolve();
  <?php endif; ?>

  // 2) Errores de importación (modal)
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

/**
 * ✅ Confirmaciones globales para forms y links con data-confirm="true"
 */
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
// Limpia errores de importación (solo después de mostrarlos)
unset($_SESSION['_import_errors'], $_SESSION['_import_errors_total']);
?>

</body>
</html>
