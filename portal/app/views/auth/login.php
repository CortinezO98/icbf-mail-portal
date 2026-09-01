<?php
use App\Auth\Csrf;
use function App\Config\url;

$year = date('Y');

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
      <div class="card-header">
        <h5 class="mb-0 text-white">
          <i class="bi bi-person-lock me-2"></i>Inicio de Sesión
        </h5>
      </div>

      <div class="card-body">
        <?php if (!empty($error)): ?>
          <div class="alert alert-danger py-2 mb-3" role="alert">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars(url('/login'), ENT_QUOTES, 'UTF-8') ?>" id="loginForm" novalidate>
          <?php if ($recaptchaEnabled): ?>
          <input type="hidden" name="g-recaptcha-response" id="loginRecaptchaToken" value="">
          <?php endif; ?>
          <input
            type="hidden"
            name="_csrf"
            value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>"
          >

          <div class="mb-3">
            <label for="login" class="form-label">Usuario o correo</label>
            <input
              id="login"
              class="form-control"
              name="login"
              type="text"
              placeholder="Ingresa tu usuario"
              autocomplete="username"
              required
            >
          </div>

          <div class="mb-3">
            <label for="password" class="form-label">Contraseña</label>
            <input
              id="password"
              class="form-control"
              name="password"
              type="password"
              placeholder="********"
              autocomplete="current-password"
              required
            >
          </div>

          <div class="d-grid">
            <button type="submit" class="btn btn-brand" id="loginSubmitBtn">
              <i class="bi bi-box-arrow-in-right me-1"></i>Ingresar
            </button>
          </div>

          <div class="text-center mt-3">
            <a
              href="<?= htmlspecialchars(url('/forgot-password'), ENT_QUOTES, 'UTF-8') ?>"
              class="link-success text-decoration-none fw-semibold"
            >
              ¿Olvidaste tu contraseña?
            </a>
          </div>
        </form>
      </div>
    </div>

    <div class="login-footer">
      <small>© ICBF • IQ Outsourcing • <?= (int)$year ?></small>
    </div>

  </div>
</div>

<?php if ($recaptchaEnabled && $recaptchaSiteKey !== ''): ?>
<script
  src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars($recaptchaSiteKey, ENT_QUOTES, 'UTF-8') ?>"
></script>

<script>
(function () {
  const form = document.getElementById('loginForm');
  const tokenInput = document.getElementById('loginRecaptchaToken');
  const submitBtn = document.getElementById('loginSubmitBtn');
  const siteKey = <?= json_encode(
      $recaptchaSiteKey,
      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
  ) ?>;

  if (!form || !tokenInput || !siteKey) return;

  let recaptchaReadyToSubmit = false;

  form.addEventListener('submit', function (event) {
    if (recaptchaReadyToSubmit) return;

    event.preventDefault();

    if (submitBtn) submitBtn.disabled = true;

    if (
      !window.grecaptcha
    ) {
      if (submitBtn) submitBtn.disabled = false;
      alert('No fue posible cargar la validación de seguridad. Recarga la página e intenta nuevamente.');
      return;
    }

    grecaptcha.ready(function () {
      grecaptcha
        .execute(siteKey, { action: 'login' })
        .then(function (token) {
          tokenInput.value = token;
          recaptchaReadyToSubmit = true;

          // submit() evita pedir un segundo token en el mismo envío.
          form.submit();
        })
        .catch(function () {
          if (submitBtn) submitBtn.disabled = false;
          alert('No fue posible completar la validación de seguridad. Intenta nuevamente.');
        });
    });
  });
})();
</script>
<?php endif; ?>
