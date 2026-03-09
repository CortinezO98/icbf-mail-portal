<?php
use App\Auth\Csrf;
use function App\Config\url;

$year = date('Y');
$emailValue = htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<div class="login-wrap">
  <div class="login-col">

    <!-- LOGOS -->
    <div class="login-logos animate__animated animate__fadeInDown">
      <img
        src="<?= htmlspecialchars(url('/assets/img/logo_icbf.png'), ENT_QUOTES, 'UTF-8') ?>"
        alt="Logo ICBF"
        class="img-fluid"
      >
      <img
        src="<?= htmlspecialchars(url('/assets/img/logo_iq.png'), ENT_QUOTES, 'UTF-8') ?>"
        alt="Logo IQ Outsourcing"
        class="img-fluid"
        style="max-height:70px;"
      >
    </div>

    <!-- TARJETA PRINCIPAL (IDÉNTICA AL LOGIN) -->
    <div class="card card-login shadow-lg animate__animated animate__fadeInUp">
      <div class="card-header">
        <h5 class="mb-0 text-white">
          <i class="bi bi-envelope-lock me-2"></i>Recuperación de acceso
        </h5>
      </div>

      <div class="card-body">
        <!-- ALERTAS -->
        <?php if (!empty($message)): ?>
          <div class="alert alert-success py-2 mb-3" role="alert">
            <i class="bi bi-check-circle me-1"></i>
            <?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
          <div class="alert alert-danger py-2 mb-3" role="alert">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <!-- TEXTO INFORMATIVO (ESTILO CONSISTENTE) -->
        <div class="text-muted small mb-4 text-center">
          <i class="bi bi-info-circle me-1"></i>
          Te enviaremos un enlace seguro a tu correo para restablecer tu contraseña.
        </div>

        <!-- FORMULARIO -->
        <form method="post" action="<?= htmlspecialchars(url('/forgot-password'), ENT_QUOTES, 'UTF-8') ?>" id="forgotForm" novalidate>
          <input
            type="hidden"
            name="_csrf"
            value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>"
          >

          <!-- CAMPO DE EMAIL (IGUAL AL LOGIN) -->
          <div class="mb-3">
            <label for="email" class="form-label">Correo electrónico</label>
            <input
              id="email"
              name="email"
              type="email"
              class="form-control"
              placeholder="usuario@dominio.com"
              required
              autocomplete="email"
              value="<?= $emailValue ?>"
            >
            <div class="invalid-feedback">
              Por favor ingresa un correo electrónico válido.
            </div>
          </div>

          <!-- BOTÓN PRINCIPAL (IGUAL AL LOGIN) -->
          <div class="d-grid">
            <button type="submit" class="btn btn-brand" id="submitBtn">
              <span class="btn-label">
                <i class="bi bi-send-check me-1"></i>Enviar enlace de recuperación
              </span>
              <span class="spinner-border spinner-border-sm ms-2 d-none" id="submitSpinner" role="status"></span>
            </button>
          </div>

          <!-- ENLACE VOLVER (IGUAL AL LOGIN) -->
          <div class="text-center mt-3">
            <a
              href="<?= htmlspecialchars(url('/login'), ENT_QUOTES, 'UTF-8') ?>"
              class="link-success text-decoration-none fw-semibold"
            >
              <i class="bi bi-arrow-left me-1"></i>Volver al inicio de sesión
            </a>
          </div>
        </form>

        <!-- CARACTERÍSTICAS DE SEGURIDAD (ESTILO SUTIL) -->
        <div class="d-flex justify-content-center gap-4 mt-4 pt-2 small text-muted">
          <div class="text-center">
            <i class="bi bi-shield-check d-block fs-5 text-success mb-1"></i>
            <span>Enlace seguro</span>
          </div>
          <div class="text-center">
            <i class="bi bi-clock-history d-block fs-5 text-success mb-1"></i>
            <span>Válido 30 min</span>
          </div>
          <div class="text-center">
            <i class="bi bi-incognito d-block fs-5 text-success mb-1"></i>
            <span>Confidencial</span>
          </div>
        </div>
      </div>
    </div>

    <!-- FOOTER CENTRADO (EXACTAMENTE COMO EL LOGIN) -->
    <div class="login-footer text-center">
      <small>© <?= (int)$year ?> ICBF • IQ Outsourcing</small>
    </div>

  </div>
</div>

<!-- SCRIPT PARA VALIDACIÓN Y EFECTOS -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('forgotForm');
  const email = document.getElementById('email');
  const submitBtn = document.getElementById('submitBtn');
  const submitSpinner = document.getElementById('submitSpinner');
  const btnLabel = submitBtn ? submitBtn.querySelector('.btn-label') : null;

  // Validación en tiempo real
  if (email) {
    email.addEventListener('input', function () {
      if (this.classList.contains('is-invalid') && this.checkValidity()) {
        this.classList.remove('is-invalid');
      }
    });

    email.addEventListener('blur', function () {
      if (this.value.trim() !== '' && !this.checkValidity()) {
        this.classList.add('is-invalid');
      } else {
        this.classList.remove('is-invalid');
      }
    });
  }

  // Submit del formulario
  if (form) {
    form.addEventListener('submit', function (e) {
      if (!form.checkValidity()) {
        e.preventDefault();
        e.stopPropagation();
        form.classList.add('was-validated');
        
        if (email && !email.checkValidity()) {
          email.classList.add('is-invalid');
        }
        
        return;
      }

      // Efecto de carga
      if (submitBtn && submitSpinner && btnLabel) {
        submitBtn.disabled = true;
        submitSpinner.classList.remove('d-none');
        btnLabel.style.opacity = '0.8';
      }
    });
  }

  // Limpiar estado si la página se recarga
  window.addEventListener('pageshow', function() {
    if (submitBtn && submitSpinner && btnLabel) {
      submitBtn.disabled = false;
      submitSpinner.classList.add('d-none');
      btnLabel.style.opacity = '1';
    }
  });
});
</script>

<!-- ESTILOS ADICIONALES PARA MANTENER CONSISTENCIA -->
<style>
/* Mantener exactamente los mismos estilos del login */
.card-login {
  border: 0;
  background: var(--bg-soft, #f6f8fb);
  border-radius: 18px;
  overflow: hidden;
}

.card-login .card-header {
  background: var(--color-primary, #4CAF50);
  color: #fff;
  text-align: center;
  border-bottom: 0;
  padding: 14px 16px;
}

.card-login .card-body {
  padding: 22px;
}

.form-label {
  font-weight: 600;
}

.btn-brand {
  --bs-btn-color: #fff;
  --bs-btn-bg: var(--color-primary, #4CAF50);
  --bs-btn-border-color: var(--color-primary, #4CAF50);
  --bs-btn-hover-color: #fff;
  --bs-btn-hover-bg: var(--color-primary-dark, #3f9c44);
  --bs-btn-hover-border-color: var(--color-primary-dark, #3f9c44);
}

.login-footer {
  text-align: center;
  margin-top: 12px;
  color: #6c757d;
  font-size: 12px;
}

/* Asegurar que el footer esté centrado */
.login-footer small {
  display: block;
  text-align: center;
}

/* Estilos para las características de seguridad */
.gap-4 {
  gap: 1.5rem;
}

.text-muted i {
  transition: transform 0.2s ease;
}

.text-muted i:hover {
  transform: translateY(-2px);
}

/* Responsive */
@media (max-width: 576px) {
  .card-login .card-body {
    padding: 18px;
  }
  
  .gap-4 {
    gap: 1rem;
  }
}
</style>