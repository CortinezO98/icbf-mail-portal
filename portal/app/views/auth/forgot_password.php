<?php
use App\Auth\Csrf;
use function App\Config\url;

$year = date('Y');
?>
<div class="login-wrap">
  <div class="login-col">

    <!-- Logos con animación mejorada -->
    <div class="login-logos animate__animated animate__fadeInDown">
      <img
        src="<?= htmlspecialchars(url('/assets/img/logo_icbf.png'), ENT_QUOTES, 'UTF-8') ?>"
        alt="Logo ICBF"
        class="img-fluid logo-hover"
      >
      <img
        src="<?= htmlspecialchars(url('/assets/img/logo_iq.png'), ENT_QUOTES, 'UTF-8') ?>"
        alt="Logo IQ Outsourcing"
        class="img-fluid logo-hover"
        style="max-height:70px;"
      >
    </div>

    <div class="login-title animate__animated animate__fadeInDown">
      <span class="badge bg-success bg-opacity-10 text-success px-3 py-2 rounded-pill">
        <i class="bi bi-shield-lock me-2"></i>Recuperación de Acceso
      </span>
    </div>

    <div class="card card-login shadow-lg animate__animated animate__fadeInUp">
      <div class="card-header bg-gradient">
        <h5 class="mb-0 text-white d-flex align-items-center">
          <i class="bi bi-envelope-paper fs-4 me-2"></i>
          <span>Restablecer contraseña</span>
        </h5>
      </div>

      <div class="card-body p-4">
        <?php if (!empty($message)): ?>
          <div class="alert alert-success border-0 bg-success-subtle text-success d-flex align-items-center" role="alert">
            <i class="bi bi-check-circle-fill fs-5 me-2"></i>
            <div><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
          <div class="alert alert-danger border-0 bg-danger-subtle text-danger d-flex align-items-center" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-5 me-2"></i>
            <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        <?php endif; ?>

        <!-- Instrucciones -->
        <div class="instruction-box mb-4 p-3 bg-light rounded-3">
          <p class="mb-2 text-secondary">
            <i class="bi bi-info-circle-fill text-success me-2"></i>
            Te enviaremos un enlace seguro a tu correo para restablecer tu contraseña.
          </p>
          <div class="progress" style="height: 2px;">
            <div class="progress-bar bg-success" style="width: 0%" id="progressBar"></div>
          </div>
        </div>

        <form method="post" action="<?= htmlspecialchars(url('/forgot-password'), ENT_QUOTES, 'UTF-8') ?>" id="forgotForm" novalidate>
          <input
            type="hidden"
            name="_csrf"
            value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>"
          >

          <!-- Campo de email con diseño mejorado -->
          <div class="mb-4 input-group-floating">
            <div class="form-floating">
              <input
                id="email"
                name="email"
                type="email"
                class="form-control form-control-lg border-0 border-bottom rounded-0 px-0"
                placeholder="nombre@ejemplo.com"
                required
                autocomplete="email"
                value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              >
              <label for="email" class="px-0 text-secondary">
                <i class="bi bi-envelope me-2"></i>Correo electrónico
              </label>
            </div>
            <div class="invalid-feedback">Por favor ingresa un correo válido.</div>
          </div>

          <!-- Botón con efecto -->
          <div class="d-grid gap-3">
            <button type="submit" class="btn btn-success btn-lg position-relative overflow-hidden" id="submitBtn">
              <span class="btn-text">
                <i class="bi bi-send-check me-2"></i>Enviar enlace de recuperación
              </span>
              <span class="spinner-border spinner-border-sm d-none" role="status" id="spinner"></span>
            </button>

            <a href="<?= htmlspecialchars(url('/login'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">
              <i class="bi bi-arrow-left me-2"></i>Volver al inicio de sesión
            </a>
          </div>
        </form>

        <!-- Características de seguridad -->
        <div class="security-features mt-4 pt-3 border-top">
          <div class="row g-2 text-center text-secondary small">
            <div class="col-4">
              <i class="bi bi-shield-check text-success d-block fs-4 mb-1"></i>
              <span>Enlace seguro</span>
            </div>
            <div class="col-4">
              <i class="bi bi-clock-history text-success d-block fs-4 mb-1"></i>
              <span>Válido 30 min</span>
            </div>
            <div class="col-4">
              <i class="bi bi-incognito text-success d-block fs-4 mb-1"></i>
              <span>Confidencial</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="login-footer animate__animated animate__fadeInUp">
      <small class="text-muted">
        © <?= (int)$year ?> ICBF • IQ Outsourcing • 
        <a href="#" class="text-decoration-none text-success">Términos</a> • 
        <a href="#" class="text-decoration-none text-success">Privacidad</a>
      </small>
    </div>

  </div>
</div>

<!-- Scripts para mejoras visuales -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  const form = document.getElementById('forgotForm');
  const submitBtn = document.getElementById('submitBtn');
  const spinner = document.getElementById('spinner');
  const emailInput = document.getElementById('email');
  const progressBar = document.getElementById('progressBar');
  const btnText = submitBtn?.querySelector('.btn-text');

  // Simular progreso cuando se escribe
  emailInput?.addEventListener('input', function() {
    const length = this.value.length;
    if (length > 0) {
      progressBar.style.width = Math.min(length * 2, 100) + '%';
    } else {
      progressBar.style.width = '0%';
    }
  });

  // Validación en tiempo real
  emailInput?.addEventListener('blur', function() {
    if (this.value && !this.validity.valid) {
      this.classList.add('is-invalid');
    } else {
      this.classList.remove('is-invalid');
    }
  });

  // Submit con efecto de carga
  form?.addEventListener('submit', function(e) {
    if (!form.checkValidity()) {
      e.preventDefault();
      e.stopPropagation();
      form.classList.add('was-validated');
      
      // SweetAlert para errores de validación
      if (window.Swal) {
        Swal.fire({
          icon: 'warning',
          title: 'Campo requerido',
          text: 'Por favor ingresa un correo electrónico válido.',
          confirmButtonColor: '#4CAF50',
          timer: 3000,
          timerProgressBar: true
        });
      }
      return;
    }

    if (submitBtn && spinner && btnText) {
      btnText.style.opacity = '0.5';
      spinner.classList.remove('d-none');
      submitBtn.disabled = true;
      
      // Efecto de progreso
      let width = 0;
      const interval = setInterval(() => {
        if (width >= 90) clearInterval(interval);
        progressBar.style.width = Math.min(width, 90) + '%';
        width += 10;
      }, 100);
    }
  });

  // Tooltips para características de seguridad
  const securityIcons = document.querySelectorAll('.security-features .col-4');
  securityIcons.forEach(icon => {
    icon.addEventListener('mouseenter', function() {
      this.classList.add('text-success');
    });
    icon.addEventListener('mouseleave', function() {
      this.classList.remove('text-success');
    });
  });
});
</script>

<!-- Estilos adicionales -->
<style>
.input-group-floating .form-floating > .form-control:focus ~ label,
.input-group-floating .form-floating > .form-control:not(:placeholder-shown) ~ label {
  color: #4CAF50 !important;
  opacity: 0.8;
  transform: scale(0.85) translateY(-0.75rem) translateX(0.15rem);
}

.input-group-floating .form-floating > .form-control:focus {
  border-bottom-color: #4CAF50 !important;
  box-shadow: none !important;
}

.instruction-box {
  border-left: 4px solid #4CAF50;
  animation: slideIn 0.5s ease-out;
}

@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateX(-10px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

.logo-hover {
  transition: transform 0.3s ease;
}

.logo-hover:hover {
  transform: scale(1.05);
}

.btn-success {
  background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
  border: none;
  transition: all 0.3s ease;
}

.btn-success:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(76, 175, 80, 0.3);
}

.btn-success:active {
  transform: translateY(0);
}

.progress {
  background-color: #e9ecef;
  border-radius: 2px;
  overflow: hidden;
}

.progress-bar {
  transition: width 0.3s ease;
}

.security-features .col-4 {
  transition: all 0.3s ease;
  cursor: default;
}

.security-features .col-4:hover {
  transform: translateY(-3px);
}

.bg-gradient {
  background: linear-gradient(135deg, #4CAF50 0%, #3d8b40 100%);
}
</style>