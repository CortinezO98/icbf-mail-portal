<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';

require_once __DIR__ . '/../app/auth/Csrf.php';
require_once __DIR__ . '/../app/auth/Auth.php';

require_once __DIR__ . '/../app/repos/UsersRepo.php';
require_once __DIR__ . '/../app/repos/CasesRepo.php';
require_once __DIR__ . '/../app/repos/MessagesRepo.php';
require_once __DIR__ . '/../app/repos/AttachmentsRepo.php';
require_once __DIR__ . '/../app/repos/EventsRepo.php';
require_once __DIR__ . '/../app/repos/MetricsRepo.php';
require_once __DIR__ . '/../app/repos/ReportsRepo.php';
require_once __DIR__ . '/../app/repos/UsersAdminRepo.php';

require_once __DIR__ . '/../app/controllers/AuthController.php';
require_once __DIR__ . '/../app/controllers/CasesController.php';
require_once __DIR__ . '/../app/controllers/AssignmentsController.php';
require_once __DIR__ . '/../app/controllers/AttachmentsController.php';
require_once __DIR__ . '/../app/controllers/AutoAssignController.php';
require_once __DIR__ . '/../app/controllers/DashboardController.php';
require_once __DIR__ . '/../app/services/ReportExportService.php';
require_once __DIR__ . '/../app/controllers/ReportsController.php';
require_once __DIR__ . '/../app/controllers/UsersAdminController.php';


require_once __DIR__ . '/../app/middleware/require_login.php';
require_once __DIR__ . '/../app/middleware/require_role.php';

$config = \App\Config\load_config();
\App\Auth\Auth::initSession($config);

$pdo = \App\Config\pdo();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$basePath = rtrim((string)($config['base_path'] ?? ''), '/');
if ($basePath !== '' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath));
    if ($path === '') $path = '/';
}

// Simple router
try {
    if ($path === '/' && $method === 'GET') {
        if (\App\Auth\Auth::check()) {
            header('Location: ' . \App\Config\url('/cases'));
        } else {
            header('Location: ' . \App\Config\url('/login'));
        }
        exit;
    }

    // Auth routes
    if ($path === '/login' && $method === 'GET') {
        (new \App\Controllers\AuthController($pdo, $config))->showLogin();
        exit;
    }
    if ($path === '/login' && $method === 'POST') {
        (new \App\Controllers\AuthController($pdo, $config))->login();
        exit;
    }
    if ($path === '/logout' && $method === 'POST') {
        (new \App\Controllers\AuthController($pdo, $config))->logout();
        exit;
    }

    // Cases
    if ($path === '/cases' && $method === 'GET') {
        \App\Middleware\require_login();
        (new \App\Controllers\CasesController($pdo, $config))->inbox();
        exit;
    }
    if (preg_match('#^/cases/(\d+)$#', $path, $m) && $method === 'GET') {
        \App\Middleware\require_login();
        $caseId = (int)$m[1];
        (new \App\Controllers\CasesController($pdo, $config))->detail($caseId);
        exit;
    }

    // Assignments (Supervisor/Admin)
    if (preg_match('#^/cases/(\d+)/assign$#', $path, $m) && $method === 'POST') {
        \App\Middleware\require_login();
        \App\Middleware\require_role(['ADMIN', 'SUPERVISOR']);
        $caseId = (int)$m[1];
        (new \App\Controllers\AssignmentsController($pdo, $config))->assign($caseId);
        exit;
    }

    // Auto-assign cases (Supervisor/Admin)
    if ($path === '/cases/auto-assign' && $method === 'POST') {
        \App\Middleware\require_login();
        \App\Middleware\require_role(['ADMIN', 'SUPERVISOR']);
        (new \App\Controllers\AutoAssignController($pdo, $config))->run();
        exit;
    }

    // Dashboard (Supervisor/Admin)
    if ($path === '/dashboard' && $method === 'GET') {
        \App\Middleware\require_login();
        \App\Middleware\require_role(['ADMIN', 'SUPERVISOR', 'AGENT']);
        (new \App\Controllers\DashboardController($pdo, $config))->index();
        exit;
    }

    // Dashboard Semáforo (Supervisor/Admin)
    // /dashboard/semaforo/verde | /dashboard/semaforo/amarillo | /dashboard/semaforo/rojo
    if (preg_match('#^/dashboard/semaforo/(verde|amarillo|rojo)$#i', $path, $m) && $method === 'GET') {
        \App\Middleware\require_login();
        \App\Middleware\require_role(['ADMIN', 'SUPERVISOR']);
        $estado = strtolower($m[1]);
        (new \App\Controllers\DashboardController($pdo, $config))->semaforo($estado);
        exit;
    }

    // Reports (Supervisor/Admin)
    if ($path === '/reports' && $method === 'GET') {
        \App\Middleware\require_login();
        \App\Middleware\require_role(['ADMIN', 'SUPERVISOR']);
        (new \App\Controllers\ReportsController($pdo, $config))->dashboard();
        exit;
    }

    if ($path === '/reports/export' && $method === 'GET') {
        \App\Middleware\require_login();
        \App\Middleware\require_role(['ADMIN', 'SUPERVISOR']);
        (new \App\Controllers\ReportsController($pdo, $config))->export();
        exit;
    }

    // Descarga por querystring: /reports/download?id=123
    if ($path === '/reports/download' && $method === 'GET') {
        \App\Middleware\require_login();
        \App\Middleware\require_role(['ADMIN', 'SUPERVISOR']);
        (new \App\Controllers\ReportsController($pdo, $config))->download();
        exit;
    }

    // Attachments download
    if (preg_match('#^/attachments/(\d+)/download$#', $path, $m) && $method === 'GET') {
        \App\Middleware\require_login();
        $attachmentId = (int)$m[1];
        (new \App\Controllers\AttachmentsController($pdo, $config))->download($attachmentId);
        exit;
    }


    // Users admin (ADMIN only)
    if ($path === '/admin/users' && $method === 'GET') {
        \App\Middleware\require_login();
        \App\Middleware\require_role(['ADMIN']);
        (new \App\Controllers\UsersAdminController($pdo, $config))->index();
        exit;
    }

    if ($path === '/admin/users/create' && $method === 'GET') {
        \App\Middleware\require_login();
        \App\Middleware\require_role(['ADMIN']);
        (new \App\Controllers\UsersAdminController($pdo, $config))->showCreate();
        exit;
    }

    if ($path === '/admin/users/create' && $method === 'POST') {
        \App\Middleware\require_login();
        \App\Middleware\require_role(['ADMIN']);
        (new \App\Controllers\UsersAdminController($pdo, $config))->create();
        exit;
    }

    if (preg_match('#^/admin/users/edit/(\d+)$#', $path, $m) && $method === 'GET') {
        \App\Middleware\require_login();
        \App\Middleware\require_role(['ADMIN']);
        $userId = (int)$m[1];
        (new \App\Controllers\UsersAdminController($pdo, $config))->showEdit($userId);
        exit;
    }

    if (preg_match('#^/admin/users/edit/(\d+)$#', $path, $m) && $method === 'POST') {
        \App\Middleware\require_login();
        \App\Middleware\require_role(['ADMIN']);
        $userId = (int)$m[1];
        (new \App\Controllers\UsersAdminController($pdo, $config))->update($userId);
        exit;
    }

    if (preg_match('#^/admin/users/toggle-active/(\d+)$#', $path, $m) && $method === 'POST') {
        \App\Middleware\require_login();
        \App\Middleware\require_role(['ADMIN']);
        $userId = (int)$m[1];
        (new \App\Controllers\UsersAdminController($pdo, $config))->toggleActive($userId);
        exit;
    }

    if (preg_match('#^/admin/users/delete/(\d+)$#', $path, $m) && $method === 'POST') {
        \App\Middleware\require_login();
        \App\Middleware\require_role(['ADMIN']);
        $userId = (int)$m[1];
        (new \App\Controllers\UsersAdminController($pdo, $config))->delete($userId);
        exit;
    }

    if ($path === '/admin/users/import' && $method === 'GET') {
        \App\Middleware\require_login();
        \App\Middleware\require_role(['ADMIN']);
        (new \App\Controllers\UsersAdminController($pdo, $config))->showImport();
        exit;
    }

    if ($path === '/admin/users/import' && $method === 'POST') {
        \App\Middleware\require_login();
        \App\Middleware\require_role(['ADMIN']);
        (new \App\Controllers\UsersAdminController($pdo, $config))->importExcel();
        exit;
    }

    if ($path === '/admin/users/export-template' && $method === 'GET') {
        \App\Middleware\require_login();
        \App\Middleware\require_role(['ADMIN']);
        (new \App\Controllers\UsersAdminController($pdo, $config))->exportTemplate();
        exit;
    }


    // 404
    http_response_code(404);
    $title = "404 | Recurso no encontrado";
    $requested = htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
    $home = \App\Config\url('/cases');
    $login = \App\Config\url('/login');

    echo <<<HTML
    <!doctype html>
    <html lang="es">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
            <div class="card-body p-4 p-md-5">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h1 class="h3 mb-1">Recurso no encontrado</h1>
                    <p class="text-muted mb-0">No pudimos encontrar la ruta solicitada en el portal.</p>
                </div>
                <span class="badge text-bg-secondary">HTTP 404</span>
                </div>

                <hr class="my-4">

                <p class="mb-2"><strong>Ruta solicitada:</strong></p>
                <div class="bg-light border rounded p-2 font-monospace small">{$requested}</div>

                <div class="mt-4 d-flex flex-wrap gap-2">
                <a class="btn btn-primary" href="{$home}">Ir a bandeja</a>
                <a class="btn btn-outline-secondary" href="{$login}">Ir a inicio de sesión</a>
                </div>

                <p class="text-muted small mt-4 mb-0">
                Si crees que esto es un error, verifica la URL o contacta al administrador del sistema.
                </p>
            </div>
            </div>
            <div class="text-center text-muted small mt-3">
            ICBF Mail • Portal de gestión • {$requested}
            </div>
        </div>
        </div>
    </div>
    </body>
    </html>
    HTML;
    exit;

} catch (\Throwable $e) {
    if (!empty($config['debug'])) {
        http_response_code(500);
        echo "<pre>ERROR: " . htmlspecialchars($e->getMessage()) . "\n\n" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    } else {
        http_response_code(500);
        echo "Internal Server Error";
    }
}
