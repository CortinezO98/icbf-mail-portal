<?php
declare(strict_types=1);

use function App\Config\url;

$users = $users ?? [];
$roles = $roles ?? [];
$flash = $flash ?? null;
$_csrf = $_csrf ?? '';
$search = $search ?? '';
$isActive = $isActive ?? null;
$roleId = $roleId ?? null;
$pagination = $pagination ?? [];
$stats = $stats ?? [];

$totalPages = $pagination['totalPages'] ?? 1;
$currentPage = $pagination['page'] ?? 1;
$hasPrev = $pagination['hasPrev'] ?? false;
$hasNext = $pagination['hasNext'] ?? false;
?>

<div class="container-fluid py-3">
    
    <!-- Encabezado -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="bi bi-people-fill text-primary me-2"></i>Gestión de Usuarios
            </h1>
            <p class="text-muted mb-0">Administra los usuarios del sistema ICBF Mail</p>
        </div>
        
        <div class="d-flex gap-2">
            <a href="<?= esc(url('/admin/users/create')) ?>" 
               class="btn btn-success">
                <i class="bi bi-person-plus me-1"></i>Nuevo Usuario
            </a>
            <a href="<?= esc(url('/admin/users/import')) ?>" 
               class="btn btn-outline-primary">
                <i class="bi bi-upload me-1"></i>Importar
            </a>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flash): ?>
        <?php
            $type = $flash['type'] ?? 'info';
            $msg = $flash['message'] ?? '';
            $cls = match($type) {
                'success' => 'alert-success',
                'error' => 'alert-danger',
                'warning' => 'alert-warning',
                'info' => 'alert-info',
                default => 'alert-secondary'
            };
            $icon = match($type) {
                'success' => 'bi-check-circle-fill',
                'error' => 'bi-exclamation-triangle-fill',
                'warning' => 'bi-exclamation-triangle-fill',
                'info' => 'bi-info-circle-fill',
                default => 'bi-info-circle'
            };
        ?>
        <div class="alert <?= $cls ?> alert-dismissible fade show" role="alert">
            <i class="bi <?= $icon ?> me-2"></i>
            <?= nl2br(esc($msg)) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Estadísticas Rápidas -->
    <div class="row mb-4">
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-0 bg-primary bg-opacity-10">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Total Usuarios</h6>
                            <h3 class="mb-0"><?= esc($stats['general']['total_users'] ?? 0) ?></h3>
                        </div>
                        <div class="flex-shrink-0">
                            <i class="bi bi-people display-6 text-primary opacity-25"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-0 bg-success bg-opacity-10">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Activos</h6>
                            <h3 class="mb-0"><?= esc($stats['general']['active_users'] ?? 0) ?></h3>
                        </div>
                        <div class="flex-shrink-0">
                            <i class="bi bi-check-circle display-6 text-success opacity-25"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-0 bg-info bg-opacity-10">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Asignables</h6>
                            <h3 class="mb-0"><?= esc($stats['general']['assignable_users'] ?? 0) ?></h3>
                        </div>
                        <div class="flex-shrink-0">
                            <i class="bi bi-person-check display-6 text-info opacity-25"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-0 bg-warning bg-opacity-10">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Con Acceso</h6>
                            <h3 class="mb-0"><?= esc($stats['general']['users_with_login'] ?? 0) ?></h3>
                        </div>
                        <div class="flex-shrink-0">
                            <i class="bi bi-door-open display-6 text-warning opacity-25"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel me-2"></i>Filtrar Usuarios</h6>
        </div>
        <div class="card-body">
            <form method="get" action="<?= esc(url('/admin/users')) ?>" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Buscar</label>
                    <input type="text" 
                           class="form-control" 
                           name="search" 
                           value="<?= esc($search) ?>"
                           placeholder="Documento, usuario, email o nombre">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Estado</label>
                    <select class="form-select" name="active">
                        <option value="">Todos</option>
                        <option value="1" <?= $isActive === 1 ? 'selected' : '' ?>>Activo</option>
                        <option value="0" <?= $isActive === 0 ? 'selected' : '' ?>>Inactivo</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Rol</label>
                    <select class="form-select" name="role_id">
                        <option value="">Todos los roles</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= esc($r['id']) ?>" 
                                <?= $roleId === (int)$r['id'] ? 'selected' : '' ?>>
                                <?= esc($r['code'] . ' - ' . $r['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2 d-flex align-items-end">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search me-1"></i>Filtrar
                        </button>
                        <?php if ($search || $isActive !== null || $roleId !== null): ?>
                            <a href="<?= esc(url('/admin/users')) ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabla de Usuarios -->
    <div class="card shadow-sm">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0">
                <i class="bi bi-table me-2"></i>Lista de Usuarios
                <span class="badge bg-secondary ms-2"><?= esc($pagination['total'] ?? 0) ?></span>
            </h6>
            
            <div class="d-flex align-items-center gap-2">
                <a href="<?= esc(url('/admin/users/export-template')) ?>" 
                   class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-download me-1"></i>Plantilla
                </a>
                
                <form method="post" 
                      action="<?= esc(url('/admin/users/export')) ?>" 
                      class="d-inline"
                      onsubmit="return confirm('¿Exportar todos los usuarios a Excel?')">
                    <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-file-excel me-1"></i>Exportar
                    </button>
                </form>
            </div>
        </div>
        
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">ID</th>
                            <th>Documento</th>
                            <th>Usuario</th>
                            <th>Nombre</th>
                            <th>Email</th>
                            <th>Roles</th>
                            <th class="text-center">Estado</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <?php
                                $userRoles = !empty($u['roles']) 
                                    ? array_map('trim', explode(',', $u['roles']))
                                    : [];
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <small class="text-muted">#<?= esc($u['id']) ?></small>
                                </td>
                                
                                <td>
                                    <?php if ($u['document']): ?>
                                        <span class="fw-semibold"><?= esc($u['document']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <div class="fw-semibold"><?= esc($u['username']) ?></div>
                                    <?php if ($u['assign_enabled'] == 1): ?>
                                        <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 badge-sm">
                                            <i class="bi bi-person-check me-1"></i>Asignable
                                        </span>
                                    <?php endif; ?>
                                </td>
                                
                                <td><?= esc($u['full_name']) ?></td>
                                
                                <td>
                                    <a href="mailto:<?= esc($u['email']) ?>" class="text-decoration-none">
                                        <?= esc($u['email']) ?>
                                    </a>
                                </td>
                                
                                <td>
                                    <?php if (empty($userRoles)): ?>
                                        <span class="badge bg-light text-dark border">Sin roles</span>
                                    <?php else: ?>
                                        <?php foreach ($userRoles as $role): ?>
                                            <?php
                                                $roleColor = match(strtoupper($role)) {
                                                    'ADMIN' => 'danger',
                                                    'SUPERVISOR' => 'warning',
                                                    'AGENTE' => 'primary',
                                                    default => 'secondary'
                                                };
                                            ?>
                                            <span class="badge bg-<?= $roleColor ?> bg-opacity-10 text-<?= $roleColor ?> border border-<?= $roleColor ?> border-opacity-25">
                                                <?= esc($role) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="text-center">
                                    <?php if ((int)($u['is_active']) === 1): ?>
                                        <span class="badge bg-success">
                                            <i class="bi bi-check-circle me-1"></i>Activo
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">
                                            <i class="bi bi-x-circle me-1"></i>Inactivo
                                        </span>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="<?= esc(url('/admin/users/edit/' . $u['id'])) ?>" 
                                           class="btn btn-outline-primary"
                                           title="Editar">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        
                                        <?php if ((int)($u['is_active']) === 1): ?>
                                            <form method="post" 
                                                  action="<?= esc(url('/admin/users/toggle-active/' . $u['id'])) ?>"
                                                  class="d-inline"
                                                  onsubmit="return confirm('¿Desactivar este usuario?')">
                                                <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">
                                                <button type="submit" 
                                                        class="btn btn-outline-warning"
                                                        title="Desactivar">
                                                    <i class="bi bi-pause"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" 
                                                  action="<?= esc(url('/admin/users/toggle-active/' . $u['id'])) ?>"
                                                  class="d-inline"
                                                  onsubmit="return confirm('¿Activar este usuario?')">
                                                <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">
                                                <button type="submit" 
                                                        class="btn btn-outline-success"
                                                        title="Activar">
                                                    <i class="bi bi-play"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <form method="post" 
                                              action="<?= esc(url('/admin/users/delete/' . $u['id'])) ?>"
                                              class="d-inline"
                                              onsubmit="return confirm('¿Eliminar permanentemente este usuario? Esta acción no se puede deshacer.')">
                                            <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">
                                            <button type="submit" 
                                                    class="btn btn-outline-danger"
                                                    title="Eliminar"
                                                    <?= $u['id'] === ($_SESSION['user_id'] ?? null) ? 'disabled' : '' ?>>
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="bi bi-people display-6 opacity-25 mb-3 d-block"></i>
                                        <h5 class="mb-2">No se encontraron usuarios</h5>
                                        <p class="mb-0"><?= $search ? 'Intenta con otros filtros' : 'Comienza creando un nuevo usuario' ?></p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Paginación -->
        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-light">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="text-muted small">
                        Mostrando <?= esc(($currentPage - 1) * ($pagination['perPage'] ?? 20) + 1) ?> 
                        a <?= esc(min($currentPage * ($pagination['perPage'] ?? 20), $pagination['total'] ?? 0)) ?> 
                        de <?= esc($pagination['total'] ?? 0) ?> usuarios
                    </div>
                    
                    <nav aria-label="Paginación de usuarios">
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($hasPrev): ?>
                                <li class="page-item">
                                    <a class="page-link" 
                                       href="<?= esc(url('/admin/users?page=' . ($currentPage - 1) . '&search=' . urlencode($search) . '&active=' . $isActive . '&role_id=' . $roleId)) ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link"><i class="bi bi-chevron-left"></i></span>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <?php if ($i == 1 || $i == $totalPages || abs($i - $currentPage) <= 2): ?>
                                    <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                                        <a class="page-link" 
                                           href="<?= esc(url('/admin/users?page=' . $i . '&search=' . urlencode($search) . '&active=' . $isActive . '&role_id=' . $roleId)) ?>">
                                            <?= $i ?>
                                        </a>
                                    </li>
                                <?php elseif ($i == 2 && $currentPage > 4): ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                <?php elseif ($i == $totalPages - 1 && $currentPage < $totalPages - 3): ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                <?php endif; ?>
                            <?php endfor; ?>
                            
                            <?php if ($hasNext): ?>
                                <li class="page-item">
                                    <a class="page-link" 
                                       href="<?= esc(url('/admin/users?page=' . ($currentPage + 1) . '&search=' . urlencode($search) . '&active=' . $isActive . '&role_id=' . $roleId)) ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link"><i class="bi bi-chevron-right"></i></span>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Modal de confirmación -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirmar acción</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="confirmMessage"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <form id="confirmForm" method="post" style="display: inline;">
                    <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">
                    <button type="submit" class="btn btn-danger">Confirmar</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Confirmación mejorada para acciones
document.addEventListener('DOMContentLoaded', function() {
    const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
    
    // Configurar confirmaciones
    document.querySelectorAll('[data-confirm]').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            const message = this.getAttribute('data-confirm');
            const form = this.closest('form');
            
            if (form) {
                document.getElementById('confirmMessage').textContent = message;
                document.getElementById('confirmForm').action = form.action;
                confirmModal.show();
            }
        });
    });
    
    // Auto-focus en búsqueda
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.focus();
        searchInput.select();
    }
});
</script>