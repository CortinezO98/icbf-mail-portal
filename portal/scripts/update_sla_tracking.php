<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';

require_once __DIR__ . '/../app/services/BusinessTime.php';
require_once __DIR__ . '/../app/repos/HolidayRepo.php';
require_once __DIR__ . '/../app/repos/MetricsRepo.php';

function logl(string $m): void {
    echo date('Y-m-d H:i:s') . " " . $m . PHP_EOL;
}

$config = \App\Config\load_config();
$pdo    = \App\Config\pdo($config);

// Debug: confirma que es la misma BD/puerto que phpMyAdmin
$who = $pdo->query("SELECT DATABASE() db, @@hostname host, @@port port")->fetch(PDO::FETCH_ASSOC);
logl("DB connected db={$who['db']} host={$who['host']} port={$who['port']}");

$metricsRepo = new \App\Repos\MetricsRepo($pdo);

try {
    logl("SLA TRACKING START");
    $initialized = $metricsRepo->initializeSlaTracking();
    logl("initializeSlaTracking inserted={$initialized}");

    $updated = $metricsRepo->updateSlaTracking();
    logl("updateSlaTracking updated_rows={$updated}");

    logl("SLA TRACKING END OK");
} catch (Throwable $e) {
    logl("ERROR: " . $e->getMessage());
    exit(1);
}
