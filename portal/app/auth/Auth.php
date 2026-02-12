<?php
declare(strict_types=1);

namespace App\Auth;

final class Auth
{
    public static function initSession(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        $cookieParams = session_get_cookie_params();
        session_name($config['session_name'] ?? 'APPSESSID');

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

    /**
     * ✅ Normaliza un role code: trim + strtoupper
     */
    private static function normRole(string $roleCode): string
    {
        return strtoupper(trim($roleCode));
    }

    /**
     * ✅ Devuelve roles siempre como array limpio (normalizados, únicos)
     */
    public static function roles(): array
    {
        $roles = $_SESSION['user']['roles'] ?? [];

        // si por algún motivo llega como string "ADMIN,AGENTE"
        if (is_string($roles)) {
            $roles = preg_split('/[,;|]/', $roles) ?: [];
        }

        if (!is_array($roles)) {
            return [];
        }

        $roles = array_map(
            fn($r) => self::normRole((string)$r),
            $roles
        );

        // remove vacíos + únicos
        $roles = array_values(array_unique(array_filter($roles, fn($r) => $r !== '')));

        // persistimos ya normalizado para que todo el sistema lo lea bien
        $_SESSION['user']['roles'] = $roles;

        return $roles;
    }

    /**
     * ✅ Case-insensitive y tolerante a espacios
     */
    public static function hasRole(string $roleCode): bool
    {
        $needle = self::normRole($roleCode);
        return in_array($needle, self::roles(), true);
    }

    /**
     * ✅ Útil para validaciones rápidas
     */
    public static function hasAnyRole(array $roleCodes): bool
    {
        foreach ($roleCodes as $code) {
            if (self::hasRole((string)$code)) return true;
        }
        return false;
    }

    public static function isAdmin(): bool
    {
        return self::hasRole('ADMIN');
    }

    public static function isSupervisor(): bool
    {
        return self::hasAnyRole(['SUPERVISOR', 'ADMIN']);
    }

    public static function isAgent(): bool
    {
        return self::hasRole('AGENTE');
    }

    /**
     * ✅ Login guarda roles normalizados
     */
    public static function login(array $user, array $roleCodes): void
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            fn($r) => self::normRole((string)$r),
            $roleCodes
        ), fn($r) => $r !== '')));

        $_SESSION['user'] = [
            'id' => (int)($user['id'] ?? 0),
            'username' => (string)($user['username'] ?? ''),
            'email' => (string)($user['email'] ?? ''),
            'full_name' => (string)($user['full_name'] ?? ''),
            'roles' => $normalized,
        ];
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                !empty($params['secure']),
                !empty($params['httponly'])
            );
        }

        session_destroy();
    }
}
