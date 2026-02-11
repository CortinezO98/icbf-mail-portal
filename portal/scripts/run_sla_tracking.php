<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';

require_once __DIR__ . '/../app/services/BusinessTime.php';
require_once __DIR__ . '/../app/repos/HolidayRepo.php';
require_once __DIR__ . '/../app/repos/MetricsRepo.php';

use App\Repos\MetricsRepo;

function logl(string $m): void {
    echo date('Y-m-d H:i:s') . " " . $m . PHP_EOL;
}

try {
    $config = \App\Config\load_config();
    $pdo = \App\Config\pdo($config);
    $repo = new MetricsRepo($pdo);

    logl("SLA TRACKING START");

    $ins = $repo->initializeSlaTracking();
    logl("initializeSlaTracking inserted={$ins}");

    $upd = $repo->updateSlaTracking();
    logl("updateSlaTracking updated_rows={$upd}");

    logl("SLA TRACKING END OK");
    exit(0);

} catch (Throwable $e) {
    logl("SLA TRACKING ERROR: " . $e->getMessage());
    exit(1);
}
