<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';

require_once __DIR__ . '/../app/services/BusinessTime.php';
require_once __DIR__ . '/../app/repos/MetricsRepo.php';

use App\Services\BusinessTime;

$pdo = \App\Config\pdo();

function dtBogota(string $dt): DateTimeImmutable {
    return new DateTimeImmutable($dt, new DateTimeZone('America/Bogota'));
}

function semaforoFromBusinessMinutes(int $mins): string {
    if ($mins < 300) return 'VERDE';
    if ($mins <= 720) return 'AMARILLO';
    return 'ROJO';
}

// 1) Cargar calendario
$row = $pdo->query("SELECT * FROM sla_calendar ORDER BY id ASC LIMIT 1")->fetch() ?: [
    'tz' => 'America/Bogota',
    'start_time' => '08:00:00',
    'end_time' => '17:00:00',
    'workdays_mask' => 62,
];
$clock = BusinessTime::fromRow($row);
$now = new DateTimeImmutable('now', new DateTimeZone('America/Bogota'));

$batchSize = 800;
$totalUpdated = 0;

echo date('Y-m-d H:i:s') . " Backfill SLA business START\n";

$select = $pdo->prepare("
    SELECT
      c.id AS case_id,
      c.received_at,
      cs.pauses_sla,
      cst.sla_ignored,
      cst.sla_started_at
    FROM cases c
    JOIN case_statuses cs ON cs.id = c.status_id
    JOIN case_sla_tracking cst ON cst.case_id = c.id
    WHERE cs.is_final = 0
      AND (cst.sla_started_at IS NULL OR cst.sla_started_at = '')
    ORDER BY c.id ASC
    LIMIT :lim
");

$update = $pdo->prepare("
    UPDATE case_sla_tracking
    SET
      sla_started_at = :sla_started_at,
      business_minutes = :business_minutes,
      current_sla_state = :state,
      sla_due_at = :sla_due_at,
      breached = :breached,
      last_updated = NOW(6)
    WHERE case_id = :case_id
");

while (true) {
    $select->bindValue(':lim', $batchSize, PDO::PARAM_INT);
    $select->execute();
    $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) break;

    foreach ($rows as $r) {
        $caseId = (int)$r['case_id'];
        $receivedAtStr = (string)($r['received_at'] ?? '');
        if ($receivedAtStr === '') continue;

        $ignored = ((int)($r['sla_ignored'] ?? 0) === 1);
        $pauses  = ((int)($r['pauses_sla'] ?? 0) === 1);

        // Si está pausado/ignorado: lo dejamos VERDE y no lo forzamos a due/biz (pero sí seteamos started_at “normalizado”)
        $receivedAt = dtBogota($receivedAtStr);
        $slaStart = $clock->normalizeStart($receivedAt);

        if ($ignored || $pauses) {
            $update->execute([
                ':sla_started_at' => $slaStart->format('Y-m-d H:i:s'),
                ':business_minutes' => null,
                ':state' => 'VERDE',
                ':sla_due_at' => null,
                ':breached' => 0,
                ':case_id' => $caseId,
            ]);
            $totalUpdated += $update->rowCount();
            continue;
        }

        $bizMins = $clock->diffBusinessMinutes($slaStart, $now);
        $state = semaforoFromBusinessMinutes($bizMins);
        $breached = ($bizMins > 720) ? 1 : 0;
        $dueAt = $clock->addBusinessMinutes($slaStart, 720)->format('Y-m-d H:i:s');

        $update->execute([
            ':sla_started_at' => $slaStart->format('Y-m-d H:i:s'),
            ':business_minutes' => $bizMins,
            ':state' => $state,
            ':sla_due_at' => $dueAt,
            ':breached' => $breached,
            ':case_id' => $caseId,
        ]);

        $totalUpdated += $update->rowCount();
    }

    echo date('Y-m-d H:i:s') . " batch done, updated so far={$totalUpdated}\n";
}

echo date('Y-m-d H:i:s') . " Backfill SLA business END. total_updated={$totalUpdated}\n";
