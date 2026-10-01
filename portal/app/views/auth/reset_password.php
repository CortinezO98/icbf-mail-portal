<?php
use App\Auth\Csrf;
use function App\Config\url;
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

    <div class="card card-login shadow-lg">
      <div class="card-header">
        <h5 class="mb-0 text-white">
          <i class="bi bi-shield-lock me-2"></i>Nueva contraseña
        </h5>
      </div>

      <div class="card-body">
        <?php if (!empty($error)): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars(url('/reset-password')) ?>" id="resetPasswordForm" novalidate>
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(Csrf::token()) ?>">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token ?? '') ?>">

          <div class="mb-3">
            <label for="password" class="form-label">Nueva contraseña</label>
            <div class="input-group">
              <input
                id="password"
                name="password"
                type="password"
                class="form-control"
                required
                minlength="8"
                autocomplete="new-password"
                aria-describedby="passwordRequirements"
              >
              <button
                type="button"
                class="btn btn-outline-secondary toggle-password-btn"
                data-target="password"
                aria-label="Mostrar u ocultar contraseña"
                tabindex="-1"
              >
                <i class="bi bi-eye"></i>
              </button>
            </div>

            <!-- Medidor de fortaleza -->
            <div class="password-strength-meter mt-2" aria-hidden="true">
              <div class="password-strength-bar" id="strengthBar"></div>
            </div>
            <div class="small mt-1" id="strengthLabel">&nbsp;</div>

            <!-- Checklist de requisitos - refleja exactamente la regla que
                 valida el backend (AuthController::isStrongPassword), no
                 criterios distintos. Sirve como guía en vivo, pero el
                 backend siempre vuelve a validar de forma independiente. -->
            <ul class="password-requirements list-unstyled small mt-2 mb-0" id="passwordRequirements">
              <li data-rule="length"><i class="bi bi-circle"></i> Mínimo 8 caracteres</li>
              <li data-rule="lower"><i class="bi bi-circle"></i> Una letra minúscula</li>
              <li data-rule="upper"><i class="bi bi-circle"></i> Una letra mayúscula</li>
              <li data-rule="number"><i class="bi bi-circle"></i> Un número</li>
              <li data-rule="symbol"><i class="bi bi-circle"></i> Un símbolo (ej. ! @ # $ %)</li>
            </ul>
          </div>

          <div class="mb-3">
            <label for="password_confirm" class="form-label">Confirmar contraseña</label>
            <div class="input-group">
              <input
                id="password_confirm"
                name="password_confirm"
                type="password"
                class="form-control"
                required
                autocomplete="new-password"
              >
              <button
                type="button"
                class="btn btn-outline-secondary toggle-password-btn"
                data-target="password_confirm"
                aria-label="Mostrar u ocultar contraseña"
                tabindex="-1"
              >
                <i class="bi bi-eye"></i>
              </button>
            </div>
            <div class="small mt-1" id="matchLabel">&nbsp;</div>
          </div>

          <div class="d-grid">
            <button type="submit" class="btn btn-brand" id="submitBtn" disabled>Actualizar contraseña</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<style>
.password-strength-meter {
  height: 6px;
  border-radius: 4px;
  background-color: #e9ecef;
  overflow: hidden;
}
.password-strength-bar {
  height: 100%;
  width: 0%;
  border-radius: 4px;
  transition: width 0.2s ease-in-out, background-color 0.2s ease-in-out;
  background-color: #dc3545;
}
.password-requirements li {
  transition: color 0.15s ease-in-out;
  color: #6c757d;
}
.password-requirements li.rule-met {
  color: #198754;
}
.password-requirements li i {
  margin-right: 6px;
}
.toggle-password-btn {
  border-color: #ced4da;
}
</style>

<script>
(function () {
  var passwordInput = document.getElementById('password');
  var confirmInput = document.getElementById('password_confirm');
  var strengthBar = document.getElementById('strengthBar');
  var strengthLabel = document.getElementById('strengthLabel');
  var matchLabel = document.getElementById('matchLabel');
  var submitBtn = document.getElementById('submitBtn');
  var requirementItems = document.querySelectorAll('#passwordRequirements li');

  // Toggle mostrar/ocultar - aplica a cualquier input marcado con data-target
  document.querySelectorAll('.toggle-password-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var targetId = btn.getAttribute('data-target');
      var input = document.getElementById(targetId);
      var icon = btn.querySelector('i');
      if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
      } else {
        input.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
      }
    });
  });

  // Reglas idénticas a AuthController::isStrongPassword():
  // /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/
  function evaluateRules(pwd) {
    return {
      length: pwd.length >= 8,
      lower: /[a-z]/.test(pwd),
      upper: /[A-Z]/.test(pwd),
      number: /\d/.test(pwd),
      symbol: /[\W_]/.test(pwd),
    };
  }

  function isFullyStrong(rules) {
    return rules.length && rules.lower && rules.upper && rules.number && rules.symbol;
  }

  function updateStrengthUI() {
    var pwd = passwordInput.value;
    var rules = evaluateRules(pwd);
    var metCount = Object.values(rules).filter(Boolean).length;

    requirementItems.forEach(function (li) {
      var rule = li.getAttribute('data-rule');
      var icon = li.querySelector('i');
      if (rules[rule]) {
        li.classList.add('rule-met');
        icon.classList.remove('bi-circle');
        icon.classList.add('bi-check-circle-fill');
      } else {
        li.classList.remove('rule-met');
        icon.classList.remove('bi-check-circle-fill');
        icon.classList.add('bi-circle');
      }
    });

    var pct = pwd.length === 0 ? 0 : (metCount / 5) * 100;
    strengthBar.style.width = pct + '%';

    if (pwd.length === 0) {
      strengthBar.style.backgroundColor = '#e9ecef';
      strengthLabel.textContent = '\u00A0';
    } else if (metCount <= 2) {
      strengthBar.style.backgroundColor = '#dc3545';
      strengthLabel.textContent = 'Contraseña débil';
      strengthLabel.className = 'small mt-1 text-danger';
    } else if (metCount <= 4) {
      strengthBar.style.backgroundColor = '#fd7e14';
      strengthLabel.textContent = 'Contraseña media';
      strengthLabel.className = 'small mt-1 text-warning';
    } else {
      strengthBar.style.backgroundColor = '#198754';
      strengthLabel.textContent = 'Contraseña fuerte';
      strengthLabel.className = 'small mt-1 text-success';
    }

    updateMatchUI();
    updateSubmitState(rules);
  }

  function updateMatchUI() {
    if (confirmInput.value.length === 0) {
      matchLabel.textContent = '\u00A0';
      matchLabel.className = 'small mt-1';
      return;
    }
    if (passwordInput.value === confirmInput.value) {
      matchLabel.textContent = 'Las contraseñas coinciden';
      matchLabel.className = 'small mt-1 text-success';
    } else {
      matchLabel.textContent = 'Las contraseñas no coinciden';
      matchLabel.className = 'small mt-1 text-danger';
    }
  }

  function updateSubmitState(rules) {
    var strong = isFullyStrong(rules);
    var match = confirmInput.value.length > 0 && passwordInput.value === confirmInput.value;
    submitBtn.disabled = !(strong && match);
  }

  passwordInput.addEventListener('input', updateStrengthUI);
  confirmInput.addEventListener('input', function () {
    updateMatchUI();
    updateSubmitState(evaluateRules(passwordInput.value));
  });

  // El backend es la fuente de verdad real (defensa en profundidad) -
  // este chequeo en el submit es solo para evitar un envío innecesario
  // si algo quedó desincronizado (ej. autocompletado del navegador).
  document.getElementById('resetPasswordForm').addEventListener('submit', function (e) {
    var rules = evaluateRules(passwordInput.value);
    var match = passwordInput.value === confirmInput.value;
    if (!isFullyStrong(rules) || !match) {
      e.preventDefault();
      updateStrengthUI();
    }
  });
})();
</script>
