<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class SecurityLogRepo
{
    public function __construct(private PDO $pdo) {}

    public function log(
        string $action,
        ?int $userId,
        ?string $email,
        bool $success,
        ?array $details = null
    ): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $sql = "
            INSERT INTO security_logs
                (user_id, action, email, ip_address, user_agent, success, details, created_at)
            VALUES
                (:user_id, :action, :email, :ip, :ua, :success, :details, NOW(6))
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':email' => $email,
            ':ip' => $ip,
            ':ua' => $userAgent ? substr($userAgent, 0, 500) : null,
            ':success' => $success ? 1 : 0,
            ':details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null
        ]);
    }

    public function getRecentAttempts(string $email, int $minutes = 60): array
    {
        $sql = "
            SELECT *
            FROM security_logs
            WHERE email = :email
                AND created_at >= DATE_SUB(NOW(6), INTERVAL :minutes MINUTE)
            ORDER BY created_at DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':email' => $email,
            ':minutes' => $minutes
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}