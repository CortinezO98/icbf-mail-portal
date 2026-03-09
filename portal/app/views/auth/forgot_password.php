<?php
use App\Auth\Csrf;
use function App\Config\url;
?>

<div class="login-wrap">
  <div class="login-col">
    <div class="card card-login shadow-lg">
      <div class="card-header">
        <h5 class="mb-0 text-white">
          <i class="bi bi-envelope-lock me-2"></i>Recuperar contraseña
        </h5>
      </div>

      <div class="card-body">
        <?php if (!empty($message)): ?>
          <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars(url('/forgot-password')) ?>">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(Csrf::token()) ?>">

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
            >
          </div>

          <div class="d-grid">
            <button type="submit" class="btn btn-brand">
              Enviar enlace de recuperación
            </button>
          </div>

          <div class="text-center mt-3">
            <a href="<?= htmlspecialchars(url('/login')) ?>" class="text-decoration-none">
              Volver al inicio de sesión
            </a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>