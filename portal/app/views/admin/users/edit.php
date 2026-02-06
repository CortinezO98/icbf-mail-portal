<?php
declare(strict_types=1);

use function App\Config\url;

$user = $user ?? [];
$roles = $roles ?? [];
$_csrf = $_csrf ?? '';

$userRoles = $user['role_ids'] ?? [];
?>

<div class="container-fluid py-3">
    
    <!-- Encabezado -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="bi bi-pencil-square text-primary me-2"></i>Editar Usuario
            </h1>
            <p class="text-muted mb-0">
                Editando: <strong><?= esc($user['full_name'] ?? 'Usuario') ?></strong>
                <span class="badge bg-secondary ms-2">ID: <?= esc($user['id'] ?? '') ?></span>
            </p>
        </div>
        
        <div>
            <a href="<?= esc(url('/admin/users')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Volver a la lista
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8 col-xl-6 mx-auto">
            
            <!-- Formulario principal -->
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0">
                        <i class="bi bi-person-gear me-2"></i>Actualizar Información
                        <?php if ((int)($user['is_active'] ?? 0) === 1): ?>
                            <span class="badge bg-success ms-2">Activo</span>
                        <?php else: ?>
                            <span class="badge bg-secondary ms-2">Inactivo</span>
                        <?php endif; ?>
                    </h6>
                </div>
                <div class="card-body">
                    <form method="post" 
                          action="<?= esc(url('/admin/users/edit/' . ($user['id'] ?? ''))) ?>" 
                          id="editForm">
                        <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">
                        
                        <!-- Información Básica -->
                        <h6 class="border-bottom pb-2 mb-3">
                            <i class="bi bi-info-circle me-2"></i>Información Básica
                        </h6>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="document" class="form-label">
                                    <i class="bi bi-card-text me-1"></i>Documento (Cédula)
                                    <span class="text-muted">(Opcional)</span>
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       id="document"
                                       name="document" 
                                       value="<?= esc($user['document'] ?? '') ?>"
                                       placeholder="Ej: 1012345678"
                                       pattern="\d{6,15}"
                                       title="Solo números, 6-15 dígitos">
                                <div class="form-text">Número de identificación único</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="username" class="form-label">
                                    <i class="bi bi-person-badge me-1"></i>Nombre de Usuario *
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       id="username"
                                       name="username" 
                                       value="<?= esc($user['username'] ?? '') ?>"
                                       required
                                       minlength="3"
                                       maxlength="80">
                                <div class="invalid-feedback">El nombre de usuario es obligatorio (3-80 caracteres)</div>
                            </div>
                        </div>
                        
                        <div class="row g-3 mt-2">
                            <div class="col-md-6">
                                <label for="email" class="form-label">
                                    <i class="bi bi-envelope me-1"></i>Correo Electrónico *
                                </label>
                                <input type="email" 
                                       class="form-control" 
                                       id="email"
                                       name="email" 
                                       value="<?= esc($user['email'] ?? '') ?>"
                                       required>
                                <div class="invalid-feedback">Ingresa un correo electrónico válido</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="full_name" class="form-label">
                                    <i class="bi bi-person-vcard me-1"></i>Nombre Completo *
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       id="full_name"
                                       name="full_name" 
                                       value="<?= esc($user['full_name'] ?? '') ?>"
                                       required
                                       minlength="2"
                                       maxlength="190">
                                <div class="invalid-feedback">El nombre completo es obligatorio</div>
                            </div>
                        </div>
                        
                        <!-- Configuración de Acceso -->
                        <h6 class="border-bottom pb-2 mb-3 mt-4">
                            <i class="bi bi-shield-lock me-2"></i>Configuración de Acceso
                        </h6>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="password" class="form-label">
                                    <i class="bi bi-key me-1"></i>Nueva Contraseña
                                    <span class="text-muted">(Opcional)</span>
                                </label>
                                <div class="input-group">
                                    <input type="password" 
                                           class="form-control" 
                                           id="password"
                                           name="password" 
                                           placeholder="Dejar vacío para no cambiar"
                                           minlength="8"
                                           pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$">
                                    <button class="btn btn-outline-secondary" 
                                            type="button" 
                                            id="togglePassword">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text">
                                    Mínimo 8 caracteres con mayúscula, minúscula, número y símbolo
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label d-block">
                                        <i class="bi bi-toggle2-on me-1"></i>Estado del Usuario
                                    </label>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" 
                                               type="radio" 
                                               name="is_active" 
                                               id="activeYes"
                                               value="1" 
                                               <?= ((int)($user['is_active'] ?? 0) === 1) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="activeYes">
                                            <span class="badge bg-success">
                                                <i class="bi bi-check-circle me-1"></i>Activo
                                            </span>
                                        </label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" 
                                               type="radio" 
                                               name="is_active" 
                                               id="activeNo"
                                               value="0"
                                               <?= ((int)($user['is_active'] ?? 0) === 0) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="activeNo">
                                            <span class="badge bg-secondary">
                                                <i class="bi bi-x-circle me-1"></i>Inactivo
                                            </span>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" 
                                               type="checkbox" 
                                               role="switch"
                                               id="assign_enabled" 
                                               name="assign_enabled" 
                                               value="1" 
                                               <?= ((int)($user['assign_enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="assign_enabled">
                                            <i class="bi bi-person-check me-1"></i>Habilitar para asignación
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Información del sistema -->
                        <div class="alert alert-light border mt-3">
                            <div class="row small">
                                <div class="col-md-6">
                                    <div class="mb-2">
                                        <i class="bi bi-calendar me-1 text-muted"></i>
                                        <strong>Último acceso:</strong>
                                        <?php if ($user['last_login_at']): ?>
                                            <?= esc(date('d/m/Y H:i', strtotime($user['last_login_at']))) ?>
                                        <?php else: ?>
                                            <span class="text-muted">Nunca</span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <i class="bi bi-calendar-plus me-1 text-muted"></i>
                                        <strong>Creado:</strong>
                                        <?= esc(date('d/m/Y', strtotime($user['created_at'] ?? ''))) ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div>
                                        <i class="bi bi-calendar-check me-1 text-muted"></i>
                                        <strong>Actualizado:</strong>
                                        <?= esc(date('d/m/Y', strtotime($user['updated_at'] ?? ''))) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Roles y Permisos -->
                        <h6 class="border-bottom pb-2 mb-3 mt-4">
                            <i class="bi bi-person-rolodex me-2"></i>Roles y Permisos
                        </h6>
                        
                        <div class="mb-3">
                            <label for="role_ids" class="form-label">
                                <i class="bi bi-tags me-1"></i>Roles Asignados *
                            </label>
                            <select class="form-select" 
                                    id="role_ids" 
                                    name="role_ids[]" 
                                    multiple 
                                    required
                                    size="<?= min(6, count($roles) + 1) ?>">
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= esc($r['id']) ?>"
                                        <?= in_array((int)$r['id'], $userRoles, true) ? 'selected' : '' ?>>
                                        <?= esc(strtoupper($r['code']) . ' — ' . $r['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Mantén presionada la tecla Ctrl (Cmd en Mac) para seleccionar múltiples roles
                            </div>
                        </div>
                        
                        <!-- Botones de acción -->
                        <div class="border-top pt-4 mt-4">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <a href="<?= esc(url('/admin/users')) ?>" 
                                       class="btn btn-outline-secondary me-2">
                                        <i class="bi bi-x-circle me-1"></i>Cancelar
                                    </a>
                                    
                                    <?php if ((int)($user['is_active'] ?? 0) === 1): ?>
                                        <form method="post" 
                                              action="<?= esc(url('/admin/users/toggle-active/' . ($user['id'] ?? ''))) ?>"
                                              class="d-inline"
                                              onsubmit="return confirm('¿Desactivar este usuario?')">
                                            <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">
                                            <button type="submit" 
                                                    class="btn btn-outline-warning me-2">
                                                <i class="bi bi-pause me-1"></i>Desactivar
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" 
                                              action="<?= esc(url('/admin/users/toggle-active/' . ($user['id'] ?? ''))) ?>"
                                              class="d-inline"
                                              onsubmit="return confirm('¿Activar este usuario?')">
                                            <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">
                                            <button type="submit" 
                                                    class="btn btn-outline-success me-2">
                                                <i class="bi bi-play me-1"></i>Activar
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <button type="button" 
                                            class="btn btn-outline-danger" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#deleteModal"
                                            <?= $user['id'] === ($_SESSION['user_id'] ?? null) ? 'disabled' : '' ?>>
                                        <i class="bi bi-trash me-1"></i>Eliminar
                                    </button>
                                </div>
                                
                                <button type="submit" 
                                        class="btn btn-primary"
                                        id="submitBtn">
                                    <i class="bi bi-check-circle me-1"></i>Guardar Cambios
                                </button>
                            </div>
                        </div>
                        
                    </form>
                </div>
            </div>
            
            <!-- Información adicional -->
            <div class="alert alert-info mt-4">
                <div class="d-flex">
                    <div class="flex-shrink-0">
                        <i class="bi bi-info-circle-fill"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="alert-heading mb-2">Notas importantes</h6>
                        <ul class="mb-0">
                            <li>Los cambios se aplicarán inmediatamente en el sistema</li>
                            <li>Si cambias el estado a "Inactivo", el usuario no podrá iniciar sesión</li>
                            <li>Al cambiar roles, los permisos se actualizarán automáticamente</li>
                            <li>Solo cambia la contraseña si el usuario lo ha solicitado</li>
                        </ul>
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>

<!-- Modal de eliminación -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle text-danger me-2"></i>
                    Confirmar Eliminación
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-octagon-fill me-2"></i>
                    <strong>¡Atención! Esta acción no se puede deshacer.</strong>
                </div>
                
                <p>¿Estás seguro de que deseas eliminar permanentemente a este usuario?</p>
                
                <div class="card border-danger">
                    <div class="card-body">
                        <h6 class="mb-2">Usuario a eliminar:</h6>
                        <p class="mb-1"><strong>Nombre:</strong> <?= esc($user['full_name'] ?? '') ?></p>
                        <p class="mb-1"><strong>Usuario:</strong> <?= esc($user['username'] ?? '') ?></p>
                        <p class="mb-0"><strong>Email:</strong> <?= esc($user['email'] ?? '') ?></p>
                    </div>
                </div>
                
                <div class="alert alert-warning mt-3">
                    <i class="bi bi-lightbulb me-2"></i>
                    <strong>Recomendación:</strong> Considera desactivar el usuario en lugar de eliminarlo, 
                    para mantener el historial de asignaciones y actividades.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Cancelar
                </button>
                
                <?php if ($user['id'] !== ($_SESSION['user_id'] ?? null)): ?>
                <form method="post" 
                      action="<?= esc(url('/admin/users/delete/' . ($user['id'] ?? ''))) ?>">
                    <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Eliminar Permanentemente
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('editForm');
    const submitBtn = document.getElementById('submitBtn');
    const togglePasswordBtn = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    
    // Mostrar/ocultar contraseña
    togglePasswordBtn?.addEventListener('click', function() {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        this.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
    });
    
    // Validación del formulario
    form?.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        
        form.classList.add('was-validated');
        
        // Deshabilitar botón para prevenir doble envío
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Guardando...';
        }
    });
    
    // Validación en tiempo real del email
    const emailInput = document.getElementById('email');
    emailInput?.addEventListener('blur', function() {
        const email = this.value;
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        
        if (email && !emailRegex.test(email)) {
            this.classList.add('is-invalid');
        } else {
            this.classList.remove('is-invalid');
        }
    });
    
    // Auto-focus en el primer campo
    const firstInput = form?.querySelector('input:not([type="hidden"])');
    firstInput?.focus();
});
</script>