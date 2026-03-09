<?php
use App\Auth\Csrf;
use function App\Config\url;
?>

<div class="login-wrap">
  <div class="login-col">
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

        <form method="post" action="<?= htmlspecialchars(url('/reset-password')) ?>">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(Csrf::token()) ?>">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token ?? '') ?>">

          <div class="mb-3">
            <label for="password" class="form-label">Nueva contraseña</label>
            <input id="password" name="password" type="password" class="form-control" required autocomplete="new-password">
          </div>

          <div class="mb-3">
            <label for="password_confirm" class="form-label">Confirmar contraseña</label>
            <input id="password_confirm" name="password_confirm" type="password" class="form-control" required autocomplete="new-password">
          </div>

          <div class="d-grid">
            <button type="submit" class="btn btn-brand">Actualizar contraseña</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>