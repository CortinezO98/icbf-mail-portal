<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Auth\Auth;
use function App\Config\url;

function require_login(): void
{
    if (!Auth::check()) {
        header('Location: ' . url('/login'));
        exit;
    }
}
