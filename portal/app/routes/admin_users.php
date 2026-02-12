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

// Definir rutas (SIN el base_path) - Cada ruta aplica su propio middleware
$routes = [
    'GET' => [
        '/admin/users' => function () use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->index();
        },
        '/admin/users/create' => function () use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->showCreate();
        },
        '/admin/users/edit/(\d+)' => function ($id) use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->showEdit((int)$id);
        },
        '/admin/users/import' => function () use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->showImport();
        },
        '/admin/users/export-template' => function () use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->exportTemplate();
        },
        '/admin/users/export' => function () use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->exportExcel();
        },
    ],
    'POST' => [
        '/admin/users/create' => function () use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->create();
        },
        '/admin/users/edit/(\d+)' => function ($id) use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->update((int)$id);
        },
        '/admin/users/toggle-active/(\d+)' => function ($id) use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->toggleActive((int)$id);
        },
        '/admin/users/delete/(\d+)' => function ($id) use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->delete((int)$id);
        },
        '/admin/users/import' => function () use ($controller) {
            \App\Middleware\require_login();
            \App\Middleware\require_role(['ADMIN']);
            $controller->import();
        },
    ],
];

// Encontrar ruta coincidente
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

foreach ($routes[$method] ?? [] as $pattern => $handler) {
    if (preg_match('#^' . $pattern . '$#', $currentPath, $matches)) {
        array_shift($matches);
        call_user_func_array($handler, $matches);
        exit;
    }
}

// Si no hay ruta, continuar con index.php (404)
return;
