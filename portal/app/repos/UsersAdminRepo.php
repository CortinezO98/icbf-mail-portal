<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;
use PDOException;

final class UsersAdminRepo
{
    public function __construct(private PDO $pdo) {}

    /**
     * Obtiene lista paginada de usuarios con filtros
     */
    public function listUsers(
        int $page = 1,
        int $perPage = 50,
        ?string $search = null,
        ?int $isActive = null,
        ?int $roleId = null
    ): array {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $search = $search !== null ? trim($search) : null;
        if ($search === '') $search = null;

        $sql = "
            SELECT
                u.id,
                u.document,
                u.username,
                u.email,
                u.full_name,
                u.is_active,
                u.assign_enabled,
                u.last_login_at,
                u.created_at,
                u.updated_at,
                GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ', ') AS roles,
                GROUP_CONCAT(DISTINCT r.id ORDER BY r.id SEPARATOR ',') AS role_ids
            FROM users u
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            LEFT JOIN roles r ON r.id = ur.role_id
            WHERE 1=1
        ";

        $params = [];

        if ($search !== null) {
            $sql .= " AND (
                u.document LIKE :search OR
                u.username LIKE :search OR
                u.email LIKE :search OR
                u.full_name LIKE :search
            )";
            $params[':search'] = "%{$search}%";
        }

        if ($isActive !== null) {
            $sql .= " AND u.is_active = :is_active";
            $params[':is_active'] = (int)$isActive;
        }

        if ($roleId !== null && $roleId > 0) {
            $sql .= " AND ur.role_id = :role_id";
            $params[':role_id'] = (int)$roleId;
        }

        $sql .= " GROUP BY u.id ORDER BY u.id DESC LIMIT :limit OFFSET :offset";

        $st = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $st->bindValue($key, $value);
        }

        $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);

        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getAllUsersForExport(): array
    {
        $sql = "
            SELECT
                u.id,
                u.document,
                u.username,
                u.email,
                u.full_name,
                u.is_active,
                u.assign_enabled,
                u.last_login_at,
                u.created_at,
                GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ', ') AS roles
            FROM users u
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            LEFT JOIN roles r ON r.id = ur.role_id
            GROUP BY u.id
            ORDER BY u.id DESC
        ";

        $st = $this->pdo->query($sql);
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * Cuenta total de usuarios para paginación
     */
    public function countUsers(
        ?string $search = null,
        ?int $isActive = null,
        ?int $roleId = null
    ): int {
        $search = $search !== null ? trim($search) : null;
        if ($search === '') $search = null;

        $sql = "
            SELECT COUNT(DISTINCT u.id) as total
            FROM users u
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            WHERE 1=1
        ";

        $params = [];

        if ($search !== null) {
            $sql .= " AND (
                u.document LIKE :search OR
                u.username LIKE :search OR
                u.email LIKE :search OR
                u.full_name LIKE :search
            )";
            $params[':search'] = "%{$search}%";
        }

        if ($isActive !== null) {
            $sql .= " AND u.is_active = :is_active";
            $params[':is_active'] = (int)$isActive;
        }

        if ($roleId !== null && $roleId > 0) {
            $sql .= " AND ur.role_id = :role_id";
            $params[':role_id'] = (int)$roleId;
        }

        $st = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $st->bindValue($key, $value);
        }

        $st->execute();

        $result = $st->fetch(PDO::FETCH_ASSOC);
        return (int)($result['total'] ?? 0);
    }

    /**
     * ✅ FIX REAL: roles robusto (si faltara name, hace fallback)
     * En tu BD sí existe name, pero dejamos el fix para no romper en otros entornos.
     */
    public function listRoles(): array
    {
        $cols = $this->getRoleColumns();

        if (($cols['has_name'] ?? false) === true) {
            $sql = "SELECT id, code, name FROM roles ORDER BY name ASC, code ASC";
        } else {
            $sql = "SELECT id, code, code AS name FROM roles ORDER BY code ASC";
        }

        $st = $this->pdo->query($sql);
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * Detecta si existe la columna "name" en roles
     */
    private function getRoleColumns(): array
    {
        try {
            $st = $this->pdo->query("DESCRIBE roles");
            $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
            $fields = array_map(
                fn($r) => strtolower((string)($r['Field'] ?? '')),
                $rows
            );

            return [
                'has_name' => in_array('name', $fields, true),
            ];
        } catch (\Throwable $e) {
            return ['has_name' => false];
        }
    }

    public function findById(int $id): ?array
    {
        $sql = "
            SELECT
                u.*,
                GROUP_CONCAT(DISTINCT ur.role_id ORDER BY ur.role_id SEPARATOR ',') as role_ids
            FROM users u
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            WHERE u.id = :id
            GROUP BY u.id
            LIMIT 1
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $row['role_ids'] = !empty($row['role_ids'])
                ? array_map('intval', explode(',', (string)$row['role_ids']))
                : [];
        }

        return $row ?: null;
    }

    public function findByDocument(string $document): ?array
    {
        $sql = "SELECT * FROM users WHERE document = :d LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':d' => $document]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $sql = "SELECT * FROM users WHERE username = :u LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':u' => $username]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $sql = "SELECT * FROM users WHERE email = :e LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':e' => $email]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createUser(
        string $document,
        string $username,
        string $email,
        string $fullName,
        string $passwordHash,
        int $isActive = 1,
        int $assignEnabled = 1
    ): int {
        $sql = "
            INSERT INTO users (
                document, username, email, full_name,
                password_hash, is_active, assign_enabled,
                created_at, updated_at
            ) VALUES (
                :document, :username, :email, :full_name,
                :password_hash, :is_active, :assign_enabled,
                NOW(6), NOW(6)
            )
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':document' => $document !== '' ? $document : null,
            ':username' => $username,
            ':email' => $email,
            ':full_name' => $fullName,
            ':password_hash' => $passwordHash,
            ':is_active' => $isActive,
            ':assign_enabled' => $assignEnabled,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Ahora recibe un array asociativo $data
     */
    public function updateUser(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];

        $allowedFields = ['document', 'username', 'email', 'full_name', 'password_hash', 'is_active', 'assign_enabled'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        $fields[] = "updated_at = NOW(6)";

        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
        $st = $this->pdo->prepare($sql);
        return $st->execute($params);
    }

    public function toggleActive(int $id, int $isActive): bool
    {
        $sql = "UPDATE users SET is_active = :is_active, updated_at = NOW(6) WHERE id = :id";
        $st = $this->pdo->prepare($sql);
        return $st->execute([':id' => $id, ':is_active' => $isActive]);
    }

    public function deleteUser(int $id): bool
    {
        try {
            $this->pdo->beginTransaction();

            $st = $this->pdo->prepare("DELETE FROM user_roles WHERE user_id = :id");
            $st->execute([':id' => $id]);

            $st = $this->pdo->prepare("DELETE FROM users WHERE id = :id");
            $result = $st->execute([':id' => $id]);

            $this->pdo->commit();
            return $result;

        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function setUserRoles(int $userId, array $roleIds): void
    {
        try {
            $this->pdo->beginTransaction();

            $del = $this->pdo->prepare("DELETE FROM user_roles WHERE user_id = :uid");
            $del->execute([':uid' => $userId]);

            if (!empty($roleIds)) {
                $ins = $this->pdo->prepare(
                    "INSERT INTO user_roles (user_id, role_id) VALUES (:uid, :rid)"
                );

                foreach ($roleIds as $rid) {
                    $rid = (int)$rid;
                    if ($rid <= 0) continue;

                    $ins->execute([
                        ':uid' => $userId,
                        ':rid' => $rid,
                    ]);
                }
            }

            $this->pdo->commit();

        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function importUsersFromArray(array $users): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
            'created_ids' => [],
        ];

        try {
            $this->pdo->beginTransaction();

            foreach ($users as $index => $userData) {
                try {
                    if (empty($userData['username']) || empty($userData['email']) || empty($userData['full_name'])) {
                        throw new \Exception("Faltan campos obligatorios en fila " . ($index + 1));
                    }

                    if ($this->findByUsername((string)$userData['username'])) {
                        throw new \Exception("Usuario '{$userData['username']}' ya existe");
                    }

                    if ($this->findByEmail((string)$userData['email'])) {
                        throw new \Exception("Email '{$userData['email']}' ya está registrado");
                    }

                    $tempPassword = $userData['password'] ?? (bin2hex(random_bytes(6)) . 'A!');
                    $hash = password_hash((string)$tempPassword, PASSWORD_DEFAULT);

                    $userId = $this->createUser(
                        (string)($userData['document'] ?? ''),
                        (string)$userData['username'],
                        (string)$userData['email'],
                        (string)$userData['full_name'],
                        $hash,
                        (int)($userData['is_active'] ?? 1),
                        (int)($userData['assign_enabled'] ?? 1)
                    );

                    if (!empty($userData['roles'])) {
                        $this->setUserRoles($userId, (array)$userData['roles']);
                    }

                    $results['success']++;
                    $results['created_ids'][] = $userId;

                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = "Fila " . ($index + 1) . ": " . $e->getMessage();
                }
            }

            $this->pdo->commit();
            return $results;

        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getStatistics(): array
    {
        $sql = "
            SELECT
                COUNT(*) as total_users,
                SUM(is_active = 1) as active_users,
                SUM(is_active = 0) as inactive_users,
                SUM(assign_enabled = 1) as assignable_users,
                SUM(last_login_at IS NOT NULL) as users_with_login,
                MIN(created_at) as first_created,
                MAX(created_at) as last_created
            FROM users
        ";

        $st = $this->pdo->query($sql);
        $stats = $st ? $st->fetch(PDO::FETCH_ASSOC) : [];

        $sqlRoles = "
            SELECT
                r.code,
                " . (($this->getRoleColumns()['has_name'] ?? false) ? "r.name" : "r.code AS name") . " ,
                COUNT(DISTINCT ur.user_id) as user_count
            FROM roles r
            LEFT JOIN user_roles ur ON ur.role_id = r.id
            GROUP BY r.id
            ORDER BY r.code
        ";

        $stRoles = $this->pdo->query($sqlRoles);
        $rolesStats = $stRoles ? ($stRoles->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        return [
            'general' => $stats ?: [],
            'by_role' => $rolesStats,
        ];
    }
}
