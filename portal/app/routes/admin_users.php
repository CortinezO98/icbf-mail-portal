<?php
declare(strict_types=1);

use App\Controllers\UsersAdminController;
use App\Middleware\require_role;

// Middleware para proteger rutas de admin
require_role(['ADMIN']);

$controller = new UsersAdminController($pdo, $config);

// Definir rutas
$routes = [
    'GET' => [
        '/admin/users' => fn() => $controller->index(),
        '/admin/users/create' => fn() => $controller->showCreate(),
        '/admin/users/edit/(\d+)' => fn($id) => $controller->showEdit((int)$id),
        '/admin/users/import' => fn() => $controller->showImport(),
        '/admin/users/export-template' => fn() => $controller->exportTemplate(),
        '/admin/users/export' => fn() => $controller->exportExcel(),
    ],
    'POST' => [
        '/admin/users/create' => fn() => $controller->create(),
        '/admin/users/edit/(\d+)' => fn($id) => $controller->update((int)$id),
        '/admin/users/toggle-active/(\d+)' => fn($id) => $controller->toggleActive((int)$id),
        '/admin/users/delete/(\d+)' => fn($id) => $controller->delete((int)$id),
        '/admin/users/import' => fn() => $controller->import(),
    ]
];

// Encontrar ruta coincidente
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

foreach ($routes[$method] ?? [] as $pattern => $handler) {
    if (preg_match('#^' . $pattern . '$#', $path, $matches)) {
        array_shift($matches);
        call_user_func_array($handler, $matches);
        exit;
    }
}

// Si no encuentra ruta
http_response_code(404);
echo "Página no encontrada";