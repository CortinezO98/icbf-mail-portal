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
        $offset = ($page - 1) * $perPage;
        
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
                GROUP_CONCAT(DISTINCT r.id) AS role_ids
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
            $params[':search'] = "%$search%";
        }
        
        if ($isActive !== null) {
            $sql .= " AND u.is_active = :is_active";
            $params[':is_active'] = $isActive;
        }
        
        if ($roleId !== null) {
            $sql .= " AND ur.role_id = :role_id";
            $params[':role_id'] = $roleId;
        }
        
        $sql .= " GROUP BY u.id ORDER BY u.id DESC LIMIT :limit OFFSET :offset";
        
        $st = $this->pdo->prepare($sql);
        
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value);
        }
        
        $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        $st->execute();
        return $st->fetchAll() ?: [];
    }

    /**
     * Cuenta total de usuarios para paginación
     */
    public function countUsers(
        ?string $search = null,
        ?int $isActive = null,
        ?int $roleId = null
    ): int {
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
            $params[':search'] = "%$search%";
        }
        
        if ($isActive !== null) {
            $sql .= " AND u.is_active = :is_active";
            $params[':is_active'] = $isActive;
        }
        
        if ($roleId !== null) {
            $sql .= " AND ur.role_id = :role_id";
            $params[':role_id'] = $roleId;
        }
        
        $st = $this->pdo->prepare($sql);
        
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value);
        }
        
        $st->execute();
        $result = $st->fetch();
        return (int)($result['total'] ?? 0);
    }

    public function listRoles(): array
    {
        $sql = "SELECT id, code, name FROM roles ORDER BY code ASC";
        return $this->pdo->query($sql)->fetchAll() ?: [];
    }

    public function findById(int $id): ?array
    {
        $sql = "
            SELECT 
                u.*,
                GROUP_CONCAT(DISTINCT ur.role_id) as role_ids
            FROM users u
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            WHERE u.id = :id
            GROUP BY u.id
            LIMIT 1
        ";
        
        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        
        if ($row) {
            $row['role_ids'] = $row['role_ids'] 
                ? array_map('intval', explode(',', $row['role_ids']))
                : [];
        }
        
        return $row ?: null;
    }

    public function findByDocument(string $document): ?array
    {
        $sql = "SELECT * FROM users WHERE document = :d LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':d' => $document]);
        return $st->fetch() ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $sql = "SELECT * FROM users WHERE username = :u LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':u' => $username]);
        return $st->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $sql = "SELECT * FROM users WHERE email = :e LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':e' => $email]);
        return $st->fetch() ?: null;
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

    public function updateUser(
        int $id,
        ?string $document,
        string $username,
        string $email,
        string $fullName,
        ?string $passwordHash = null,
        ?int $isActive = null,
        ?int $assignEnabled = null
    ): bool {
        $fields = [
            'username = :username',
            'email = :email',
            'full_name = :full_name',
            'updated_at = NOW(6)'
        ];
        
        $params = [
            ':id' => $id,
            ':username' => $username,
            ':email' => $email,
            ':full_name' => $fullName,
        ];
        
        if ($document !== null) {
            $fields[] = 'document = :document';
            $params[':document'] = $document !== '' ? $document : null;
        }
        
        if ($passwordHash !== null) {
            $fields[] = 'password_hash = :password_hash';
            $params[':password_hash'] = $passwordHash;
        }
        
        if ($isActive !== null) {
            $fields[] = 'is_active = :is_active';
            $params[':is_active'] = $isActive;
        }
        
        if ($assignEnabled !== null) {
            $fields[] = 'assign_enabled = :assign_enabled';
            $params[':assign_enabled'] = $assignEnabled;
        }
        
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
            
            // Eliminar relaciones primero
            $st = $this->pdo->prepare("DELETE FROM user_roles WHERE user_id = :id");
            $st->execute([':id' => $id]);
            
            // Eliminar usuario
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
            
            // Eliminar roles actuales
            $del = $this->pdo->prepare("DELETE FROM user_roles WHERE user_id = :uid");
            $del->execute([':uid' => $userId]);
            
            // Insertar nuevos roles
            if (!empty($roleIds)) {
                $ins = $this->pdo->prepare(
                    "INSERT INTO user_roles (user_id, role_id) VALUES (:uid, :rid)"
                );
                
                foreach ($roleIds as $rid) {
                    $rid = (int)$rid;
                    if ($rid <= 0) continue;
                    
                    $ins->execute([
                        ':uid' => $userId,
                        ':rid' => $rid
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
            'created_ids' => []
        ];
        
        try {
            $this->pdo->beginTransaction();
            
            foreach ($users as $index => $userData) {
                try {
                    // Validar datos básicos
                    if (empty($userData['username']) || empty($userData['email']) || empty($userData['full_name'])) {
                        throw new \Exception("Faltan campos obligatorios en fila " . ($index + 1));
                    }
                    
                    // Verificar duplicados
                    if ($this->findByUsername($userData['username'])) {
                        throw new \Exception("Usuario '{$userData['username']}' ya existe");
                    }
                    
                    if ($this->findByEmail($userData['email'])) {
                        throw new \Exception("Email '{$userData['email']}' ya está registrado");
                    }
                    
                    // Generar password temporal
                    $tempPassword = $userData['password'] ?? bin2hex(random_bytes(6)) . 'A!';
                    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
                    
                    // Crear usuario
                    $userId = $this->createUser(
                        $userData['document'] ?? '',
                        $userData['username'],
                        $userData['email'],
                        $userData['full_name'],
                        $hash,
                        (int)($userData['is_active'] ?? 1),
                        (int)($userData['assign_enabled'] ?? 1)
                    );
                    
                    // Asignar roles
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
        $stats = $st->fetch();
        
        // Estadísticas por rol
        $sqlRoles = "
            SELECT 
                r.code,
                r.name,
                COUNT(DISTINCT ur.user_id) as user_count
            FROM roles r
            LEFT JOIN user_roles ur ON ur.role_id = r.id
            GROUP BY r.id
            ORDER BY r.code
        ";
        
        $stRoles = $this->pdo->query($sqlRoles);
        $rolesStats = $stRoles->fetchAll() ?: [];
        
        return [
            'general' => $stats ?: [],
            'by_role' => $rolesStats
        ];
    }
}