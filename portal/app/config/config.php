<?php
declare(strict_types=1);

namespace App\Config;

function load_config(): array
{
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
    $publicUrl = rtrim((string)(getenv('PORTAL_PUBLIC_URL') ?: ''), '/');

    $assetsBase = rtrim((string)(getenv('PORTAL_MAIL_ASSETS_BASE_URL') ?: ''), '/');
    if ($assetsBase === '') {
        $assetsBase = $publicUrl;
    }

    $mailFrom = getenv('PORTAL_MAIL_FROM') ?: 'noreply@icbf.gov.co';

    return [
        'db' => [
            'host' => getenv('PORTAL_DB_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('PORTAL_DB_PORT') ?: 3307),
            'name' => getenv('PORTAL_DB_NAME') ?: 'icbf_mail',
            'user' => getenv('PORTAL_DB_USER') ?: 'root',
            'pass' => getenv('PORTAL_DB_PASSWORD') ?: '',
        ],

        'app' => [
            'public_url' => $publicUrl, 
        ],

        'mail' => [
            'from' => $mailFrom,
            'from_email' => $mailFrom,
            'from_name' => getenv('PORTAL_MAIL_FROM_NAME') ?: 'ICBF Mail',
            'assets_base_url' => $assetsBase,

            'driver' => getenv('PORTAL_MAIL_DRIVER') ?: 'smtp',
            'host' => getenv('PORTAL_MAIL_HOST') ?: '',
            'port' => (int)(getenv('PORTAL_MAIL_PORT') ?: 587),
            'user' => getenv('PORTAL_MAIL_USER') ?: '',
            'pass' => getenv('PORTAL_MAIL_PASS') ?: '',
            'tls'  => (int)(getenv('PORTAL_MAIL_TLS') ?: 1) === 1,
        ],

        'session_name' => getenv('PORTAL_SESSION_NAME') ?: 'ICBF_PORTAL',
        'base_path' => $basePath,
        'debug' => (int)(getenv('PORTAL_DEBUG') ?: 0) === 1,
        'csrf_key' => getenv('PORTAL_CSRF_KEY') ?: 'CHANGE_ME_CSRF_KEY',

        'attachments_dir' => rtrim((string)(getenv('PORTAL_ATTACHMENTS_DIR') ?: '/var/lib/icbf-mail-portal/attachments'), "\\/"),

        // Presencia y asignación de agentes (R1/R2)
        'agent_presence' => [
            'heartbeat_seconds' => max(10, (int)(getenv('PORTAL_AGENT_HEARTBEAT_SECONDS') ?: 30)),
            'stale_seconds' => max(30, (int)(getenv('PORTAL_AGENT_STALE_SECONDS') ?: 90)),
            'max_active_cases' => max(1, (int)(getenv('PORTAL_AGENT_MAX_ACTIVE_CASES') ?: 2)),
        ],
    ];
}


function url(string $path): string
{
    $config = load_config();
    $base = rtrim($config['base_path'], '/');

    if (!str_starts_with($path, '/')) $path = '/' . $path;
    return $base . $path;
}


function public_url(string $path): string
{
    $config = load_config();
    $base = rtrim((string)($config['app']['public_url'] ?? ''), '/');

    if ($base === '') {
        return url($path);
    }

    if (!str_starts_with($path, '/')) $path = '/' . $path;
    return $base . $path;
}

function mail_asset_url(string $path): string
{
    $config = load_config();
    $base = rtrim((string)($config['mail']['assets_base_url'] ?? ''), '/');

    if ($base === '') {
        return public_url($path);
    }

    if (!str_starts_with($path, '/')) $path = '/' . $path;
    return $base . $path;
}
