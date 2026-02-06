<?php
declare(strict_types=1);

if (!function_exists('esc')) {
    function esc($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('is_active_prefix')) {
    function is_active_prefix(string $currentPath, string $prefix): bool {
        if ($prefix === '/') return $currentPath === '/';
        return $currentPath === $prefix || str_starts_with($currentPath, $prefix . '/');
    }
}

if (!function_exists('formatDate')) {
    function formatDate($date): string {
        if (empty($date)) return '—';
        $timestamp = strtotime((string)$date);
        if (!$timestamp) return '—';

        $today = strtotime('today');
        $yesterday = strtotime('yesterday');

        if ($timestamp >= $today) return 'Hoy, ' . date('H:i', $timestamp);
        if ($timestamp >= $yesterday) return 'Ayer, ' . date('H:i', $timestamp);
        return date('d/m/Y', $timestamp);
    }
}
