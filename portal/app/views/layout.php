<?php
use App\Auth\Auth;
use App\Auth\Csrf;
use function App\Config\url;

// $viewPath is defined by controller render()
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ICBF - Portal</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?= htmlspecialchars(url('/cases')) ?>">ICBF Mail</a>

    <div class="d-flex align-items-center gap-3">
      <?php if (Auth::check()): ?>
        <span class="text-white small">
          <?= htmlspecialchars((Auth::user()['full_name'] ?? Auth::user()['username'] ?? '')) ?>
          (<?= htmlspecialchars(implode(',', Auth::roles())) ?>)
        </span>

        <form method="post" action="<?= htmlspecialchars(url('/logout')) ?>">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(Csrf::token()) ?>">
          <button class="btn btn-outline-light btn-sm" type="submit">Salir</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</nav>

<main class="container py-4">
  <?php include $viewPath; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
