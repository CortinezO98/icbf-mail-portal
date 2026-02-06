<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use Exception;
use App\Auth\Csrf;
use App\Repos\UsersAdminRepo;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class UsersAdminController
{
    private UsersAdminRepo $repo;
    private int $defaultPerPage = 20;

    public function __construct(private PDO $pdo, private array $config)
    {
        $this->repo = new UsersAdminRepo($pdo);
    }

    public function index(): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $search = trim((string)($_GET['search'] ?? ''));
        $isActive = isset($_GET['active']) ? (int)$_GET['active'] : null;
        $roleId = isset($_GET['role_id']) ? (int)$_GET['role_id'] : null;
        
        $users = $this->repo->listUsers($page, $this->defaultPerPage, $search, $isActive, $roleId);
        $total = $this->repo->countUsers($search, $isActive, $roleId);
        $roles = $this->repo->listRoles();
        
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);

        $this->render('admin/users/index.php', [
            'users' => $users,
            'roles' => $roles,
            'flash' => $flash,
            '_csrf' => Csrf::token(),
            'search' => $search,
            'isActive' => $isActive,
            'roleId' => $roleId,
            'pagination' => [
                'page' => $page,
                'perPage' => $this->defaultPerPage,
                'total' => $total,
                'totalPages' => ceil($total / $this->defaultPerPage),
                'hasPrev' => $page > 1,
                'hasNext' => ($page * $this->defaultPerPage) < $total,
            ],
            'stats' => $this->repo->getStatistics(),
        ]);
    }

    public function showCreate(): void
    {
        $roles = $this->repo->listRoles();
        
        $this->render('admin/users/create.php', [
            'roles' => $roles,
            '_csrf' => Csrf::token(),
        ]);
    }

    public function create(): void
    {
        Csrf::verify();

        $data = $this->validateUserData($_POST);
        $roleIds = $_POST['role_ids'] ?? [];
        
        if (!is_array($roleIds) || empty($roleIds)) {
            $this->flash('error', 'Debes seleccionar al menos un rol.');
            $this->redirect('/admin/users/create');
        }

        // Verificar duplicados
        $this->checkDuplicates($data['document'], $data['username'], $data['email']);

        // Generar password temporal si no se proporciona
        $password = trim((string)($_POST['password'] ?? ''));
        if (empty($password)) {
            $password = $this->generateTemporaryPassword();
        }

        // Validar fortaleza de password
        if (!$this->validatePasswordStrength($password)) {
            $this->flash('error', 'La contraseña debe tener al menos 8 caracteres, incluyendo mayúsculas, minúsculas, números y símbolos.');
            $this->redirect('/admin/users/create');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $userId = $this->repo->createUser(
                $data['document'],
                $data['username'],
                $data['email'],
                $data['full_name'],
                $hash,
                (int)($_POST['is_active'] ?? 1),
                (int)($_POST['assign_enabled'] ?? 1)
            );

            $this->repo->setUserRoles($userId, $roleIds);

            $this->flash('success', 
                "Usuario creado exitosamente. " .
                "Contraseña temporal: <code>{$password}</code> (Guárdala de manera segura)"
            );
            
            $this->redirect('/admin/users');

        } catch (Exception $e) {
            $this->flash('error', 'Error al crear usuario: ' . $e->getMessage());
            $this->redirect('/admin/users/create');
        }
    }

    public function showEdit(int $id): void
    {
        $user = $this->repo->findById($id);
        
        if (!$user) {
            $this->flash('error', 'Usuario no encontrado.');
            $this->redirect('/admin/users');
        }

        $roles = $this->repo->listRoles();
        
        $this->render('admin/users/edit.php', [
            'user' => $user,
            'roles' => $roles,
            '_csrf' => Csrf::token(),
        ]);
    }

    public function update(int $id): void
    {
        Csrf::verify();

        $user = $this->repo->findById($id);
        if (!$user) {
            $this->flash('error', 'Usuario no encontrado.');
            $this->redirect('/admin/users');
        }

        $data = $this->validateUserData($_POST, $id);
        $roleIds = $_POST['role_ids'] ?? [];
        
        if (!is_array($roleIds) || empty($roleIds)) {
            $this->flash('error', 'Debes seleccionar al menos un rol.');
            $this->redirect('/admin/users/edit/' . $id);
        }

        // Verificar duplicados excluyendo el usuario actual
        $this->checkDuplicates($data['document'], $data['username'], $data['email'], $id);

        $updateData = [
            'username' => $data['username'],
            'email' => $data['email'],
            'full_name' => $data['full_name'],
        ];

        // Solo actualizar documento si es diferente
        if ($data['document'] !== ($user['document'] ?? '')) {
            $updateData['document'] = $data['document'];
        }

        // Actualizar password si se proporciona una nueva
        $newPassword = trim((string)($_POST['password'] ?? ''));
        if (!empty($newPassword)) {
            if (!$this->validatePasswordStrength($newPassword)) {
                $this->flash('error', 'La contraseña debe tener al menos 8 caracteres, incluyendo mayúsculas, minúsculas, números y símbolos.');
                $this->redirect('/admin/users/edit/' . $id);
            }
            $updateData['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        // Campos booleanos
        if (isset($_POST['is_active'])) {
            $updateData['is_active'] = (int)$_POST['is_active'];
        }
        
        if (isset($_POST['assign_enabled'])) {
            $updateData['assign_enabled'] = (int)$_POST['assign_enabled'];
        }

        try {
            $this->pdo->beginTransaction();
            
            $this->repo->updateUser($id, ...array_values($updateData));
            $this->repo->setUserRoles($id, $roleIds);
            
            $this->pdo->commit();
            
            $message = 'Usuario actualizado exitosamente.';
            if (!empty($newPassword)) {
                $message .= ' Nueva contraseña establecida.';
            }
            
            $this->flash('success', $message);
            $this->redirect('/admin/users');

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->flash('error', 'Error al actualizar usuario: ' . $e->getMessage());
            $this->redirect('/admin/users/edit/' . $id);
        }
    }

    public function toggleActive(int $id): void
    {
        Csrf::verify();

        $user = $this->repo->findById($id);
        if (!$user) {
            $this->flash('error', 'Usuario no encontrado.');
            $this->redirect('/admin/users');
        }

        $newStatus = (int)($user['is_active'] === 1 ? 0 : 1);
        
        try {
            $this->repo->toggleActive($id, $newStatus);
            
            $statusText = $newStatus === 1 ? 'activado' : 'desactivado';
            $this->flash('success', "Usuario {$statusText} exitosamente.");
            
        } catch (Exception $e) {
            $this->flash('error', 'Error al cambiar estado: ' . $e->getMessage());
        }
        
        $this->redirect('/admin/users');
    }

    public function delete(int $id): void
    {
        Csrf::verify();

        $user = $this->repo->findById($id);
        if (!$user) {
            $this->flash('error', 'Usuario no encontrado.');
            $this->redirect('/admin/users');
        }

        try {
            $this->repo->deleteUser($id);
            $this->flash('success', 'Usuario eliminado exitosamente.');
            
        } catch (Exception $e) {
            $this->flash('error', 'Error al eliminar usuario: ' . $e->getMessage());
        }
        
        $this->redirect('/admin/users');
    }

    public function showImport(): void
    {
        $roles = $this->repo->listRoles();
        
        $this->render('admin/users/import.php', [
            'roles' => $roles,
            '_csrf' => Csrf::token(),
        ]);
    }

    public function importExcel(): void
    {
        Csrf::verify();

        if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            $this->flash('error', 'Por favor selecciona un archivo Excel válido.');
            $this->redirect('/admin/users/import');
        }

        $file = $_FILES['excel_file']['tmp_name'];
        $fileType = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));

        try {
            // Cargar archivo Excel
            if ($fileType === 'xlsx') {
                $spreadsheet = IOFactory::load($file);
            } elseif ($fileType === 'csv') {
                $spreadsheet = IOFactory::load($file);
            } else {
                throw new Exception('Formato de archivo no soportado. Usa .xlsx o .csv');
            }

            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            if (count($rows) < 2) {
                throw new Exception('El archivo debe contener al menos una fila de datos (excluyendo encabezados)');
            }

            // Procesar filas
            $usersToImport = [];
            $headers = array_map('trim', $rows[0]);
            
            // Mapeo de columnas esperadas
            $expectedHeaders = ['document', 'username', 'email', 'full_name', 'roles'];
            
            for ($i = 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                $userData = [];
                
                foreach ($headers as $index => $header) {
                    if ($index < count($row)) {
                        $userData[$header] = trim((string)$row[$index]);
                    }
                }
                
                // Validar datos mínimos
                if (empty($userData['username']) || empty($userData['email']) || empty($userData['full_name'])) {
                    continue; // O podrías registrar error
                }
                
                // Procesar roles
                if (!empty($userData['roles'])) {
                    $roleCodes = array_map('trim', explode(',', $userData['roles']));
                    $roleIds = $this->mapRoleCodesToIds($roleCodes);
                    $userData['roles'] = $roleIds;
                }
                
                $usersToImport[] = $userData;
            }

            if (empty($usersToImport)) {
                throw new Exception('No se encontraron datos válidos para importar.');
            }

            // Importar usuarios
            $result = $this->repo->importUsersFromArray($usersToImport);
            
            $message = sprintf(
                "Importación completada: %d usuarios creados, %d fallidos.",
                $result['success'],
                $result['failed']
            );
            
            if (!empty($result['errors'])) {
                $message .= " Errores: " . implode('; ', array_slice($result['errors'], 0, 5));
                if (count($result['errors']) > 5) {
                    $message .= "... y " . (count($result['errors']) - 5) . " más.";
                }
            }
            
            $this->flash('success', $message);
            $this->redirect('/admin/users');

        } catch (Exception $e) {
            $this->flash('error', 'Error en importación: ' . $e->getMessage());
            $this->redirect('/admin/users/import');
        }
    }

    public function exportTemplate(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Encabezados
        $headers = ['Documento', 'Username', 'Email', 'Nombre Completo', 'Roles (separados por coma)'];
        $sheet->fromArray($headers, null, 'A1');
        
        // Estilos para encabezados
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E6E6FA']
            ]
        ];
        
        $sheet->getStyle('A1:E1')->applyFromArray($headerStyle);
        
        // Datos de ejemplo
        $exampleData = [
            ['1012345678', 'usuario1', 'usuario1@ejemplo.com', 'Juan Pérez', 'AGENTE,SUPERVISOR'],
            ['1023456789', 'usuario2', 'usuario2@ejemplo.com', 'María García', 'AGENTE'],
            ['', 'usuario3', 'usuario3@ejemplo.com', 'Carlos López', 'ADMIN'],
        ];
        
        $sheet->fromArray($exampleData, null, 'A2');
        
        // Auto tamaño columnas
        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Enviar archivo
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="plantilla_usuarios.xlsx"');
        header('Cache-Control: max-age=0');
        
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    private function validateUserData(array $data, ?int $excludeUserId = null): array
    {
        $document = trim((string)($data['document'] ?? ''));
        $username = trim((string)($data['username'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $fullName = trim((string)($data['full_name'] ?? ''));

        if (empty($username) || empty($email) || empty($fullName)) {
            throw new Exception('Faltan campos obligatorios: username, email o full_name');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Email inválido');
        }

        // Validar formato de documento si se proporciona
        if (!empty($document) && !preg_match('/^\d{6,15}$/', $document)) {
            throw new Exception('Documento debe contener solo números (6-15 dígitos)');
        }

        return [
            'document' => $document,
            'username' => $username,
            'email' => $email,
            'full_name' => $fullName,
        ];
    }

    private function checkDuplicates(string $document, string $username, string $email, ?int $excludeUserId = null): void
    {
        // Verificar documento
        if (!empty($document)) {
            $existing = $this->repo->findByDocument($document);
            if ($existing && $existing['id'] !== $excludeUserId) {
                throw new Exception("Ya existe un usuario con documento: {$document}");
            }
        }

        // Verificar username
        $existing = $this->repo->findByUsername($username);
        if ($existing && $existing['id'] !== $excludeUserId) {
            throw new Exception("Ya existe un usuario con username: {$username}");
        }

        // Verificar email
        $existing = $this->repo->findByEmail($email);
        if ($existing && $existing['id'] !== $excludeUserId) {
            throw new Exception("Ya existe un usuario con email: {$email}");
        }
    }

    private function generateTemporaryPassword(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        
        // Asegurar al menos un carácter de cada tipo
        $password .= chr(rand(65, 90)); // Mayúscula
        $password .= chr(rand(97, 122)); // Minúscula
        $password .= rand(0, 9); // Número
        $password .= '!@#$%^&*'[rand(0, 7)]; // Símbolo
        
        // Completar hasta 10 caracteres
        for ($i = 0; $i < 6; $i++) {
            $password .= $chars[rand(0, strlen($chars) - 1)];
        }
        
        return str_shuffle($password);
    }

    private function validatePasswordStrength(string $password): bool
    {
        // Al menos 8 caracteres, una mayúscula, una minúscula, un número y un símbolo
        return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $password);
    }

    private function mapRoleCodesToIds(array $roleCodes): array
    {
        $allRoles = $this->repo->listRoles();
        $roleMap = [];
        
        foreach ($allRoles as $role) {
            $roleMap[$role['code']] = $role['id'];
        }
        
        $roleIds = [];
        foreach ($roleCodes as $code) {
            if (isset($roleMap[$code])) {
                $roleIds[] = $roleMap[$code];
            }
        }
        
        return $roleIds;
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
    }

    private function redirect(string $path): void
    {
        header('Location: ' . \App\Config\url($path));
        exit;
    }

    private function render(string $view, array $params = []): void
    {
        extract($params, EXTR_SKIP);
        $viewPath = dirname(__DIR__) . '/views/' . $view;
        include dirname(__DIR__) . '/views/layout.php';
    }
}