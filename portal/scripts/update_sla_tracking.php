<?php
// File: portal/scripts/update_sla_tracking.php
<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/repos/MetricsRepo.php';

$pdo = \App\Config\pdo();
$metricsRepo = new \App\Repos\MetricsRepo($pdo);

try {
    // 1. Inicializar casos nuevos
    $initialized = $metricsRepo->initializeSlaTracking();
    
    // 2. Actualizar tracking existente
    $updated = $metricsRepo->updateSlaTracking();
    
    echo date('Y-m-d H:i:s') . " - SLA Tracking: $initialized inicializados, $updated actualizados\n";
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    exit(1);
}


?>