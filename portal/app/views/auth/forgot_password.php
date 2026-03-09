<?php
use App\Auth\Csrf;
use function App\Config\url;

$year = date('Y');
$emailValue = htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<div class="login-wrap forgot-wrap">
  <div class="login-col forgot-col">

    <div class="login-logos forgot-logos animate__animated animate__fadeInDown">
      <img
        src="<?= htmlspecialchars(url('/assets/img/logo_icbf.png'), ENT_QUOTES, 'UTF-8') ?>"
        alt="Logo ICBF"
        class="forgot-logo-main"
      >
      <img
        src="<?= htmlspecialchars(url('/assets/img/logo_iq.png'), ENT_QUOTES, 'UTF-8') ?>"
        alt="Logo IQ Outsourcing"
        class="forgot-logo-secondary"
      >
    </div>

    <div class="login-title animate__animated animate__fadeInDown">
      Recuperación de acceso
    </div>

    <div class="card card-login forgot-card shadow-lg animate__animated animate__fadeInUp">
      <div class="card-header forgot-card-header">
        <h5 class="mb-0 text-white">
          <i class="bi bi-envelope-lock me-2"></i>Restablecer contraseña
        </h5>
      </div>

      <div class="card-body p-4 p-md-4">
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

        <div class="forgot-info-box mb-4">
          <div class="d-flex align-items-start">
            <i class="bi bi-info-circle-fill forgot-info-icon me-2"></i>
            <div>
              <div class="forgot-info-title">Recuperación segura</div>
              <div class="forgot-info-text">
                Ingresa tu correo electrónico y te enviaremos un enlace seguro para restablecer tu contraseña.
              </div>
            </div>
          </div>
        </div>

        <form method="post" action="<?= htmlspecialchars(url('/forgot-password'), ENT_QUOTES, 'UTF-8') ?>" id="forgotForm" novalidate>
          <input
            type="hidden"
            name="_csrf"
            value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>"
          >

          <div class="mb-3">
            <label for="email" class="form-label">Correo electrónico</label>
            <div class="input-group input-group-lg">
              <span class="input-group-text forgot-input-icon">
                <i class="bi bi-envelope"></i>
              </span>
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
          </div>

          <div class="d-grid gap-2 mt-4">
            <button type="submit" class="btn btn-brand forgot-submit-btn" id="submitBtn">
              <span class="btn-label">
                <i class="bi bi-send-check me-2"></i>Enviar enlace de recuperación
              </span>
              <span class="spinner-border spinner-border-sm ms-2 d-none" id="submitSpinner" role="status" aria-hidden="true"></span>
            </button>

            <a
              href="<?= htmlspecialchars(url('/login'), ENT_QUOTES, 'UTF-8') ?>"
              class="btn btn-outline-secondary forgot-back-btn"
            >
              <i class="bi bi-arrow-left me-2"></i>Volver al inicio de sesión
            </a>
          </div>
        </form>

        <div class="forgot-security mt-4 pt-3">
          <div class="row g-3 text-center">
            <div class="col-4">
              <div class="forgot-security-item">
                <i class="bi bi-shield-check forgot-security-icon"></i>
                <small>Enlace seguro</small>
              </div>
            </div>
            <div class="col-4">
              <div class="forgot-security-item">
                <i class="bi bi-clock-history forgot-security-icon"></i>
                <small>Válido 30 min</small>
              </div>
            </div>
            <div class="col-4">
              <div class="forgot-security-item">
                <i class="bi bi-incognito forgot-security-icon"></i>
                <small>Confidencial</small>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="login-footer animate__animated animate__fadeInUp">
      <small>© <?= (int)$year ?> ICBF • IQ Outsourcing</small>
    </div>

  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('forgotForm');
  const email = document.getElementById('email');
  const submitBtn = document.getElementById('submitBtn');
  const submitSpinner = document.getElementById('submitSpinner');
  const btnLabel = submitBtn ? submitBtn.querySelector('.btn-label') : null;

  if (email) {
    email.addEventListener('input', function () {
      if (email.classList.contains('is-invalid') && email.checkValidity()) {
        email.classList.remove('is-invalid');
      }
    });

    email.addEventListener('blur', function () {
      if (email.value.trim() !== '' && !email.checkValidity()) {
        email.classList.add('is-invalid');
      } else {
        email.classList.remove('is-invalid');
      }
    });
  }

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

      if (submitBtn && submitSpinner && btnLabel) {
        submitBtn.disabled = true;
        submitSpinner.classList.remove('d-none');
        btnLabel.style.opacity = '0.85';
      }
    });
  }
});
</script>

<style>
.forgot-wrap {
  min-height: calc(100vh - 80px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 32px 16px;
}

.forgot-col {
  width: 100%;
  max-width: 540px;
  margin: 0 auto;
}

.forgot-logos {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 18px;
  margin-bottom: 18px;
}

.forgot-logo-main {
  display: block;
  width: auto;
  max-width: 220px;
  max-height: 130px;
  object-fit: contain;
}

.forgot-logo-secondary {
  display: block;
  width: auto;
  max-width: 120px;
  max-height: 60px;
  object-fit: contain;
}

.forgot-card {
  border: 0;
  border-radius: 18px;
  overflow: hidden;
}

.forgot-card-header {
  background: linear-gradient(135deg, #4CAF50 0%, #3f9644 100%);
  border-bottom: 0;
}

.forgot-info-box {
  background: #f8fafc;
  border: 1px solid #e9ecef;
  border-left: 4px solid #4CAF50;
  border-radius: 12px;
  padding: 14px 16px;
}

.forgot-info-icon {
  color: #4CAF50;
  font-size: 1rem;
  margin-top: 2px;
}

.forgot-info-title {
  font-weight: 600;
  color: #1f2937;
  margin-bottom: 2px;
}

.forgot-info-text {
  color: #6b7280;
  font-size: 0.95rem;
  line-height: 1.45;
}

.forgot-input-icon {
  background: #fff;
  border-right: 0;
}

.input-group .form-control {
  border-left: 0;
}

.input-group .form-control:focus {
  box-shadow: none;
}

.input-group:focus-within {
  border-radius: 0.5rem;
}

.forgot-submit-btn {
  min-height: 48px;
  font-weight: 600;
}

.forgot-back-btn {
  min-height: 46px;
}

.forgot-security {
  border-top: 1px solid #e9ecef;
}

.forgot-security-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  color: #6c757d;
  gap: 6px;
}

.forgot-security-icon {
  color: #4CAF50;
  font-size: 1.3rem;
}

@media (max-width: 576px) {
  .forgot-wrap {
    padding: 20px 12px;
    min-height: auto;
  }

  .forgot-logos {
    gap: 12px;
    margin-bottom: 14px;
  }

  .forgot-logo-main {
    max-width: 170px;
    max-height: 95px;
  }

  .forgot-logo-secondary {
    max-width: 90px;
    max-height: 48px;
  }

  .forgot-col {
    max-width: 100%;
  }

  .forgot-security small {
    font-size: 0.72rem;
  }
}
</style>