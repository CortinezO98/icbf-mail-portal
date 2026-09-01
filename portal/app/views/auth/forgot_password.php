<?php
use App\Auth\Csrf;
use function App\Config\url;

$year = date('Y');
$emailValue = htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8');

$recaptcha = is_array($recaptcha ?? null) ? $recaptcha : [];
$recaptchaEnabled = (bool)($recaptcha['enabled'] ?? false);
$recaptchaSiteKey = trim((string)($recaptcha['site_key'] ?? ''));
?>
<div class="login-wrap">
  <div class="login-col">

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

    <div class="login-title animate__animated animate__fadeInDown">
      ICBF • Gestión de Correo
    </div>

    <div class="card card-login shadow-lg animate__animated animate__fadeInUp">
      <div class="card-body">
        <div class="text-center mb-4">
          <h5 class="mb-1 text-success fw-bold">
            <i class="bi bi-shield-lock me-2"></i>Recuperación de acceso
          </h5>
          <p class="text-muted mb-0 small">
            Ingresa tu correo electrónico para restablecer tu contraseña.
          </p>
        </div>

        <?php if (!empty($message)): ?>
          <div class="alert alert-success py-2 mb-3 text-center" role="alert">
            <i class="bi bi-check-circle me-1"></i>
            <?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
          <div class="alert alert-danger py-2 mb-3 text-center" role="alert">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars(url('/forgot-password'), ENT_QUOTES, 'UTF-8') ?>" id="forgotForm" novalidate>
          <?php if ($recaptchaEnabled): ?>
          <input type="hidden" name="g-recaptcha-response" id="forgotRecaptchaToken" value="">
          <?php endif; ?>
          <input
            type="hidden"
            name="_csrf"
            value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>"
          >

          <div class="mb-3">
            <label for="email" class="form-label">Correo electrónico</label>
            <input
              id="email"
              class="form-control"
              name="email"
              type="email"
              placeholder="usuario@dominio.com"
              autocomplete="email"
              required
              value="<?= $emailValue ?>"
            >
            <div class="invalid-feedback">
              Por favor ingresa un correo electrónico válido.
            </div>
          </div>

          <div class="d-grid">
            <button type="submit" class="btn btn-brand" id="submitBtn">
              <span class="btn-label">
                <i class="bi bi-send-check me-1"></i>Enviar enlace de recuperación
              </span>
              <span class="spinner-border spinner-border-sm ms-2 d-none" id="submitSpinner" role="status" aria-hidden="true"></span>
            </button>
          </div>

          <div class="text-center mt-3">
            <a
              href="<?= htmlspecialchars(url('/login'), ENT_QUOTES, 'UTF-8') ?>"
              class="link-success text-decoration-none fw-semibold"
            >
              <i class="bi bi-arrow-left me-1"></i>Volver al inicio de sesión
            </a>
          </div>
        </form>

        <div class="row text-center mt-4 pt-3 border-top forgot-security-row">
          <div class="col-4">
            <i class="bi bi-shield-check text-success d-block mb-1"></i>
            <small class="text-muted">Enlace seguro</small>
          </div>
          <div class="col-4">
            <i class="bi bi-clock-history text-success d-block mb-1"></i>
            <small class="text-muted">Válido 30 min</small>
          </div>
          <div class="col-4">
            <i class="bi bi-incognito text-success d-block mb-1"></i>
            <small class="text-muted">Confidencial</small>
          </div>
        </div>
      </div>
    </div>

    <div class="login-footer text-center">
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
.login-footer.text-center {
  text-align: center;
}

.forgot-security-row i {
  font-size: 1.15rem;
}

.card-login .card-body .text-center h5 {
  font-size: 1.2rem;
}

.card-login .card-body .text-center p {
  line-height: 1.4;
}

@media (max-width: 576px) {
  .forgot-security-row small {
    font-size: 0.72rem;
  }
}
</style>

<?php if ($recaptchaEnabled && $recaptchaSiteKey !== ''): ?>
<script
  src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars($recaptchaSiteKey, ENT_QUOTES, 'UTF-8') ?>"
></script>

<script>
(function () {
  const form = document.getElementById('forgotForm');
  const tokenInput = document.getElementById('forgotRecaptchaToken');
  const submitBtn = document.getElementById('submitBtn');
  const submitSpinner = document.getElementById('submitSpinner');

  const siteKey = <?= json_encode(
      $recaptchaSiteKey,
      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
  ) ?>;

  if (!form || !tokenInput || !siteKey) return;

  let recaptchaReadyToSubmit = false;

  form.addEventListener('submit', function (event) {
    if (recaptchaReadyToSubmit || event.defaultPrevented) return;

    event.preventDefault();

    if (submitBtn) submitBtn.disabled = true;

    if (
      !window.grecaptcha
    ) {
      if (submitBtn) submitBtn.disabled = false;
      if (submitSpinner) submitSpinner.classList.add('d-none');

      alert('No fue posible cargar la validación de seguridad. Recarga la página e intenta nuevamente.');
      return;
    }

    grecaptcha.ready(function () {
      grecaptcha
        .execute(siteKey, { action: 'password_reset' })
        .then(function (token) {
          tokenInput.value = token;
          recaptchaReadyToSubmit = true;
          form.submit();
        })
        .catch(function () {
          if (submitBtn) submitBtn.disabled = false;
          if (submitSpinner) submitSpinner.classList.add('d-none');

          alert('No fue posible completar la validación de seguridad. Intenta nuevamente.');
        });
    });
  });
})();
</script>
<?php endif; ?>
