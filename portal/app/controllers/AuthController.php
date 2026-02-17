<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Repos\UsersRepo;
use App\Auth\Auth;
use App\Auth\Csrf;

use function App\Config\url;

final class AuthController
{
    private UsersRepo $usersRepo;

    public function __construct(private PDO $pdo, private array $config)
    {
        // ✅ Asegura sesión iniciada SIEMPRE para este controlador
        Auth::initSession($this->config);

        $this->usersRepo = new UsersRepo($pdo);
    }

    public function showLogin(): void
    {
        $error = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash_error']);
        $this->render('auth/login.php', ['error' => $error]);
    }

    public function login(): void
    {
        // ✅ Asegura sesión iniciada incluso si algún flujo no pasó por __construct
        Auth::initSession($this->config);

        Csrf::validate($_POST['_csrf'] ?? null);

        $login = trim((string)($_POST['login'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($login === '' || $password === '') {
            $_SESSION['_flash_error'] = "Usuario y contraseña son obligatorios.";
            header('Location: ' . url('/login'));
            exit;
        }

        $user = $this->usersRepo->findByUsernameOrEmail($login);
        if (!$user || !password_verify($password, (string)($user['password_hash'] ?? ''))) {
            $_SESSION['_flash_error'] = "Credenciales inválidas.";
            header('Location: ' . url('/login'));
            exit;
        }

        // ✅ Trae roles (robusto) y si no hay, puedes definir un default si deseas
        $roles = $this->usersRepo->rolesForUser((int)$user['id']);

        // (Opcional) Si quieres forzar que TODO usuario tenga al menos AGENTE:
        // if (empty($roles)) $roles = ['AGENTE'];

        // ✅ Seguridad: evita session fixation
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        Auth::login($user, $roles);

        header('Location: ' . url('/cases'));
        exit;
    }

    public function logout(): void
    {
        Auth::initSession($this->config);

        Csrf::validate($_POST['_csrf'] ?? null);
        Auth::logout();
        header('Location: ' . url('/login'));
        exit;
    }

    private function render(string $view, array $params = []): void
    {
        extract($params, EXTR_SKIP);
        $viewPath = dirname(__DIR__) . '/views/' . $view;
        include dirname(__DIR__) . '/views/layout.php';
    }
}
