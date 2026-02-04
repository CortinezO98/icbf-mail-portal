<?php
use App\Auth\Csrf;
use function App\Config\url;
?>
<div class="row justify-content-center">
  <div class="col-md-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h4 class="mb-3">Iniciar sesión</h4>

        <?php if (!empty($error)): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars(url('/login')) ?>">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(Csrf::token()) ?>">

          <div class="mb-3">
            <label class="form-label">Usuario o correo</label>
            <input class="form-control" name="login" autocomplete="username" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Contraseña</label>
            <input class="form-control" name="password" type="password" autocomplete="current-password" required>
          </div>

          <button class="btn btn-primary w-100" type="submit">Entrar</button>
        </form>
      </div>
    </div>
  </div>
</div>
