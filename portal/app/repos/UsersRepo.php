<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class UsersRepo
{
    public function __construct(private PDO $pdo) {}

    public function findByUsernameOrEmail(string $login): ?array
    {
        $login = trim($login);

        $sql = "
            SELECT *
            FROM users
            WHERE (username = :login_u OR email = :login_e)
              AND is_active = 1
            LIMIT 1
        ";

        $st = $this->pdo->prepare($sql);

        $st->execute([
            ':login_u' => $login,
            ':login_e' => $login,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findActiveByEmail(string $email): ?array
    {
        $sql = "
            SELECT *
            FROM users
            WHERE email = :email
              AND is_active = 1
            LIMIT 1
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':email' => trim($email),
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function storePasswordResetToken(
        int $userId,
        string $email,
        string $tokenHash,
        string $expiresAt
    ): void {
        $sql = "
            INSERT INTO password_resets (
                user_id,
                email,
                token_hash,
                expires_at
            )
            VALUES (
                :user_id,
                :email,
                :token_hash,
                :expires_at
            )
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':user_id'    => $userId,
            ':email'      => trim($email),
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt,
        ]);
    }

    public function findValidPasswordResetByTokenHash(string $tokenHash): ?array
    {
        $sql = "
            SELECT
                pr.*,
                u.id AS user_id_ref,
                u.email AS user_email
            FROM password_resets pr
            INNER JOIN users u
                ON u.id = pr.user_id
            WHERE pr.token_hash = :token_hash
              AND pr.used_at IS NULL
              AND pr.expires_at >= NOW(6)
              AND u.is_active = 1
            LIMIT 1
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':token_hash' => $tokenHash,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $sql = "
            UPDATE users
            SET password_hash = :password_hash,
                updated_at = NOW(6)
            WHERE id = :id
            LIMIT 1
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':password_hash' => $passwordHash,
            ':id'            => $userId,
        ]);
    }

    public function markPasswordResetUsed(int $resetId): void
    {
        $sql = "
            UPDATE password_resets
            SET used_at = NOW(6)
            WHERE id = :id
            LIMIT 1
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':id' => $resetId,
        ]);
    }

    public function rolesForUser(int $userId): array
    {
        $sql = "
            SELECT DISTINCT UPPER(TRIM(r.code)) AS code
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = :uid
            ORDER BY code
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([':uid' => $userId]);

        $codes = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

        // normalización extra
        $codes = array_values(array_unique(array_filter(array_map(
            static fn($v) => strtoupper(trim((string)$v)),
            $codes
        ))));

        return $codes;
    }

    public function listAgents(): array
    {
        $sql = "
            SELECT u.id, u.full_name, u.username, u.email
            FROM users u
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN roles r ON r.id = ur.role_id
            WHERE u.is_active = 1
              AND UPPER(TRIM(r.code)) = 'AGENTE'
            ORDER BY u.full_name ASC
        ";
        $st = $this->pdo->query($sql);
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function pickLeastLoadedAgentId(): ?int
    {
        $sql = "
            SELECT u.id
            FROM users u
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN roles r ON r.id = ur.role_id AND UPPER(TRIM(r.code)) = 'AGENTE'
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


    public function listAssignableAgents(): array
    {
        $sql = "
            SELECT DISTINCT
                u.id,
                u.full_name,
                u.username,
                u.email
            FROM users u
            INNER JOIN user_roles ur ON ur.user_id = u.id
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE u.is_active = 1
            AND u.assign_enabled = 1
            AND UPPER(TRIM(r.code)) IN ('AGENTE', 'AGENT')
            ORDER BY u.full_name ASC, u.username ASC
        ";

        $st = $this->pdo->query($sql);
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }





}