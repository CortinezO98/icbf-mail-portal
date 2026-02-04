<?php
declare(strict_types=1);

namespace App\Auth;

final class Auth
{
    public static function initSession(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        $cookieParams = session_get_cookie_params();
        session_name($config['session_name']);

        // Secure-ish defaults (in local dev, https might be false; keep secure=false for localhost)
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookieParams['path'] ?? '/',
            'domain' => $cookieParams['domain'] ?? '',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    public static function check(): bool
    {
        return !empty($_SESSION['user']['id']);
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function id(): ?int
    {
        return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    }

    public static function roles(): array
    {
        return $_SESSION['user']['roles'] ?? [];
    }

    public static function hasRole(string $roleCode): bool
    {
        return in_array($roleCode, self::roles(), true);
    }

    public static function login(array $user, array $roleCodes): void
    {
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'username' => $user['username'] ?? '',
            'email' => $user['email'] ?? '',
            'full_name' => $user['full_name'] ?? '',
            'roles' => $roleCodes,
        ];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
