<?php
declare(strict_types=1);

namespace App\Auth;

final class Csrf
{
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException("Session not started");
        }
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['_csrf'];
    }

    public static function validate(?string $token): void
    {
        $expected = $_SESSION['_csrf'] ?? null;
        if (!$expected || !$token || !hash_equals($expected, $token)) {
            http_response_code(403);
            echo "CSRF validation failed";
            exit;
        }
    }
}
