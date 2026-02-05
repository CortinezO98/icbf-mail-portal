<?php
// File: portal/app/config/dashboard.php
declare(strict_types=1);

namespace App\Config;

function dashboard_config(): array
{
    return [
        // Configuración del semáforo (días)
        'semaforo' => [
            'verde' => [
                'min_days' => 3,
                'color' => '#10b981',
                'icon' => 'bi-check-circle',
                'label' => 'En Tiempo'
            ],
            'amarillo' => [
                'min_days' => 1,
                'max_days' => 2,
                'color' => '#f59e0b',
                'icon' => 'bi-exclamation-triangle',
                'label' => 'Por Vencer'
            ],
            'rojo' => [
                'max_days' => 0,
                'color' => '#ef4444',
                'icon' => 'bi-exclamation-octagon',
                'label' => 'Vencido'
            ]
        ],
        
        // Configuración de actualización
        'auto_refresh' => [
            'enabled' => true,
            'interval_seconds' => 30,
            'show_counter' => true
        ],
        
        // Configuración de reportes
        'reports' => [
            'max_rows' => 1000,
            'formats' => ['csv', 'json', 'html'],
            'retention_days' => 30
        ],
        
        // Permisos
        'permissions' => [
            'view_dashboard' => ['ADMIN', 'SUPERVISOR', 'AGENTE'],
            'export_reports' => ['ADMIN', 'SUPERVISOR'],
            'view_executive' => ['ADMIN']
        ]
    ];
}