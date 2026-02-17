<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class ReportJobService
{
    public function __construct(private PDO $pdo) {}

    public function requestSlaExport(string $startDate, string $endDate, ?int $mailboxId, int $userId): int
    {
        $params = [
            "start" => $startDate,
            "end" => $endDate,
        ];
        if ($mailboxId !== null) $params["mailbox_id"] = $mailboxId;

        $sql = "
          INSERT INTO generated_reports
            (report_type, params_json, status, created_by, created_at, updated_at)
          VALUES
            ('SLA_DATASET', :params, 'PENDING', :uid, NOW(6), NOW(6))
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':params' => json_encode($params, JSON_UNESCAPED_UNICODE),
            ':uid' => $userId,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function listMyReports(int $userId): array
    {
        $st = $this->pdo->prepare("
          SELECT id, report_type, status, row_count, storage_path, error_message, created_at, finished_at
          FROM generated_reports
          WHERE created_by = :uid
          ORDER BY created_at DESC
          LIMIT 50
        ");
        $st->execute([':uid' => $userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getReport(int $reportId): ?array
    {
        $st = $this->pdo->prepare("
          SELECT *
          FROM generated_reports
          WHERE id = :id
          LIMIT 1
        ");
        $st->execute([':id' => $reportId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
