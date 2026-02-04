<?php
declare(strict_types=1);

namespace App\Config;

function load_config(): array
{
    // reads portal/.env.local if exists
    $envFile = dirname(__DIR__, 2) . '/.env.local';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!str_contains($line, '=')) continue;

            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);

            if ($k !== '' && getenv($k) === false) {
                putenv($k . '=' . $v);
                $_ENV[$k] = $v;
            }
        }
    }

    $basePath = getenv('PORTAL_BASE_PATH') ?: '';

    return [
        'db' => [
            'host' => getenv('PORTAL_DB_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('PORTAL_DB_PORT') ?: 3306),
            'name' => getenv('PORTAL_DB_NAME') ?: 'icbf_mail',
            'user' => getenv('PORTAL_DB_USER') ?: 'root',
            'pass' => getenv('PORTAL_DB_PASSWORD') ?: '',
        ],
        'session_name' => getenv('PORTAL_SESSION_NAME') ?: 'ICBF_PORTAL',
        'base_path' => $basePath, 
        'debug' => (int)(getenv('PORTAL_DEBUG') ?: 0) === 1,
        'csrf_key' => getenv('PORTAL_CSRF_KEY') ?: 'CHANGE_ME_CSRF_KEY',

        'attachments_dir' => rtrim((string)(getenv('PORTAL_ATTACHMENTS_DIR') ?: ''), "\\/"),
    ];
}

function url(string $path): string
{
    $config = load_config();
    $base = rtrim($config['base_path'], '/');

    if (!str_starts_with($path, '/')) $path = '/' . $path;
    return $base . $path;
}
