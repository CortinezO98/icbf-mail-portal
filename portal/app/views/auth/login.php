<?php
use App\Auth\Csrf;
use function App\Config\url;

$year = date('Y');
?>
<div class="login-wrap">
  <div class="login-col">

    <div class="login-logos animate__animated animate__fadeInDown">
      <img src="<?= htmlspecialchars(url('/assets/img/logo_icbf.png')) ?>" alt="Logo ICBF" class="img-fluid" >
      <img src="<?= htmlspecialchars(url('/assets/img/logo_iq.png')) ?>" alt="Logo IQ Outsourcing" class="img-fluid" style="max-height:70px;">
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
          <div class="alert alert-danger py-2 mb-3">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars(url('/login')) ?>" novalidate>
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(Csrf::token()) ?>">

          <div class="mb-3">
            <label for="login" class="form-label">Usuario o correo</label>
            <input
              id="login"
              class="form-control"
              name="login"
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
            <button type="submit" class="btn btn-brand">
              <i class="bi bi-box-arrow-in-right me-1"></i>Ingresar
            </button>
          </div>
        </form>
      </div>
    </div>

    <div class="login-footer">
      <small>© ICBF • IQ Outsourcing • <?= (int)$year ?></small>
    </div>

  </div>
</div>
