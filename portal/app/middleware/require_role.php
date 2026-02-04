<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Auth\Auth;

function require_role(array $allowedRoleCodes): void
{
    $roles = Auth::roles();
    foreach ($allowedRoleCodes as $role) {
        if (in_array($role, $roles, true)) return;
    }
    http_response_code(403);
    echo "Forbidden";
    exit;
}
