<?php
use App\Auth\Auth;
use App\Auth\Csrf;
use function App\Config\url;

// $viewPath is defined by controller render()
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ICBF - Portal</title>

  <!-- CSS (SIEMPRE en HEAD) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">

  <!-- App styles -->
  <link href="<?= htmlspecialchars(\App\Config\url('/assets/css/app.css?v=1')) ?>" rel="stylesheet">
</head>

<body class="bg-light <?= $isLogin ? 'page-login' : 'page-app' ?>">

<?php
// Ocultar navbar en pantalla de login (similar a tu Django)
$isLogin = str_ends_with($path, '/login') || $path === '/login';
?>

<?php if (!$isLogin): ?>
  <nav class="navbar navbar-expand-lg navbar-dark" style="background:#4CAF50;">
    <div class="container-fluid">
      <a class="navbar-brand fw-semibold" href="<?= htmlspecialchars(url('/cases')) ?>">ICBF Mail</a>

      <div class="d-flex align-items-center gap-3">
        <?php if (Auth::check()): ?>
          <span class="text-white small">
            <?= htmlspecialchars((Auth::user()['full_name'] ?? Auth::user()['username'] ?? '')) ?>
            <span class="opacity-75">(<?= htmlspecialchars(implode(',', Auth::roles())) ?>)</span>
          </span>

          <form method="post" action="<?= htmlspecialchars(url('/logout')) ?>">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(Csrf::token()) ?>">
            <button class="btn btn-outline-light btn-sm" type="submit">
              <i class="bi bi-box-arrow-right me-1"></i>Salir
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </nav>
<?php endif; ?>

<main class="<?= $isLogin ? '' : 'container py-4' ?>">
  <?php include $viewPath; ?>
</main>

<!-- JS al final (opcional) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
