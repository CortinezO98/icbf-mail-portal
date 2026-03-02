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

        // LOG DE ENTRADA
        error_log("========== UsersAdminRepo::listUsers ==========");
        error_log("Input parameters - page: $page, perPage: $perPage, offset: $offset");
        error_log("search: '" . ($search ?? 'null') . "', isActive: " . ($isActive ?? 'null') . ", roleId: " . ($roleId ?? 'null'));

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
                ra.roles,
                ra.role_ids
            FROM users u
            LEFT JOIN (
                SELECT
                    ur.user_id,
                    GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ', ') AS roles,
                    GROUP_CONCAT(DISTINCT r.id ORDER BY r.id SEPARATOR ',') AS role_ids
                FROM user_roles ur
                INNER JOIN roles r ON r.id = ur.role_id
                GROUP BY ur.user_id
            ) ra ON ra.user_id = u.id
            WHERE 1=1
        ";

        $params = [];

        if ($search !== null) {
            $sql .= " AND (
                COALESCE(u.document, '') LIKE :search OR
                u.username LIKE :search OR
                u.email LIKE :search OR
                u.full_name LIKE :search
            )";
            $params[':search'] = "%{$search}%";
            error_log("Added search filter with term: '%{$search}%'");
        }

        if ($isActive !== null) {
            $sql .= " AND u.is_active = :is_active";
            $params[':is_active'] = (int)$isActive;
            error_log("Added is_active filter: $isActive");
        }

        if ($roleId !== null && $roleId > 0) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM user_roles ur2
                WHERE ur2.user_id = u.id AND ur2.role_id = :role_id
            )";
            $params[':role_id'] = (int)$roleId;
            error_log("Added role_id filter: $roleId (using EXISTS)");
        }

        $sql .= " ORDER BY u.id DESC LIMIT :limit OFFSET :offset";
        
        // LOG DE LA CONSULTA SQL
        $sqlForLog = preg_replace('/\s+/', ' ', $sql);
        error_log("Final SQL query: " . $sqlForLog);
        error_log("Parameters to bind: " . json_encode($params));
        error_log("Limit: $perPage, Offset: $offset");

        try {
            $st = $this->pdo->prepare($sql);

            // Bind de parámetros de búsqueda y filtros
            foreach ($params as $k => $v) {
                if ($k === ':is_active' || $k === ':role_id') {
                    $st->bindValue($k, (int)$v, PDO::PARAM_INT);
                    error_log("Binding $k = " . (int)$v . " (INT)");
                } else {
                    $st->bindValue($k, (string)$v, PDO::PARAM_STR);
                    error_log("Binding $k = '" . (string)$v . "' (STRING)");
                }
            }
            
            // Bind de limit y offset
            $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $st->bindValue(':offset', $offset, PDO::PARAM_INT);
            error_log("Binding :limit = $perPage (INT)");
            error_log("Binding :offset = $offset (INT)");

            // Ejecutar consulta
            error_log("Executing query...");
            $st->execute();
            
            // Obtener resultados
            $results = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            error_log("Query executed successfully. Returned " . count($results) . " rows");
            
            // Log del primer resultado para ver estructura (opcional)
            if (!empty($results)) {
                error_log("First result sample: " . json_encode($results[0]));
            }
            
            return $results;
            
        } catch (\PDOException $e) {
            error_log("!!!!!!!!!! PDO ERROR in listUsers !!!!!!!!!!");
            error_log("Error message: " . $e->getMessage());
            error_log("Error code: " . $e->getCode());
            if (isset($e->errorInfo) && is_array($e->errorInfo)) {
                error_log("SQL State: " . ($e->errorInfo[0] ?? 'N/A'));
                error_log("Driver Code: " . ($e->errorInfo[1] ?? 'N/A'));
                error_log("Driver Message: " . ($e->errorInfo[2] ?? 'N/A'));
            }
            error_log("Stack trace: " . $e->getTraceAsString());
            
            // Re-lanzar la excepción para que la capture el controlador
            throw $e;
        } catch (\Exception $e) {
            error_log("!!!!!!!!!! GENERAL ERROR in listUsers !!!!!!!!!!");
            error_log("Error message: " . $e->getMessage());
            error_log("Error type: " . get_class($e));
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
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

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

        error_log("UsersAdminRepo::countUsers - search: " . ($search ?? 'null') . ", isActive: " . ($isActive ?? 'null') . ", roleId: " . ($roleId ?? 'null'));

        $params = [];
        
        // CONSTRUIR LA CONSULTA BASE
        $sql = "SELECT COUNT(DISTINCT u.id) as total FROM users u";
        
        // Si hay filtro por rol, NECESITAMOS JOIN con user_roles
        if ($roleId !== null && $roleId > 0) {
            $sql .= " INNER JOIN user_roles ur ON ur.user_id = u.id AND ur.role_id = :role_id";
            $params[':role_id'] = (int)$roleId;
            error_log("Added role_id filter with INNER JOIN: $roleId");
        }
        
        $sql .= " WHERE 1=1";

        // Agregar filtro de búsqueda si existe
        if ($search !== null) {
            $sql .= " AND (
                COALESCE(u.document, '') LIKE :search OR
                u.username LIKE :search OR
                u.email LIKE :search OR
                u.full_name LIKE :search
            )";
            $params[':search'] = "%{$search}%";
            error_log("Added search filter: '%$search%'");
        }

        // Agregar filtro de estado si existe
        if ($isActive !== null) {
            $sql .= " AND u.is_active = :is_active";
            $params[':is_active'] = (int)$isActive;
            error_log("Added is_active filter: $isActive");
        }

        error_log("Final SQL: " . preg_replace('/\s+/', ' ', $sql));
        error_log("Params: " . json_encode($params));

        try {
            $st = $this->pdo->prepare($sql);

            foreach ($params as $key => $value) {
                $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
                error_log("Binding $key = " . (is_int($value) ? $value : "'$value'"));
            }

            $st->execute();
            $result = $st->fetch(PDO::FETCH_ASSOC);
            $total = (int)($result['total'] ?? 0);
            
            error_log("Query executed successfully, total = $total");
            return $total;
            
        } catch (\PDOException $e) {
            error_log("!!!!!!!!!! PDO ERROR in countUsers !!!!!!!!!!");
            error_log("Error message: " . $e->getMessage());
            if (isset($e->errorInfo) && is_array($e->errorInfo)) {
                error_log("SQL State: " . ($e->errorInfo[0] ?? 'N/A'));
                error_log("Driver Code: " . ($e->errorInfo[1] ?? 'N/A'));
                error_log("Driver Message: " . ($e->errorInfo[2] ?? 'N/A'));
            }
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }


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
        $startedHere = false;

        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedHere = true;
            }

            $st = $this->pdo->prepare("DELETE FROM user_roles WHERE user_id = :id");
            $st->execute([':id' => $id]);

            $st = $this->pdo->prepare("DELETE FROM users WHERE id = :id");
            $result = $st->execute([':id' => $id]);

            if ($startedHere) {
                $this->pdo->commit();
            }

            return $result;

        } catch (PDOException $e) {
            if ($startedHere && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }


    public function setUserRoles(int $userId, array $roleIds): void
    {
        $startedHere = false;
        $roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds), fn($v) => $v > 0)));

        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedHere = true;
            }

            $del = $this->pdo->prepare("DELETE FROM user_roles WHERE user_id = :uid");
            $del->execute([':uid' => $userId]);

            if (!empty($roleIds)) {
                $ins = $this->pdo->prepare(
                    "INSERT INTO user_roles (user_id, role_id) VALUES (:uid, :rid)"
                );

                foreach ($roleIds as $rid) {
                    $ins->execute([
                        ':uid' => $userId,
                        ':rid' => $rid,
                    ]);
                }
            }

            if ($startedHere) {
                $this->pdo->commit();
            }

        } catch (\PDOException $e) {
            if ($startedHere && $this->pdo->inTransaction()) {
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

        $startedHere = false;

        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedHere = true;
            }

            foreach ($users as $index => $userData) {
                $rowNum = $index + 1;
                $sp = "sp_user_import_" . $rowNum;
                if ($startedHere) {
                    $this->pdo->exec("SAVEPOINT {$sp}");
                }

                try {
                    if (empty($userData['username']) || empty($userData['email']) || empty($userData['full_name'])) {
                        throw new \Exception("Faltan campos obligatorios en fila {$rowNum}");
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
                    if ($startedHere) {
                        $this->pdo->exec("RELEASE SAVEPOINT {$sp}");
                    }

                } catch (\Throwable $e) {
                    $results['failed']++;
                    $results['errors'][] = "Fila {$rowNum}: " . $e->getMessage();
                    if ($startedHere) {
                        $this->pdo->exec("ROLLBACK TO SAVEPOINT {$sp}");
                        $this->pdo->exec("RELEASE SAVEPOINT {$sp}");
                    } else {

                    }
                }
            }

            if ($startedHere) {
                $this->pdo->commit();
            }

            return $results;

        } catch (\Throwable $e) {
            if ($startedHere && $this->pdo->inTransaction()) {
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