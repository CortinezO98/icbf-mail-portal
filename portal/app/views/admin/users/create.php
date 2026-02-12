<?php
declare(strict_types=1);

use function App\Config\url;

$roles = $roles ?? [];
$_csrf = $_csrf ?? '';
$rolesCount = is_array($roles) ? count($roles) : 0;
?>

<style>
/* ✅ Sticky footer actions visibles siempre */
.admin-sticky-actions{
  position: sticky;
  bottom: 0;
  background: #fff;
  z-index: 999; /* 🔥 subimos para que no lo tape nada */
  padding: 12px 0 0;
  margin-top: 16px;
  box-shadow: 0 -10px 20px rgba(0,0,0,.06);
}
.admin-sticky-actions::before{
  content:'';
  position:absolute;
  left:0; right:0; top:-12px;
  height:12px;
  pointer-events: none;
  background: linear-gradient(180deg, rgba(255,255,255,0), rgba(255,255,255,1));
}

/* ✅ Evita que el sticky tape el final del contenido */
body.page-app main#mainContent{
  padding-bottom: 80px; /* ajusta si quieres */
}
</style>

<div class="container-fluid py-3">

  <!-- Encabezado -->
  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">
        <i class="bi bi-person-plus text-primary me-2"></i>Crear Nuevo Usuario
      </h1>
      <p class="text-muted mb-0">Completa el formulario para registrar un nuevo usuario en el sistema</p>
    </div>

    <!-- ✅ Acciones ARRIBA (siempre visibles sin scroll) -->
    <div class="d-flex gap-2">
      <a href="<?= esc(url('/admin/users')) ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Volver a la lista
      </a>
    </div>
  </div>

  <?php if ($rolesCount === 0): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <div>
        <div class="fw-semibold">No hay roles disponibles</div>
        <div class="small">
          No se puede crear un usuario sin roles. Verifica que la tabla <code>roles</code> tenga registros.
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="row">
    <div class="col-lg-8 col-xl-6 mx-auto">

      <!-- Formulario principal -->
      <div class="card shadow-sm">
        <div class="card-header bg-light">
          <h6 class="mb-0"><i class="bi bi-person-fill-add me-2"></i>Datos del Usuario</h6>
        </div>

        <div class="card-body">
          <form method="post" action="<?= esc(url('/admin/users/create')) ?>" id="userForm" novalidate>
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
                       inputmode="numeric"
                       pattern="\d{6,15}"
                       maxlength="15"
                       title="Solo números, 6-15 dígitos">
                <div class="form-text">Número de identificación único</div>
                <div class="invalid-feedback">Debe contener solo números (6 a 15 dígitos).</div>
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
                       maxlength="80"
                       autocomplete="off">
                <div class="invalid-feedback">El nombre de usuario es obligatorio (3-80 caracteres).</div>
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
                       required
                       autocomplete="off">
                <div class="invalid-feedback">Ingresa un correo electrónico válido.</div>
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
                       maxlength="190"
                       autocomplete="off">
                <div class="invalid-feedback">El nombre completo es obligatorio.</div>
              </div>
            </div>

            <!-- Configuración de Acceso -->
            <h6 class="border-bottom pb-2 mb-3 mt-4">
              <i class="bi bi-shield-lock me-2"></i>Configuración de Acceso
            </h6>

            <div class="row g-3">
              <div class="col-md-6">
                <label for="password" class="form-label">
                  <i class="bi bi-key me-1"></i>Contraseña <span class="text-muted">(Opcional)</span>
                </label>

                <div class="input-group">
                  <input type="password"
                         class="form-control"
                         id="password"
                         name="password"
                         placeholder="Si se deja vacía, se genera una temporal"
                         minlength="8"
                         autocomplete="new-password">
                  <button class="btn btn-outline-secondary" type="button" id="togglePassword" aria-label="Mostrar/ocultar contraseña">
                    <i class="bi bi-eye"></i>
                  </button>
                </div>

                <div class="form-text">
                  Si la defines manualmente, usa al menos 8 caracteres con mayúscula, minúscula, número y símbolo.
                </div>

                <div class="d-flex flex-wrap gap-2 mt-2">
                  <button type="button" class="btn btn-sm btn-outline-primary" id="generatePassword">
                    <i class="bi bi-shuffle me-1"></i>Generar contraseña segura
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="copyPassword" disabled>
                    <i class="bi bi-clipboard me-1"></i>Copiar
                  </button>
                </div>

                <div id="passwordAlertHost" class="mt-2"></div>
              </div>

              <div class="col-md-6">
                <div class="mb-3">
                  <label class="form-label d-block">
                    <i class="bi bi-toggle2-on me-1"></i>Estado del Usuario
                  </label>

                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="is_active" id="activeYes" value="1" checked>
                    <label class="form-check-label" for="activeYes">
                      <span class="badge bg-success">
                        <i class="bi bi-check-circle me-1"></i>Activo
                      </span>
                    </label>
                  </div>

                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="is_active" id="activeNo" value="0">
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
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="assign_enabled" name="assign_enabled" value="1" checked>
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
              <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <label for="role_ids" class="form-label mb-0">
                  <i class="bi bi-tags me-1"></i>Roles Asignados *
                </label>

                <div class="btn-group btn-group-sm" role="group" aria-label="Acciones de roles">
                  <button type="button" class="btn btn-outline-secondary" id="rolesSelectAll" <?= $rolesCount === 0 ? 'disabled' : '' ?>>
                    Seleccionar todos
                  </button>
                  <button type="button" class="btn btn-outline-secondary" id="rolesClear" <?= $rolesCount === 0 ? 'disabled' : '' ?>>
                    Limpiar
                  </button>
                </div>
              </div>

              <select class="form-select mt-2"
                      id="role_ids"
                      name="role_ids[]"
                      multiple
                      required
                      <?= $rolesCount === 0 ? 'disabled' : '' ?>
                      size="<?= max(3, min(6, $rolesCount + 1)) ?>">
                <?php foreach ($roles as $r): ?>
                  <option value="<?= esc((string)$r['id']) ?>">
                    <?= esc(strtoupper((string)$r['code']) . ' — ' . (string)$r['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>



              <div class="invalid-feedback">Selecciona al menos un rol.</div>
              <div class="form-text">Ctrl (Cmd en Mac) para seleccionar múltiples</div>

              <?php if ($rolesCount > 0): ?>
                <div class="mt-2">
                  <?php foreach ($roles as $r): ?>
                    <?php
                      $roleColor = match (strtoupper((string)$r['code'])) {
                        'ADMIN' => 'danger',
                        'SUPERVISOR' => 'warning',
                        'AGENTE' => 'primary',
                        default => 'secondary'
                      };
                    ?>
                    <span class="badge bg-<?= $roleColor ?> bg-opacity-10 text-<?= $roleColor ?> border border-<?= $roleColor ?> border-opacity-25 me-2 mb-1">
                      <?= esc(strtoupper((string)$r['code'])) ?>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- ✅ Botones de acción (sticky) -->
            <div class="admin-sticky-actions border-top">
              <div class="d-flex justify-content-between gap-2 flex-wrap">
                <a href="<?= esc(url('/admin/users')) ?>" class="btn btn-outline-secondary">
                  <i class="bi bi-x-circle me-1"></i>Cancelar
                </a>

                <button type="submit"
                        class="btn btn-success"
                        id="submitBtn"
                        <?= $rolesCount === 0 ? 'disabled' : '' ?>>
                  <i class="bi bi-check-circle me-1"></i>Crear Usuario
                </button>
              </div>
            </div>

          </form>
        </div>
      </div>

      <div class="alert alert-info mt-4">
        <div class="d-flex">
          <div class="flex-shrink-0"><i class="bi bi-info-circle-fill"></i></div>
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
  const submitTop = document.getElementById('submitTop');

  const generatePasswordBtn = document.getElementById('generatePassword');
  const copyPasswordBtn = document.getElementById('copyPassword');
  const passwordInput = document.getElementById('password');
  const togglePasswordBtn = document.getElementById('togglePassword');
  const alertHost = document.getElementById('passwordAlertHost');

  const rolesSelect = document.getElementById('role_ids');
  const rolesSelectAll = document.getElementById('rolesSelectAll');
  const rolesClear = document.getElementById('rolesClear');

  function clearPasswordAlert() { if (alertHost) alertHost.innerHTML = ''; }
  function showPasswordAlert(password) {
    if (!alertHost) return;
    clearPasswordAlert();
    const div = document.createElement('div');
    div.className = 'alert alert-success alert-dismissible fade show';
    div.innerHTML = `
      <i class="bi bi-check-circle me-2"></i>
      Contraseña generada: <code>${password}</code>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    alertHost.appendChild(div);
  }

  generatePasswordBtn?.addEventListener('click', function() {
    const lower = 'abcdefghijklmnopqrstuvwxyz';
    const upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const nums  = '0123456789';
    const symb  = '!@#$%^&*';
    const all   = lower + upper + nums + symb;

    let pwd = '';
    pwd += upper[Math.floor(Math.random() * upper.length)];
    pwd += lower[Math.floor(Math.random() * lower.length)];
    pwd += nums[Math.floor(Math.random() * nums.length)];
    pwd += symb[Math.floor(Math.random() * symb.length)];
    for (let i = 0; i < 8; i++) pwd += all[Math.floor(Math.random() * all.length)];
    pwd = pwd.split('').sort(() => Math.random() - 0.5).join('');

    passwordInput.value = pwd;
    passwordInput.type = 'text';
    togglePasswordBtn && (togglePasswordBtn.innerHTML = '<i class="bi bi-eye-slash"></i>');
    if (copyPasswordBtn) copyPasswordBtn.disabled = false;
    showPasswordAlert(pwd);
  });

  copyPasswordBtn?.addEventListener('click', async function() {
    const val = (passwordInput?.value || '').trim();
    if (!val) return;
    try {
      await navigator.clipboard.writeText(val);
      window.Swal?.fire({ icon:'success', title:'Copiado', text:'Contraseña copiada', timer:1200, showConfirmButton:false });
    } catch (e) {
      passwordInput.select();
      document.execCommand('copy');
    }
  });

  togglePasswordBtn?.addEventListener('click', function() {
    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
    passwordInput.setAttribute('type', type);
    this.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
  });

  rolesSelectAll?.addEventListener('click', function() {
    if (!rolesSelect) return;
    for (const opt of rolesSelect.options) opt.selected = true;
  });

  rolesClear?.addEventListener('click', function() {
    if (!rolesSelect) return;
    for (const opt of rolesSelect.options) opt.selected = false;
  });

  form?.addEventListener('submit', function(event) {
    if (rolesSelect && rolesSelect.disabled) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }

    if (!form.checkValidity()) {
      event.preventDefault();
      event.stopPropagation();
    }

    form.classList.add('was-validated');

    if (form.checkValidity()) {
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Creando usuario...';
      }
      if (submitTop) submitTop.disabled = true;
    }
  });
});
</script>
