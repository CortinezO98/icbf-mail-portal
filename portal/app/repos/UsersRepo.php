<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class UsersRepo
{
    public function __construct(private PDO $pdo) {}

    public function findByUsernameOrEmail(string $login): ?array
    {
        $sql = "SELECT * FROM users
                WHERE (username = :login OR email = :login)
                  AND is_active = 1
                LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':login' => $login]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function rolesForUser(int $userId): array
    {
        $sql = "SELECT r.code
                FROM user_roles ur
                JOIN roles r ON r.id = ur.role_id
                WHERE ur.user_id = :uid";
        $st = $this->pdo->prepare($sql);
        $st->execute([':uid' => $userId]);
        return array_map(fn($r) => $r['code'], $st->fetchAll());
    }

    public function listAgents(): array
    {
        // OJO: En tu BD el rol es AGENTE (no AGENT)
        $sql = "SELECT u.id, u.full_name, u.username, u.email
                FROM users u
                JOIN user_roles ur ON ur.user_id = u.id
                JOIN roles r ON r.id = ur.role_id
                WHERE u.is_active=1 AND r.code='AGENTE'
                ORDER BY u.full_name ASC";
        return $this->pdo->query($sql)->fetchAll();
    }


    public function pickLeastLoadedAgentId(): ?int
    {
        $sql = "
            SELECT u.id
            FROM users u
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN roles r ON r.id = ur.role_id AND r.code = 'AGENTE'
            LEFT JOIN cases c ON c.assigned_user_id = u.id
            LEFT JOIN case_statuses cs
            ON cs.id = c.status_id
            AND cs.code IN ('ASIGNADO','EN_PROCESO')
            WHERE u.is_active = 1
            AND u.assign_enabled = 1
            GROUP BY u.id
            ORDER BY COUNT(c.id) ASC,
                    COALESCE(u.last_assigned_at, '1970-01-01') ASC,
                    u.id ASC
            LIMIT 1
        ";
        $st = $this->pdo->query($sql);
        $id = $st ? $st->fetchColumn() : false;
        return $id ? (int)$id : null;
    }

    public function touchLastAssignedAt(int $userId): void
    {
        $st = $this->pdo->prepare("
            UPDATE users
            SET last_assigned_at = NOW(6),
                updated_at = NOW(6)
            WHERE id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $userId]);
    }


}
