<?php
// portal/app/routes/admin_users.php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/require_login.php';
require_once __DIR__ . '/../middleware/require_role.php';

use App\Controllers\UsersAdminController;

// Obtener $pdo y $config del ámbito global (vienen desde public/index.php)
global $pdo, $config;

$controller = new UsersAdminController($pdo, $config);

// PROCESAR EL PATH IGUAL QUE EN INDEX.PHP
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = rtrim((string)($config['base_path'] ?? ''), '/');

if ($basePath !== '' && str_starts_with($currentPath, $basePath)) {
    $currentPath = substr($currentPath, strlen($basePath));
    if ($currentPath === '') $currentPath = '/';
}

// ELIMINAR EL PREFIJO /admin/users PARA QUE LAS RUTAS COINCIDAN
$prefix = '/admin/users';
if (str_starts_with($currentPath, $prefix)) {
    $routePath = substr($currentPath, strlen($prefix));
    if ($routePath === '') $routePath = '/';
} else {
    // No debería pasar porque index.php ya filtró, pero por seguridad
    http_response_code(404);
    echo "Invalid admin route";
    exit;
}

// Definir rutas (SIN el prefijo /admin/users)
$routes = [
    'GET' => [
        '/' => function () use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->index();
        },
        '/create' => function () use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->showCreate();
        },
        '/edit/(\d+)' => function ($id) use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->showEdit((int)$id);
        },
        '/import' => function () use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->showImport();
        },
        '/export-template' => function () use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->exportTemplate();
        },
        '/export' => function () use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->exportExcel();
        },
    ],
    'POST' => [
        '/create' => function () use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->create();
        },
        '/edit/(\d+)' => function ($id) use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->update((int)$id);
        },
        '/toggle-active/(\d+)' => function ($id) use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->toggleActive((int)$id);
        },
        '/delete/(\d+)' => function ($id) use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->delete((int)$id);
        },
        '/import' => function () use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->import();
        },
    ],
];

// Encontrar ruta coincidente
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

foreach ($routes[$method] ?? [] as $pattern => $handler) {
    if (preg_match('#^' . $pattern . '$#', $routePath, $matches)) {
        array_shift($matches);
        call_user_func_array($handler, $matches);
        exit;
    }
}

// Si no hay ruta, 404
http_response_code(404);
echo "Admin route not found: " . htmlspecialchars($routePath);
exit;