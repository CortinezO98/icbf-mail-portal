<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class CaseEventsRepo
{
    public function __construct(private PDO $pdo) {}

    public function addStatusChange(
        int $caseId,
        ?int $actorUserId,
        string $source, 
        ?string $ip,
        ?string $ua,
        string $eventType, 
        ?int $fromStatusId,
        ?int $toStatusId,
        array $details = []
    ): void {
        $json = null;
        if (!empty($details)) {
            $json = json_encode($details, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $json = null;
            }
        }

        $st = $this->pdo->prepare("
            INSERT INTO case_events
              (case_id, actor_user_id, source, ip_address, user_agent,
               event_type, from_status_id, to_status_id, details_json, created_at)
            VALUES
              (:cid, :uid, :src, :ip, :ua, :etype, :froms, :tos, :json, NOW(6))
        ");

        $st->execute([
            ':cid'   => $caseId,
            ':uid'   => $actorUserId,
            ':src'   => $source,
            ':ip'    => $ip,
            ':ua'    => $ua,
            ':etype' => $eventType,
            ':froms' => $fromStatusId,
            ':tos'   => $toStatusId,
            ':json'  => $json,
        ]);
    }
}
