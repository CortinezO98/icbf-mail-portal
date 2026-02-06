<?php
declare(strict_types=1);

use function App\Config\url;

$roles = $roles ?? [];
$_csrf = $_csrf ?? '';
?>

<div class="container-fluid py-3">
    
    <!-- Encabezado -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="bi bi-person-plus text-primary me-2"></i>Crear Nuevo Usuario
            </h1>
            <p class="text-muted mb-0">Completa el formulario para registrar un nuevo usuario en el sistema</p>
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
                    <h6 class="mb-0"><i class="bi bi-person-fill-add me-2"></i>Datos del Usuario</h6>
                </div>
                <div class="card-body">
                    <form method="post" action="<?= esc(url('/admin/users/create')) ?>" id="userForm">
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
                                       placeholder="Ej: jperez"
                                       required
                                       minlength="3"
                                       maxlength="80">
                                <div class="invalid-feedback">El nombre de usuario es obligatorio (3-80 caracteres)</div>
                                <div class="form-text">Será usado para iniciar sesión en el sistema</div>
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
                                       placeholder="usuario@ejemplo.com"
                                       required>
                                <div class="invalid-feedback">Ingresa un correo electrónico válido</div>
                                <div class="form-text">Para notificaciones y recuperación de cuenta</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="full_name" class="form-label">
                                    <i class="bi bi-person-vcard me-1"></i>Nombre Completo *
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       id="full_name"
                                       name="full_name" 
                                       placeholder="Ej: Juan Pérez"
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
                                    <i class="bi bi-key me-1"></i>Contraseña
                                    <span class="text-muted">(Opcional)</span>
                                </label>
                                <div class="input-group">
                                    <input type="password" 
                                           class="form-control" 
                                           id="password"
                                           name="password" 
                                           placeholder="Si se deja vacía, se genera una temporal"
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
                                <button type="button" 
                                        class="btn btn-sm btn-outline-primary mt-1"
                                        id="generatePassword">
                                    <i class="bi bi-shuffle me-1"></i>Generar contraseña segura
                                </button>
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
                                               checked>
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
                                               value="0">
                                        <label class="form-check-label" for="activeNo">
                                            <span class="badge bg-secondary">
                                                <i class="bi bi-x-circle me-1"></i>Inactivo
                                            </span>
                                        </label>
                                    </div>
                                    <div class="form-text">Los usuarios inactivos no pueden iniciar sesión</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" 
                                               type="checkbox" 
                                               role="switch"
                                               id="assign_enabled" 
                                               name="assign_enabled" 
                                               value="1" 
                                               checked>
                                        <label class="form-check-label" for="assign_enabled">
                                            <i class="bi bi-person-check me-1"></i>Habilitar para asignación
                                        </label>
                                    </div>
                                    <div class="form-text">Permitir asignarle casos automáticamente</div>
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
                                    <option value="<?= esc($r['id']) ?>">
                                        <?= esc(strtoupper($r['code']) . ' — ' . $r['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Mantén presionada la tecla Ctrl (Cmd en Mac) para seleccionar múltiples roles
                            </div>
                            <div class="mt-2">
                                <?php foreach ($roles as $r): ?>
                                    <?php
                                        $roleColor = match(strtoupper($r['code'])) {
                                            'ADMIN' => 'danger',
                                            'SUPERVISOR' => 'warning',
                                            'AGENTE' => 'primary',
                                            default => 'secondary'
                                        };
                                    ?>
                                    <span class="badge bg-<?= $roleColor ?> bg-opacity-10 text-<?= $roleColor ?> border border-<?= $roleColor ?> border-opacity-25 me-2 mb-1">
                                        <?= esc(strtoupper($r['code'])) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Botones de acción -->
                        <div class="border-top pt-4 mt-4">
                            <div class="d-flex justify-content-between">
                                <a href="<?= esc(url('/admin/users')) ?>" 
                                   class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle me-1"></i>Cancelar
                                </a>
                                
                                <button type="submit" 
                                        class="btn btn-success"
                                        id="submitBtn">
                                    <i class="bi bi-check-circle me-1"></i>Crear Usuario
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
                        <h6 class="alert-heading mb-2">Información importante</h6>
                        <ul class="mb-0">
                            <li>El sistema validará que el usuario, email y documento sean únicos</li>
                            <li>Si no se especifica contraseña, se generará una temporal automáticamente</li>
                            <li>Se recomienda asignar al menos un rol al usuario</li>
                            <li>Los cambios se reflejarán inmediatamente en el sistema</li>
                        </ul>
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('userForm');
    const submitBtn = document.getElementById('submitBtn');
    const generatePasswordBtn = document.getElementById('generatePassword');
    const passwordInput = document.getElementById('password');
    const togglePasswordBtn = document.getElementById('togglePassword');
    
    // Generar contraseña segura
    generatePasswordBtn?.addEventListener('click', function() {
        const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        let password = '';
        
        // Asegurar al menos un carácter de cada tipo
        password += chars[Math.floor(Math.random() * 26) + 26]; // Mayúscula
        password += chars[Math.floor(Math.random() * 26)]; // Minúscula
        password += chars[Math.floor(Math.random() * 10) + 52]; // Número
        password += '!@#$%^&*'[Math.floor(Math.random() * 8)]; // Símbolo
        
        // Completar hasta 12 caracteres
        for (let i = 0; i < 8; i++) {
            password += chars[Math.floor(Math.random() * chars.length)];
        }
        
        // Mezclar caracteres
        password = password.split('').sort(() => Math.random() - 0.5).join('');
        
        passwordInput.value = password;
        passwordInput.type = 'text';
        
        // Mostrar notificación
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-success alert-dismissible fade show mt-2';
        alertDiv.innerHTML = `
            <i class="bi bi-check-circle me-2"></i>
            Contraseña generada: <code>${password}</code>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        generatePasswordBtn.parentNode.appendChild(alertDiv);
    });
    
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
        
        // Validar email único (simulación)
        const email = document.getElementById('email').value;
        const username = document.getElementById('username').value;
        
        // Deshabilitar botón para prevenir doble envío
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Creando usuario...';
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
    
    // Validación en tiempo real del documento
    const docInput = document.getElementById('document');
    docInput?.addEventListener('input', function() {
        const doc = this.value;
        
        if (doc && !/^\d*$/.test(doc)) {
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