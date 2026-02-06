<?php
declare(strict_types=1);

use App\Auth\Auth;
use App\Auth\Csrf;
use function App\Config\url;

require_once __DIR__ . '/_helpers.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isLogin = str_ends_with($path, '/login') || $path === '/login';

$roleIsSupervisor = Auth::hasRole('SUPERVISOR') || Auth::hasRole('ADMIN');
$enableSemaforoRoutes = false;

$user = Auth::user() ?? [];
$fullName = (string)($user['full_name'] ?? $user['username'] ?? '');
$rolesLabel = '';

if (Auth::check()) {
    $roles = Auth::roles();
    $rolesLabel = $roles ? implode(', ', $roles) : '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Sistema de gestión de correspondencia ICBF">
    <title>ICBF - Portal de Correspondencia</title>

    <!-- Bootstrap CSS con SRI para seguridad -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
          rel="stylesheet" 
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" 
          crossorigin="anonymous">
    
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" 
          rel="stylesheet">
    
    <!-- Animate.css -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" 
          rel="stylesheet">

    <!-- Estilos propios -->
    <link href="<?= esc(url('/assets/css/app.css?v=2')) ?>" rel="stylesheet">

    <style>
        :root {
            --color-primary: #4CAF50;
            --color-primary-dark: #3f9c44;
            --color-primary-soft: rgba(76, 175, 80, 0.12);
        }
        
        .navbar-brand { 
            letter-spacing: 0.2px; 
        }
        
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
        
        /* Mejoras de accesibilidad */
        .nav-link:focus-visible {
            outline: 2px solid #ffffff;
            outline-offset: 2px;
        }
        
        button:focus-visible {
            outline: 2px solid var(--color-primary-dark);
            outline-offset: 2px;
        }
    </style>
</head>

<body class="bg-light <?= $isLogin ? 'page-login' : 'page-app' ?>">
    <?php if (!$isLogin): ?>
    <!-- Barra de navegación principal -->
    <nav class="navbar navbar-expand-lg navbar-dark" 
         style="background-color: var(--color-primary);"
         aria-label="Navegación principal">
        <div class="container-fluid">
            <!-- Logo y marca -->
            <a class="navbar-brand fw-semibold d-flex align-items-center gap-2"
               href="<?= esc(url('/cases')) ?>"
               aria-label="ICBF Mail - Inicio">
                <i class="bi bi-envelope-paper" aria-hidden="true"></i>
                <span>ICBF Mail</span>
            </a>

            <!-- Botón hamburguesa para móviles -->
            <button class="navbar-toggler" 
                    type="button" 
                    data-bs-toggle="collapse" 
                    data-bs-target="#mainNavbar"
                    aria-controls="mainNavbar" 
                    aria-expanded="false" 
                    aria-label="Alternar navegación">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Contenido del menú -->
            <div class="collapse navbar-collapse" id="mainNavbar">
                <!-- Menú de navegación -->
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?= is_active_prefix($path, '/cases') ? 'active' : '' ?>"
                           href="<?= esc(url('/cases')) ?>"
                           aria-current="<?= is_active_prefix($path, '/cases') ? 'page' : 'false' ?>">
                            <i class="bi bi-inbox me-1" aria-hidden="true"></i>
                            Bandeja
                        </a>
                    </li>

                    <?php if ($roleIsSupervisor): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= is_active_prefix($path, '/dashboard') ? 'active' : '' ?>"
                           href="<?= esc(url('/dashboard')) ?>"
                           aria-current="<?= is_active_prefix($path, '/dashboard') ? 'page' : 'false' ?>">
                            <i class="bi bi-speedometer2 me-1" aria-hidden="true"></i>
                            Tablero ANS
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= is_active_prefix($path, '/reports') ? 'active' : '' ?>"
                           href="<?= esc(url('/reports')) ?>"
                           aria-current="<?= is_active_prefix($path, '/reports') ? 'page' : 'false' ?>">
                            <i class="bi bi-file-earmark-bar-graph me-1" aria-hidden="true"></i>
                            Reportes
                        </a>
                    </li>

                    <?php if (Auth::hasRole('ADMIN')): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= is_active_prefix($path, '/admin') ? 'active' : '' ?>" 
                          href="#" 
                          role="button" 
                          data-bs-toggle="dropdown" 
                          aria-expanded="false">
                            <i class="bi bi-gear me-1"></i>Administración
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item <?= $path === '/admin/users' ? 'active' : '' ?>" 
                                  href="<?= esc(url('/admin/users')) ?>">
                                <i class="bi bi-people me-2"></i>Usuarios
                            </a></li>
                            <li><a class="dropdown-item" href="#">
                                <i class="bi bi-shield-check me-2"></i>Roles y Permisos
                            </a></li>
                            <li><a class="dropdown-item" href="#">
                                <i class="bi bi-sliders me-2"></i>Configuración
                            </a></li>
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
                                <a class="dropdown-item" 
                                   href="<?= esc(url('/dashboard/semaforo/verde')) ?>">
                                    <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                                    Verde
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" 
                                   href="<?= esc(url('/dashboard/semaforo/amarillo')) ?>">
                                    <i class="bi bi-exclamation-triangle-fill text-warning me-2" aria-hidden="true"></i>
                                    Amarillo
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" 
                                   href="<?= esc(url('/dashboard/semaforo/rojo')) ?>">
                                    <i class="bi bi-exclamation-octagon-fill text-danger me-2" aria-hidden="true"></i>
                                    Rojo
                                </a>
                            </li>
                        </ul>
                    </li>
                    <?php endif; ?>
                    <?php endif; ?>
                </ul>

                <!-- Sección de usuario -->
                <div class="d-flex align-items-center gap-3">
                    <?php if (Auth::check()): ?>
                    <div class="text-white small text-end">
                        <div class="fw-semibold" id="userFullName">
                            <?= esc($fullName) ?>
                        </div>
                        <?php if ($rolesLabel !== ''): ?>
                        <div class="opacity-75">
                            <span class="badge badge-role" aria-label="Roles asignados">
                                <?= esc($rolesLabel) ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <form method="post" 
                          action="<?= esc(url('/logout')) ?>" 
                          class="m-0"
                          aria-label="Formulario de cierre de sesión">
                        <input type="hidden" 
                               name="_csrf" 
                               value="<?= esc(Csrf::token()) ?>">
                        <button class="btn btn-outline-light btn-sm" 
                                type="submit"
                                aria-label="Cerrar sesión">
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

    <!-- Contenido principal -->
    <main class="<?= $isLogin ? '' : 'container py-4 app-shell' ?>" 
          role="main"
          id="mainContent">
        <?php include $viewPath; ?>
    </main>

    <!-- Scripts con SRI y atributos defer para mejor rendimiento -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
            crossorigin="anonymous"
            defer></script>
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"
            defer></script>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"
            defer></script>
    
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"
            defer></script>
</body>
</html>