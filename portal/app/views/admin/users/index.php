<?php
declare(strict_types=1);

use function App\Config\url;

$users = $users ?? [];
$roles = $roles ?? [];
$_csrf = $_csrf ?? '';
$search = $search ?? '';
$isActive = $isActive ?? null;
$roleId = $roleId ?? null;
$pagination = $pagination ?? [];
$stats = $stats ?? [];

$totalPages  = (int)($pagination['totalPages'] ?? 1);
$currentPage = (int)($pagination['page'] ?? 1);
$hasPrev     = (bool)($pagination['hasPrev'] ?? false);
$hasNext     = (bool)($pagination['hasNext'] ?? false);
$perPage     = (int)($pagination['perPage'] ?? 20);
$total       = (int)($pagination['total'] ?? 0);

$qs = static function(array $overrides = []) use ($search, $isActive, $roleId): string {

    $q = [];

    if ($search !== '') {
        $q['search'] = $search;
    }

    if ($isActive !== null) {
        $q['active'] = (string)$isActive;
    }

    if ($roleId !== null && $roleId > 0) {
        $q['role_id'] = (string)$roleId;
    }

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($q[$key]); 
        } else {
            $q[$key] = $value; 
        }
    }

    return http_build_query($q);
};
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
            <a href="<?= esc(url('/admin/users/create')) ?>" class="btn btn-success">
                <i class="bi bi-person-plus me-1"></i>Nuevo Usuario
            </a>
            <a href="<?= esc(url('/admin/users/import')) ?>" class="btn btn-outline-primary">
                <i class="bi bi-upload me-1"></i>Importar
            </a>
        </div>
    </div>

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
                            <a href="<?= esc(url('/admin/users')) ?>" class="btn btn-outline-secondary" title="Limpiar filtros">
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
                <span class="badge bg-secondary ms-2"><?= esc($total) ?></span>
            </h6>

            <div class="d-flex align-items-center gap-2">
                <a href="<?= esc(url('/admin/users/export-template')) ?>"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-download me-1"></i>Plantilla
                </a>

                <form method="post" action="<?= esc(url('/admin/users/export')) ?>" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">
                    <button type="submit"
                            class="btn btn-sm btn-outline-primary"
                            data-confirm="true"
                            data-confirm-title="Exportar usuarios"
                            data-confirm-text="¿Exportar todos los usuarios a Excel?"
                            data-confirm-icon="question">
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
                                    ? array_map('trim', explode(',', (string)$u['roles']))
                                    : [];
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <small class="text-muted">#<?= esc($u['id']) ?></small>
                                </td>

                                <td>
                                    <?php if (!empty($u['document'])): ?>
                                        <span class="fw-semibold"><?= esc($u['document']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="fw-semibold"><?= esc($u['username']) ?></div>
                                    <?php if ((int)($u['assign_enabled'] ?? 0) === 1): ?>
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
                                    <?php if ((int)($u['is_active'] ?? 0) === 1): ?>
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
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Acciones de usuario">
                                        <a href="<?= esc(url('/admin/users/edit/' . $u['id'])) ?>"
                                           class="btn btn-outline-primary"
                                           title="Editar">
                                            <i class="bi bi-pencil"></i>
                                        </a>

                                        <form method="post"
                                              action="<?= esc(url('/admin/users/toggle-active/' . $u['id'])) ?>"
                                              class="d-inline">
                                            <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">

                                            <?php if ((int)($u['is_active'] ?? 0) === 1): ?>
                                                <button type="submit"
                                                        class="btn btn-outline-warning"
                                                        title="Desactivar"
                                                        data-confirm="true"
                                                        data-confirm-title="Cambiar estado"
                                                        data-confirm-text="¿Desactivar este usuario?"
                                                        data-confirm-icon="warning">
                                                    <i class="bi bi-pause"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="submit"
                                                        class="btn btn-outline-success"
                                                        title="Activar"
                                                        data-confirm="true"
                                                        data-confirm-title="Cambiar estado"
                                                        data-confirm-text="¿Activar este usuario?"
                                                        data-confirm-icon="question">
                                                    <i class="bi bi-play"></i>
                                                </button>
                                            <?php endif; ?>
                                        </form>

                                        <form method="post"
                                              action="<?= esc(url('/admin/users/delete/' . $u['id'])) ?>"
                                              class="d-inline">
                                            <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">
                                            <button type="submit"
                                                    class="btn btn-outline-danger"
                                                    title="Eliminar"
                                                    data-confirm="true"
                                                    data-confirm-title="Eliminar usuario"
                                                    data-confirm-text="¿Eliminar permanentemente este usuario? Esta acción no se puede deshacer."
                                                    data-confirm-icon="warning"
                                                    <?= ((int)$u['id'] === (int)($_SESSION['user_id'] ?? 0)) ? 'disabled' : '' ?>>
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
                        <?php if ($total > 0): ?>
                            Mostrando <?= esc((($currentPage - 1) * $perPage) + 1) ?>
                            a <?= esc(min($currentPage * $perPage, $total)) ?>
                            de <?= esc($total) ?> usuarios
                        <?php else: ?>
                            Mostrando 0 usuarios
                        <?php endif; ?>
                    </div>

                    <nav aria-label="Paginación de usuarios">
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($hasPrev): ?>
                                <li class="page-item">
                                    <a class="page-link"
                                       href="<?= esc(url('/admin/users?' . $qs(['page' => $currentPage - 1]))) ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link"><i class="bi bi-chevron-left"></i></span>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <?php if ($i === 1 || $i === $totalPages || abs($i - $currentPage) <= 2): ?>
                                    <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                                        <a class="page-link"
                                           href="<?= esc(url('/admin/users?' . $qs(['page' => $i]))) ?>">
                                            <?= $i ?>
                                        </a>
                                    </li>
                                <?php elseif ($i === 2 && $currentPage > 4): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php elseif ($i === $totalPages - 1 && $currentPage < $totalPages - 3): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($hasNext): ?>
                                <li class="page-item">
                                    <a class="page-link"
                                       href="<?= esc(url('/admin/users?' . $qs(['page' => $currentPage + 1]))) ?>">
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

<script>
document.addEventListener('DOMContentLoaded', function () {
  const searchInput = document.querySelector('input[name="search"]');
  if (searchInput && !searchInput.value) searchInput.focus();
});

if (window.location.hash === '#password-generated') {
  const userId = new URLSearchParams(window.location.search).get('user_id');
  const storedPassword = sessionStorage.getItem('generated_password_' + userId);

  if (storedPassword) {
    Swal.fire({
      title: 'Contraseña Generada',
      html: `La contraseña temporal es: <code>${storedPassword}</code><br><br>
            <small>Guarda esta contraseña de manera segura.</small>`,
      icon: 'info',
      confirmButtonText: 'Copiar al Portapapeles',
      showCancelButton: true,
      cancelButtonText: 'Cerrar'
    }).then((result) => {
      if (result.isConfirmed) {
        navigator.clipboard.writeText(storedPassword);
        Swal.fire('Copiado!', 'Contraseña copiada al portapapeles.', 'success');
      }
      sessionStorage.removeItem('generated_password_' + userId);
    });
  }
}
</script>
