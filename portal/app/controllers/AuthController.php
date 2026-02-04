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
        Csrf::validate($_POST['_csrf'] ?? null);

        $login = trim((string)($_POST['login'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($login === '' || $password === '') {
            $_SESSION['_flash_error'] = "Usuario y contraseña son obligatorios.";
            header('Location: ' . url('/login'));
            exit;
        }

        $user = $this->usersRepo->findByUsernameOrEmail($login);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $_SESSION['_flash_error'] = "Credenciales inválidas.";
            header('Location: ' . url('/login'));
            exit;
        }

        $roles = $this->usersRepo->rolesForUser((int)$user['id']);
        Auth::login($user, $roles);

        header('Location: ' . url('/cases'));
        exit;
    }

    public function logout(): void
    {
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
