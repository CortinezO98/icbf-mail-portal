<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';

require_once __DIR__ . '/../app/services/BusinessTime.php';
require_once __DIR__ . '/../app/repos/HolidayRepo.php';
require_once __DIR__ . '/../app/repos/MetricsRepo.php';

$pdo = \App\Config\pdo(\App\Config\load_config());
$repo = new \App\Repos\MetricsRepo($pdo);

$ref = new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('America/Bogota')); // festivo
$next = new DateTimeImmutable('2026-01-02 08:00:00', new DateTimeZone('America/Bogota')); // hábil

$clock = (new ReflectionClass($repo))->getMethod('loadBusinessClock');
$clock->setAccessible(true);
$bt = $clock->invoke($repo);

$start = $bt->normalizeStart($ref);
echo "normalizeStart(2026-01-01 10:00) => " . $start->format('Y-m-d H:i:s') . PHP_EOL;

$mins = $bt->diffBusinessMinutes($ref, $next);
echo "diffBusinessMinutes(2026-01-01 10:00 -> 2026-01-02 08:00) => $mins" . PHP_EOL;
