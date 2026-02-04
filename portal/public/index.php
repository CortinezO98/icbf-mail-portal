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

require_once __DIR__ . '/../app/controllers/AuthController.php';
require_once __DIR__ . '/../app/controllers/CasesController.php';
require_once __DIR__ . '/../app/controllers/AssignmentsController.php';
require_once __DIR__ . '/../app/controllers/AttachmentsController.php';

require_once __DIR__ . '/../app/middleware/require_login.php';
require_once __DIR__ . '/../app/middleware/require_role.php';

// Bootstrap session & config
$config = \App\Config\load_config();
\App\Auth\Auth::initSession($config);

$pdo = \App\Config\pdo();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// remove base path if configured (e.g. /portal/public)
$basePath = rtrim($config['base_path'], '/');
if ($basePath !== '' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath));
    if ($path === '') $path = '/';
}

// Simple router
try {
    if ($path === '/' && $method === 'GET') {
        // redirect based on auth
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

    // Attachments download
    if (preg_match('#^/attachments/(\d+)/download$#', $path, $m) && $method === 'GET') {
        \App\Middleware\require_login();
        $attachmentId = (int)$m[1];
        (new \App\Controllers\AttachmentsController($pdo, $config))->download($attachmentId);
        exit;
    }

    http_response_code(404);
    echo "404 Not Found";
} catch (\Throwable $e) {
    if (!empty($config['debug'])) {
        http_response_code(500);
        echo "<pre>ERROR: " . htmlspecialchars($e->getMessage()) . "\n\n" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    } else {
        http_response_code(500);
        echo "Internal Server Error";
    }
}
