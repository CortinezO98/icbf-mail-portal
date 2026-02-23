<?php
declare(strict_types=1);


namespace App\Controllers;

use PDO;
use Exception;
use App\Auth\Csrf;
use App\Repos\UsersAdminRepo;
use App\Repos\EmailQueueRepo;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

final class UsersAdminController
{
    private UsersAdminRepo $repo;
    private EmailQueueRepo $mailQueue;
    private int $defaultPerPage = 20;

    public function __construct(private PDO $pdo, private array $config)
    {
        $this->repo = new UsersAdminRepo($pdo);
        $this->mailQueue = new EmailQueueRepo($pdo);
    }

    /**
     * ✅ Recomendación aplicada:
     * Solo hacer rollBack si hay una transacción activa.
     * (Evita: "There is no active transaction")
     */
    private function safeRollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function index(): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $search = trim((string)($_GET['search'] ?? ''));
        $isActive = isset($_GET['active']) && $_GET['active'] !== '' ? (int)$_GET['active'] : null;
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
                'totalPages' => (int)ceil($total / $this->defaultPerPage),
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

    /**
     * ✅ FIX: Lee payload de forma robusta (evita que $_POST llegue vacío)
     * - x-www-form-urlencoded / multipart: usa $_POST
     * - JSON: lee php://input y decodifica
     * - fallback: parse_str para urlencoded en body crudo
     */
    private function readRequestData(): array
    {
        if (!empty($_POST) && is_array($_POST)) {
            return $_POST;
        }

        $raw = file_get_contents('php://input') ?: '';
        $raw = trim($raw);
        if ($raw === '') return [];

        if (str_starts_with($raw, '{') || str_starts_with($raw, '[')) {
            $json = json_decode($raw, true);
            return is_array($json) ? $json : [];
        }

        $parsed = [];
        parse_str($raw, $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    public function create(): void
    {
        $post = $this->readRequestData();

        // CSRF
        Csrf::validate($post['_csrf'] ?? null);

        // ✅ checkbox: encolar email
        $sendWelcome = (string)($post['send_welcome_email'] ?? '') === '1';

        // ✅ Normaliza role_ids (a veces llega como string)
        $roleIds = $post['role_ids'] ?? [];
        if (!is_array($roleIds)) {
            $roleIds = [$roleIds];
        }

        $data = $this->validateUserData($post);

        if (empty($roleIds)) {
            $this->flash('error', 'Debes seleccionar al menos un rol.');
            $this->redirect('/admin/users/create');
        }

        // Verificar duplicados
        $this->checkDuplicates($data['document'], $data['username'], $data['email']);

        // Password
        $password = trim((string)($post['password'] ?? ''));
        if ($password === '') {
            $password = $this->generateTemporaryPassword();
        } else {
            if (!$this->validatePasswordStrength($password)) {
                $this->flash('error', 'La contraseña debe tener al menos 8 caracteres, incluyendo mayúsculas, minúsculas, números y símbolos.');
                $this->redirect('/admin/users/create');
            }
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $isActive = (int)($post['is_active'] ?? 1);
        $assignEnabled = isset($post['assign_enabled']) ? 1 : 0;

        // Limpieza final role_ids => int > 0
        $roleIds = array_values(array_filter(array_map('intval', $roleIds), fn($v) => $v > 0));
        if (empty($roleIds)) {
            $this->flash('error', 'Debes seleccionar al menos un rol válido.');
            $this->redirect('/admin/users/create');
        }

        $this->pdo->beginTransaction();
        try {
            $userId = $this->repo->createUser(
                $data['document'],
                $data['username'],
                $data['email'],
                $data['full_name'],
                $hash,
                $isActive,
                $assignEnabled
            );

            // user_roles tiene PK compuesta (user_id, role_id): OK.
            $this->repo->setUserRoles($userId, $roleIds);

            $this->pdo->commit();

            // ✅ ENCOLAR (después del commit) usando la plantilla profesional (EmailQueueRepo::buildWelcomeHtml)
            $mailQueued = false;

            if ($sendWelcome) {
                $loginUrl  = \App\Config\url('/login');
                $fromName  = (string)($this->config['mail']['from_name'] ?? 'ICBF Mail');
                $fromEmail = (string)($this->config['mail']['from_email'] ?? 'noreply@icbf.gov.co'); // opcional

                try {
                    // ✅ MISMA plantilla con logos + card + botón + primeros pasos
                    $this->mailQueue->enqueueWelcomeEmail(
                        $data['email'],              // toEmail
                        $data['full_name'] ?? null,  // toName (saludo)
                        $data['username'],           // username
                        $password,                   // tempPassword
                        $loginUrl,                   // loginUrl
                        $fromEmail,                  // fromEmail (aunque el HTML no lo use)
                        $fromName,                   // fromName
                        5                            // priority
                    );
                    $mailQueued = true;
                } catch (\Throwable $e) {
                    error_log('Mail enqueue failed (create user): ' . $e->getMessage());
                }
            }

            $extra = $sendWelcome
                ? ($mailQueued ? " Email encolado para envío a {$data['email']}." : " No se pudo encolar el email (revisa logs/BD).")
                : " (No se encoló email).";

            $this->flash(
                'success',
                "Usuario creado exitosamente. Contraseña temporal: {$password} (Guárdala de manera segura).{$extra}"
            );
            $this->redirect('/admin/users');
        } catch (Exception $e) {
            $this->safeRollback(); // ✅ evita "There is no active transaction"
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
            'editUser' => $user,
            'roles' => $roles,
            '_csrf' => Csrf::token(),
        ]);
    }

    public function update(int $id): void
    {
        $post = $this->readRequestData();

        Csrf::validate($post['_csrf'] ?? null);

        $user = $this->repo->findById($id);
        if (!$user) {
            $this->flash('error', 'Usuario no encontrado.');
            $this->redirect('/admin/users');
        }

        $roleIds = $post['role_ids'] ?? [];
        if (!is_array($roleIds)) $roleIds = [$roleIds];

        $data = $this->validateUserData($post, $id);

        if (empty($roleIds)) {
            $this->flash('error', 'Debes seleccionar al menos un rol.');
            $this->redirect('/admin/users/edit/' . $id);
        }

        $this->checkDuplicates($data['document'], $data['username'], $data['email'], $id);

        $updateData = [
            'username' => $data['username'],
            'email' => $data['email'],
            'full_name' => $data['full_name'],
        ];

        if ($data['document'] !== ($user['document'] ?? '')) {
            $updateData['document'] = $data['document'];
        }

        $newPassword = trim((string)($post['password'] ?? ''));
        if ($newPassword !== '') {
            if (!$this->validatePasswordStrength($newPassword)) {
                $this->flash('error', 'La contraseña debe tener al menos 8 caracteres, incluyendo mayúsculas, minúsculas, números y símbolos.');
                $this->redirect('/admin/users/edit/' . $id);
            }
            $updateData['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        if (isset($post['is_active'])) {
            $updateData['is_active'] = (int)$post['is_active'];
        }
        if (isset($post['assign_enabled'])) {
            $updateData['assign_enabled'] = (int)$post['assign_enabled'];
        }

        $roleIds = array_values(array_filter(array_map('intval', $roleIds), fn($v) => $v > 0));
        if (empty($roleIds)) {
            $this->flash('error', 'Debes seleccionar al menos un rol válido.');
            $this->redirect('/admin/users/edit/' . $id);
        }

        $this->pdo->beginTransaction();
        try {
            $this->repo->updateUser($id, $updateData);
            $this->repo->setUserRoles($id, $roleIds);

            $this->pdo->commit();

            $message = 'Usuario actualizado exitosamente.';
            if ($newPassword !== '') $message .= ' Nueva contraseña establecida.';
            $this->flash('success', $message);
            $this->redirect('/admin/users');
        } catch (Exception $e) {
            $this->safeRollback(); // ✅ evita "There is no active transaction"
            $this->flash('error', 'Error al actualizar usuario: ' . $e->getMessage());
            $this->redirect('/admin/users/edit/' . $id);
        }
    }

    public function toggleActive(int $id): void
    {
        $post = $this->readRequestData();
        Csrf::validate($post['_csrf'] ?? null);

        $user = $this->repo->findById($id);
        if (!$user) {
            $this->flash('error', 'Usuario no encontrado.');
            $this->redirect('/admin/users');
        }

        $newStatus = (int)(((int)$user['is_active'] === 1) ? 0 : 1);

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
        $post = $this->readRequestData();
        Csrf::validate($post['_csrf'] ?? null);

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

    /**
     * 📌 CONSERVADO - importExcel (compatibilidad)
     */
    public function importExcel(): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);

        if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            $this->flash('error', 'Por favor selecciona un archivo Excel válido.');
            $this->redirect('/admin/users/import');
        }

        $file = $_FILES['excel_file']['tmp_name'];
        $fileType = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));

        try {
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

            $usersToImport = [];
            $headers = array_map('trim', $rows[0]);

            for ($i = 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                $userData = [];

                foreach ($headers as $index => $header) {
                    if ($index < count($row)) {
                        $userData[$header] = trim((string)$row[$index]);
                    }
                }

                if (empty($userData['username']) || empty($userData['email']) || empty($userData['full_name'])) {
                    continue;
                }

                $usersToImport[] = $userData;
            }

            if (empty($usersToImport)) {
                throw new Exception('No se encontraron datos válidos para importar.');
            }

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

    /**
     * ✅ MÉTODO PRINCIPAL DE IMPORTACIÓN
     */
    public function import(): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);

        if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            $this->flash('error', 'Por favor selecciona un archivo válido.');
            $this->redirect('/admin/users/import');
        }

        $file = $_FILES['excel_file']['tmp_name'];
        $originalName = $_FILES['excel_file']['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            $this->flash('error', 'Formato de archivo no soportado. Use .xlsx, .xls o .csv');
            $this->redirect('/admin/users/import');
        }

        try {
            $reader = ($extension === 'csv') ? new Csv() : IOFactory::createReaderForFile($file);

            $spreadsheet = $reader->load($file);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            if (count($rows) <= 1) {
                $this->flash('error', 'El archivo debe contener al menos una fila de datos (excluyendo encabezados)');
                $this->redirect('/admin/users/import');
            }

            $allRoles = $this->repo->listRoles();
            $roleMap = [];
            foreach ($allRoles as $role) {
                $roleMap[strtoupper((string)$role['code'])] = (int)$role['id'];
            }

            $headers = array_map('trim', $rows[0]);
            $columnMapping = [
                'documento' => 'document',
                'document' => 'document',
                'usuario' => 'username',
                'username' => 'username',
                'email' => 'email',
                'correo' => 'email',
                'nombre' => 'full_name',
                'nombre completo' => 'full_name',
                'full_name' => 'full_name',
                'roles' => 'roles',
                'rol' => 'roles',
                'activo' => 'is_active',
                'is_active' => 'is_active',
                'asignable' => 'assign_enabled',
                'assign_enabled' => 'assign_enabled',
                'contraseña' => 'password',
                'password' => 'password'
            ];

            $normalizedHeaders = [];
            foreach ($headers as $index => $header) {
                $lower = strtolower($header);
                $normalizedHeaders[$index] = $columnMapping[$lower] ?? $lower;
            }

            $successCount = 0;
            $errorCount = 0;
            $errors = [];
            $createdUsers = [];

            $skipDuplicates = (string)($_POST['skip_duplicates'] ?? '') === '1';
            $sendWelcome    = (string)($_POST['send_welcome_email'] ?? '') === '1';

            for ($i = 1; $i < count($rows); $i++) {
                $rowData = [];
                $row = $rows[$i];
                $rowNum = $i + 1;

                foreach ($normalizedHeaders as $colIndex => $fieldName) {
                    $rowData[$fieldName] = isset($row[$colIndex]) ? trim((string)$row[$colIndex]) : '';
                }

                try {
                    if (empty($rowData['username']) || empty($rowData['email']) || empty($rowData['full_name'])) {
                        throw new Exception("Faltan campos obligatorios (username, email, full_name)");
                    }

                    if (!filter_var($rowData['email'], FILTER_VALIDATE_EMAIL)) {
                        throw new Exception("Email inválido: {$rowData['email']}");
                    }

                    $document = $rowData['document'] ?? '';
                    if ($document !== '' && !preg_match('/^\d{6,15}$/', $document)) {
                        throw new Exception("Documento debe contener solo números (6-15 dígitos): {$document}");
                    }

                    if ($skipDuplicates) {
                        if ($this->repo->findByUsername($rowData['username'])) {
                            throw new Exception("Usuario '{$rowData['username']}' ya existe (omitido)");
                        }
                        if ($this->repo->findByEmail($rowData['email'])) {
                            throw new Exception("Email '{$rowData['email']}' ya existe (omitido)");
                        }
                        if ($document !== '' && $this->repo->findByDocument($document)) {
                            throw new Exception("Documento '{$document}' ya existe (omitido)");
                        }
                    }

                    $roleIds = [];
                    if (!empty($rowData['roles'])) {
                        $roleCodes = array_map('trim', explode(',', strtoupper((string)$rowData['roles'])));
                        foreach ($roleCodes as $code) {
                            if (isset($roleMap[$code])) $roleIds[] = (int)$roleMap[$code];
                        }
                    }

                    if (empty($roleIds) && isset($roleMap['AGENTE'])) {
                        $roleIds[] = (int)$roleMap['AGENTE'];
                    }

                    $isActive = isset($rowData['is_active'])
                        ? ((in_array(strtolower((string)$rowData['is_active']), ['si', '1', 'true'], true)) ? 1 : 0)
                        : 1;

                    $assignEnabled = isset($rowData['assign_enabled'])
                        ? ((in_array(strtolower((string)$rowData['assign_enabled']), ['si', '1', 'true'], true)) ? 1 : 0)
                        : 1;

                    $password = $rowData['password'] ?? '';
                    if ($password === '') $password = $this->generateTemporaryPassword();

                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    $this->pdo->beginTransaction();
                    try {
                        $userId = $this->repo->createUser(
                            $document,
                            $rowData['username'],
                            $rowData['email'],
                            $rowData['full_name'],
                            $hash,
                            $isActive,
                            $assignEnabled
                        );

                        if (!empty($roleIds)) {
                            $roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds), fn($v) => $v > 0)));
                            $this->repo->setUserRoles($userId, $roleIds);
                        }

                        $this->pdo->commit();
                    } catch (Exception $e) {
                        $this->safeRollback();
                        throw $e;
                    }

                    $successCount++;
                    $createdUsers[] = [
                        'id' => $userId,
                        'username' => $rowData['username'],
                        'email' => $rowData['email'],
                        'password' => $password,
                        'full_name' => $rowData['full_name'],
                    ];
                } catch (Exception $e) {
                    $errorCount++;
                    $errors[] = "Fila {$rowNum}: " . $e->getMessage();
                }
            }
            $queuedCount = 0;
            $queueFailCount = 0;

            if ($sendWelcome && !empty($createdUsers)) {
                $loginUrl  = \App\Config\url('/login');
                $fromName  = (string)($this->config['mail']['from_name'] ?? 'ICBF Mail');
                $fromEmail = (string)($this->config['mail']['from_email'] ?? 'noreply@icbf.gov.co'); 

                foreach ($createdUsers as $user) {
                    try {
                        $this->mailQueue->enqueueWelcomeEmail(
                            $user['email'],
                            $user['full_name'] ?? null,
                            $user['username'],
                            $user['password'],
                            $loginUrl,
                            $fromEmail,
                            $fromName,
                            5
                        );
                        $queuedCount++;
                    } catch (\Throwable $e) {
                        $queueFailCount++;
                        error_log("Mail enqueue failed (import) for {$user['email']}: " . $e->getMessage());
                    }
                }
            }

            $message = "Importación completada: {$successCount} usuarios creados.";
            if ($errorCount > 0) $message .= " {$errorCount} con error.";

            if ($sendWelcome) {
                $message .= " Correos encolados: {$queuedCount}.";
                if ($queueFailCount > 0) $message .= " Fallidos al encolar: {$queueFailCount}.";
            }

            if (!empty($errors)) {
                $_SESSION['_import_errors'] = array_slice($errors, 0, 10);
                $_SESSION['_import_errors_total'] = count($errors);
            }

            $this->flash($errorCount > 0 ? 'warning' : 'success', $message);
            $this->redirect('/admin/users');
        } catch (Exception $e) {
            $this->flash('error', 'Error en importación: ' . $e->getMessage());
            $this->redirect('/admin/users/import');
        }
    }


    /**
     * 📌 CONSERVADO (compatibilidad): envío directo por mail()
     * Ya NO se usa en create/import si estás en modo cola.
     */
    private function sendWelcomeEmail(string $email, string $username, string $password): bool
    {
        $subject = 'Bienvenido al Sistema ICBF Mail';
        $message = "
            <html>
            <body>
                <h2>Bienvenido al Sistema de Gestión de Correo ICBF</h2>
                <p>Tu cuenta ha sido creada exitosamente.</p>
                <div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0;'>
                    <strong>Usuario:</strong> {$username}<br>
                    <strong>Contraseña temporal:</strong> {$password}<br>
                    <strong>Acceso:</strong> " . \App\Config\url('/login') . "
                </div>
                <p><em>Por seguridad, cambia tu contraseña en tu primer acceso.</em></p>
                <p>Saludos,<br>Equipo ICBF</p>
            </body>
            </html>
        ";

        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: ICBF Mail <noreply@icbf.gov.co>\r\n";

        return mail($email, $subject, $message, $headers);
    }

    public function exportTemplate(): void
    {
        try {
            if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
                throw new \Exception('PhpSpreadsheet no está instalado o no cargó el autoload.');
            }

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            $headers = ['Documento', 'Username', 'Email', 'Nombre Completo', 'Roles (separados por coma)'];
            $sheet->fromArray($headers, null, 'A1');

            $headerStyle = [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E6E6FA']
                ]
            ];
            $sheet->getStyle('A1:E1')->applyFromArray($headerStyle);

            $exampleData = [
                ['1012345678', 'usuario1', 'usuario1@ejemplo.com', 'Juan Pérez', 'AGENTE,SUPERVISOR'],
                ['1023456789', 'usuario2', 'usuario2@ejemplo.com', 'María García', 'AGENTE'],
                ['', 'usuario3', 'usuario3@ejemplo.com', 'Carlos López', 'ADMIN'],
            ];
            $sheet->fromArray($exampleData, null, 'A2');

            foreach (range('A', 'E') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="plantilla_usuarios.xlsx"');
            header('Cache-Control: max-age=0');
            header('Pragma: no-cache');

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
            exit;

        } catch (\Throwable $e) {
            $this->flash('error', 'Error al descargar plantilla: ' . ($this->config['debug'] ? $e->getMessage() : 'Error interno'));
            $this->redirect('/admin/users/import');
        }
    }

    public function exportExcel(): void
    {
        try {
            if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
                throw new Exception('PhpSpreadsheet no está instalado');
            }

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Usuarios');

            $headers = [
                'ID', 'Documento', 'Usuario', 'Email', 'Nombre Completo',
                'Roles', 'Activo', 'Asignable', 'Último Login', 'Creado'
            ];
            $sheet->fromArray($headers, null, 'A1');

            $allUsers = $this->getAllUsersForExport();

            $row = 2;
            foreach ($allUsers as $user) {
                $sheet->setCellValue('A' . $row, $user['id']);
                $sheet->setCellValue('B' . $row, $user['document'] ?? '');
                $sheet->setCellValue('C' . $row, $user['username']);
                $sheet->setCellValue('D' . $row, $user['email']);
                $sheet->setCellValue('E' . $row, $user['full_name']);
                $sheet->setCellValue('F' . $row, $user['roles'] ?? '');
                $sheet->setCellValue('G' . $row, ((int)$user['is_active'] === 1) ? 'Sí' : 'No');
                $sheet->setCellValue('H' . $row, ((int)$user['assign_enabled'] === 1) ? 'Sí' : 'No');
                $sheet->setCellValue('I' . $row, $user['last_login_at'] ?? 'Nunca');
                $sheet->setCellValue('J' . $row, $user['created_at']);
                $row++;
            }

            foreach (range('A', 'J') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            $headerStyle = [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E6E6FA']
                ],
                'alignment' => ['horizontal' => 'center']
            ];
            $sheet->getStyle('A1:J1')->applyFromArray($headerStyle);

            $sheet->getStyle('I:J')->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm');

            $filename = 'usuarios_icbf_' . date('Ymd_His') . '.xlsx';

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            header('Pragma: no-cache');

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
            exit;
        } catch (Exception $e) {
            $this->flash('error', 'Error al exportar: ' . $e->getMessage());
            $this->redirect('/admin/users');
        }
    }

    private function getAllUsersForExport(): array
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
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function validateUserData(array $data, ?int $excludeUserId = null): array
    {
        $document = trim((string)($data['document'] ?? ''));
        $username = trim((string)($data['username'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $fullName = trim((string)($data['full_name'] ?? ''));

        if ($username === '' || $email === '' || $fullName === '') {
            throw new Exception('Faltan campos obligatorios: username, email o full_name');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Email inválido');
        }

        if ($document !== '' && !preg_match('/^\d{6,15}$/', $document)) {
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
        if ($document !== '') {
            $existing = $this->repo->findByDocument($document);
            if ($existing && (int)$existing['id'] !== (int)$excludeUserId) {
                throw new Exception("Ya existe un usuario con documento: {$document}");
            }
        }

        $existing = $this->repo->findByUsername($username);
        if ($existing && (int)$existing['id'] !== (int)$excludeUserId) {
            throw new Exception("Ya existe un usuario con username: {$username}");
        }

        $existing = $this->repo->findByEmail($email);
        if ($existing && (int)$existing['id'] !== (int)$excludeUserId) {
            throw new Exception("Ya existe un usuario con email: {$email}");
        }
    }

    private function generateTemporaryPassword(): string
    {
        $prefix = 'IqICBF';

        // MAYÚSCULAS sin letras confusas (O/I/S/B)
        $letters = 'ACDEFGHJKLMNPQRTUVWXYZ';

        // Números sin confusos (0/1/5/8)
        $digits = '234679';

        // Símbolo fijo para dictado (elige uno)
        $symbol = '@';

        // 3 letras
        $block = '';
        for ($i = 0; $i < 3; $i++) {
            $block .= $letters[random_int(0, strlen($letters) - 1)];
        }

        // 3 números
        $nums = '';
        for ($i = 0; $i < 3; $i++) {
            $nums .= $digits[random_int(0, strlen($digits) - 1)];
        }

        return "{$prefix}-{$block}-{$nums}{$symbol}";
    }


    private function validatePasswordStrength(string $password): bool
    {
        return (bool)preg_match(
            '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@])[-A-Za-z\d@]{8,}$/',
            $password
        );
    }

    private function mapRoleCodesToIds(array $roleCodes): array
    {
        $allRoles = $this->repo->listRoles();
        $roleMap = [];

        foreach ($allRoles as $role) {
            $roleMap[(string)$role['code']] = (int)$role['id'];
        }

        $roleIds = [];
        foreach ($roleCodes as $code) {
            if (isset($roleMap[$code])) {
                $roleIds[] = (int)$roleMap[$code];
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
