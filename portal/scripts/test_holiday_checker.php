<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/services/BusinessTime.php';
require_once __DIR__ . '/../app/repos/HolidayRepo.php';
require_once __DIR__ . '/../app/repos/MetricsRepo.php';

use App\Config;
use App\Repos\HolidayRepo;


$config = Config\load_config();
$pdo = Config\pdo($config);

$h = new HolidayRepo($pdo);

// 2026-01-01 (Año Nuevo) lo tienes en tabla
$dt = new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('America/Bogota'));

echo "isHoliday? " . ($h->isHoliday('CO', $dt) ? "YES\n" : "NO\n");
