<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use Throwable;
use App\Repos\UsersRepo;
use App\Repos\EmailQueueRepo;
use App\Repos\SecurityLogRepo; 
use App\Auth\Auth;
use App\Auth\Csrf;

use function App\Config\url;
use function App\Config\public_url;

final class AuthController
{
    private UsersRepo $usersRepo;
    private EmailQueueRepo $emailQueueRepo;
    private SecurityLogRepo $securityLogRepo; 

    public function __construct(private PDO $pdo, private array $config)
    {
        Auth::initSession($this->config);

        $this->usersRepo = new UsersRepo($pdo);
        $this->emailQueueRepo = new EmailQueueRepo($pdo);
        $this->securityLogRepo = new SecurityLogRepo($pdo); 
    }

    public function showLogin(): void
    {
        $error = $_SESSION['_flash_error'] ?? null;
        $success = $_SESSION['_flash_success'] ?? null;

        unset($_SESSION['_flash_error'], $_SESSION['_flash_success']);

        $this->render('auth/login.php', [
            'error' => $error,
            'success' => $success,
        ]);
    }

    public function login(): void
    {
        Auth::initSession($this->config);

        Csrf::validate($_POST['_csrf'] ?? null);

        $login = trim((string)($_POST['login'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($login === '' || $password === '') {
            $_SESSION['_flash_error'] = 'Usuario y contraseña son obligatorios.';
            header('Location: ' . url('/login'));
            exit;
        }

        $user = $this->usersRepo->findByUsernameOrEmail($login);
        if (!$user || !password_verify($password, (string)($user['password_hash'] ?? ''))) {
            $_SESSION['_flash_error'] = 'Credenciales inválidas.';
            header('Location: ' . url('/login'));
            exit;
        }

        $roles = $this->usersRepo->rolesForUser((int)$user['id']);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        Auth::login($user, $roles);

        header('Location: ' . url('/cases'));
        exit;
    }

    public function logout(): void
    {
        Auth::initSession($this->config);

        Csrf::validate($_POST['_csrf'] ?? null);
        Auth::logout();

        header('Location: ' . url('/login'));
        exit;
    }

    public function showForgotPassword(): void
    {
        Auth::initSession($this->config);

        $message = $_SESSION['_flash_success'] ?? null;
        $error = $_SESSION['_flash_error'] ?? null;

        unset($_SESSION['_flash_success'], $_SESSION['_flash_error']);

        $this->render('auth/forgot_password.php', [
            'message' => $message,
            'error' => $error,
        ]);
    }

    public function sendResetLink(): void
    {
        Auth::initSession($this->config);

        Csrf::validate($_POST['_csrf'] ?? null);

        $email = trim((string)($_POST['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['_flash_error'] = 'Debes ingresar un correo válido.';
            header('Location: ' . url('/forgot-password'));
            exit;
        }

        // ✅ LOG DE SOLICITUD DE RESET
        $this->securityLogRepo->log(
            'PASSWORD_RESET_REQUEST',
            null,
            $email,
            true,
            ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]
        );

        $rateLimit = $this->checkRateLimit($email);
        if (!$rateLimit['allowed']) {
            // ✅ LOG DE RATE LIMIT EXCEDIDO
            $this->securityLogRepo->log(
                'PASSWORD_RESET_RATE_LIMIT',
                null,
                $email,
                false,
                ['reason' => 'rate_limit_exceeded', 'attempts' => $rateLimit['attempts'] ?? 3]
            );
            
            $_SESSION['_flash_error'] = $rateLimit['message'];
            header('Location: ' . url('/forgot-password'));
            exit;
        }

        $user = $this->usersRepo->findActiveByEmail($email);

        if ($user) {
            $plainToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $plainToken);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

            $this->usersRepo->storePasswordResetToken(
                (int)$user['id'],
                (string)$user['email'],
                $tokenHash,
                $expiresAt
            );

            $this->securityLogRepo->log(
                'PASSWORD_RESET_TOKEN_GENERATED',
                (int)$user['id'],
                (string)$user['email'],
                true,
                ['expires_in' => '30 minutes']
            );

            $resetUrl = public_url('/reset-password?token=' . urlencode($plainToken));

            $this->sendPasswordResetEmail(
                (string)$user['email'],
                (string)($user['full_name'] ?? $user['username']),
                $resetUrl
            );
        } else {
            $this->securityLogRepo->log(
                'PASSWORD_RESET_EMAIL_NOT_FOUND',
                null,
                $email,
                false,
                ['reason' => 'email_not_registered']
            );
        }

        $_SESSION['_flash_success'] = 'Si el correo existe en el sistema, recibirás un enlace para restablecer la contraseña.';
        header('Location: ' . url('/forgot-password'));
        exit;
    }

    public function showResetPassword(): void
    {
        Auth::initSession($this->config);

        $token = trim((string)($_GET['token'] ?? ''));
        if ($token === '') {
            $this->securityLogRepo->log(
                'PASSWORD_RESET_EMPTY_TOKEN',
                null,
                null,
                false,
                ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]
            );
            
            http_response_code(400);
            echo 'Token inválido';
            exit;
        }

        $error = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash_error']);

        $this->render('auth/reset_password.php', [
            'token' => $token,
            'error' => $error,
        ]);
    }

    public function resetPassword(): void
    {
        Auth::initSession($this->config);

        Csrf::validate($_POST['_csrf'] ?? null);

        $token = trim((string)($_POST['token'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

        if ($token === '' || $password === '' || $passwordConfirm === '') {
            // ✅ LOG DE CAMPOS INCOMPLETOS
            $this->securityLogRepo->log(
                'PASSWORD_RESET_INCOMPLETE',
                null,
                null,
                false,
                ['missing_fields' => true]
            );
            
            $_SESSION['_flash_error'] = 'Todos los campos son obligatorios.';
            header('Location: ' . url('/reset-password?token=' . urlencode($token)));
            exit;
        }

        if ($password !== $passwordConfirm) {
            $this->securityLogRepo->log(
                'PASSWORD_RESET_MISMATCH',
                null,
                null,
                false,
                ['reason' => 'passwords_do_not_match']
            );
            
            $_SESSION['_flash_error'] = 'Las contraseñas no coinciden.';
            header('Location: ' . url('/reset-password?token=' . urlencode($token)));
            exit;
        }

        if (!$this->isStrongPassword($password)) {
            $this->securityLogRepo->log(
                'PASSWORD_RESET_WEAK_PASSWORD',
                null,
                null,
                false,
                ['reason' => 'password_too_weak']
            );
            
            $_SESSION['_flash_error'] = 'La contraseña debe tener mínimo 8 caracteres, mayúscula, minúscula, número y símbolo.';
            header('Location: ' . url('/reset-password?token=' . urlencode($token)));
            exit;
        }

        $tokenHash = hash('sha256', $token);
        $reset = $this->usersRepo->findValidPasswordResetByTokenHash($tokenHash);

        if (!$reset) {
            $this->securityLogRepo->log(
                'PASSWORD_RESET_INVALID_TOKEN',
                null,
                null,
                false,
                ['token_hash' => substr($tokenHash, 0, 8) . '...']
            );
            
            $_SESSION['_flash_error'] = 'El enlace no es válido o ya expiró.';
            header('Location: ' . url('/forgot-password'));
            exit;
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $this->pdo->beginTransaction();

        try {
            $this->usersRepo->updatePasswordHash((int)$reset['user_id'], $passwordHash);
            $this->usersRepo->markPasswordResetUsed((int)$reset['id']);

            $this->pdo->commit();

            $this->securityLogRepo->log(
                'PASSWORD_RESET_SUCCESS',
                (int)$reset['user_id'],
                (string)$reset['email'],
                true,
                ['reset_id' => (int)$reset['id']]
            );

            $this->sendPasswordChangedEmail(
                (string)$reset['email'],
                (string)($reset['user_email'] ?? 'Usuario')
            );

            $_SESSION['_flash_success'] = 'Tu contraseña fue actualizada correctamente.';
            header('Location: ' . url('/login'));
            exit;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->securityLogRepo->log(
                'PASSWORD_RESET_ERROR',
                (int)($reset['user_id'] ?? null),
                (string)($reset['email'] ?? null),
                false,
                ['error' => $e->getMessage()]
            );

            $_SESSION['_flash_error'] = 'No se pudo actualizar la contraseña.';
            header('Location: ' . url('/reset-password?token=' . urlencode($token)));
            exit;
        }
    }

    /**
     * ✅ Rate limiting para evitar spam de emails
     */
    private function checkRateLimit(string $email): array
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = 'rate_limit_' . md5($ip . '_' . $email);
        
        $now = time();
        $limit = 3; // Máximo 3 solicitudes
        $window = 3600; // En 1 hora
        
        $data = $_SESSION[$key] ?? ['count' => 0, 'first' => $now];
        
        // Resetear si pasó la ventana
        if ($now - $data['first'] > $window) {
            $data = ['count' => 1, 'first' => $now];
            $_SESSION[$key] = $data;
            return ['allowed' => true, 'attempts' => 1];
        }
        
        // Incrementar contador
        $data['count']++;
        $_SESSION[$key] = $data;
        
        if ($data['count'] > $limit) {
            $minutesLeft = ceil(($window - ($now - $data['first'])) / 60);
            return [
                'allowed' => false,
                'attempts' => $data['count'],
                'message' => "Has superado el límite de solicitudes. Intenta en {$minutesLeft} minutos."
            ];
        }
        
        return ['allowed' => true, 'attempts' => $data['count']];
    }

    /**
     * ✅ Enviar email de recuperación
     */
    private function sendPasswordResetEmail(string $toEmail, string $toName, string $resetUrl): void
    {
        $fromName = (string)($this->config['mail']['from_name'] ?? 'ICBF Mail');
        $fromEmail = (string)($this->config['mail']['from_email'] ?? 'noreply@icbf.gov.co');

        $subject = '🔐 Recuperación de contraseña - ICBF Mail';

        $bodyHtml = $this->buildResetEmailHtml($toName, $resetUrl, $fromName);

        $this->emailQueueRepo->enqueue(
            'PASSWORD_RESET',
            $toEmail,
            $toName,
            $subject,
            $bodyHtml,
            5 // prioridad
        );
    }

    /**
     *  Enviar email de confirmación de cambio de contraseña
     */
    private function sendPasswordChangedEmail(string $toEmail, string $toName): void
    {
        $fromName = (string)($this->config['mail']['from_name'] ?? 'ICBF Mail');
        $loginUrl = url('/login');

        $subject = '✅ Contraseña actualizada - ICBF Mail';

        $bodyHtml = $this->buildPasswordChangedEmailHtml($toName, $loginUrl, $fromName);

        $this->emailQueueRepo->enqueue(
            'PASSWORD_CHANGED',
            $toEmail,
            $toName,
            $subject,
            $bodyHtml,
            5
        );
    }

    /**
     * ✅ Construir HTML del email de recuperación
     */
    private function buildResetEmailHtml(string $toName, string $resetUrl, string $fromName): string
    {
        $logoIcbf = \App\Config\mail_asset_url('/assets/img/logo_icbf.png');
        $logoIq = \App\Config\mail_asset_url('/assets/img/logo_iq.png');
        $year = date('Y');

        $safeName = htmlspecialchars($toName ?: 'Usuario', ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
        $safeFrom = htmlspecialchars($fromName, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperación de contraseña</title>
</head>
<body style="margin:0; padding:0; font-family:'Segoe UI', Arial, sans-serif; background-color:#f4f7fb;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" align="center" bgcolor="#f4f7fb" style="padding:30px 15px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%; background-color:#ffffff; border-radius:18px; box-shadow:0 8px 25px rgba(0,0,0,0.05);">
                    <tr>
                        <td style="padding:35px 30px;">
                            <!-- LOGOS -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" style="padding-bottom:20px;">
                                        <table cellpadding="0" cellspacing="0" border="0" align="center">
                                            <tr>
                                                <td style="padding-right:12px;">
                                                    <img src="{$logoIcbf}" alt="ICBF" width="70" style="display:block;">
                                                </td>
                                                <td style="padding-left:12px;">
                                                    <img src="{$logoIq}" alt="IQ Outsourcing" width="110" style="display:block;">
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <h2 style="color:#4CAF50; text-align:center; margin:0 0 10px;">🔐 Recuperación de contraseña</h2>
                            
                            <p style="margin:0 0 18px; font-size:16px; color:#2c3e50; text-align:center;">
                                Hola <strong style="color:#4CAF50;">{$safeName}</strong>,
                            </p>

                            <p style="color:#334155; font-size:15px; line-height:1.5;">
                                Recibimos una solicitud para restablecer la contraseña de tu cuenta en el Sistema de Gestión de Correo ICBF.
                            </p>

                            <div style="background-color:#f8fafc; border-radius:14px; border:1px solid #e2e8f0; padding:25px; margin:20px 0; text-align:center;">
                                <p style="margin:0 0 15px; font-size:15px; color:#475569;">
                                    Haz clic en el siguiente botón para establecer una nueva contraseña:
                                </p>

                                <a href="{$safeUrl}"
                                   style="display:inline-block; background-color:#4CAF50; color:#ffffff; font-size:16px; font-weight:600; text-decoration:none; padding:14px 32px; border-radius:40px; box-shadow:0 4px 12px rgba(76,175,80,0.30);">
                                    ✅ RESTABLECER CONTRASEÑA
                                </a>
                            </div>

                            <div style="background-color:#fff7ed; border:1px solid #fed7aa; border-radius:12px; padding:15px; margin:20px 0; color:#7c2d12; font-size:14px;">
                                <strong>⚠️ Importante:</strong>
                                <ul style="margin:8px 0 0; padding-left:20px;">
                                    <li>Este enlace expirará en <strong>30 minutos</strong></li>
                                    <li>Si no solicitaste este cambio, puedes ignorar este correo</li>
                                    <li>Nunca compartas este enlace con nadie</li>
                                </ul>
                            </div>

                            <p style="margin:16px 0 0; color:#334155;">Saludos,<br><strong>{$safeFrom}</strong></p>

                            <hr style="border:1px solid #e2e8f0; margin:30px 0 20px;">

                            <p style="font-size:12px; color:#64748b; text-align:center;">
                                © {$year} ICBF · IQ Outsourcing · Sistema de Gestión de Correo<br>
                                <span style="color:#94a3b8;">Este es un mensaje automático, por favor no respondas.</span>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    /**
     * ✅ Construir HTML de confirmación de cambio
     */
    private function buildPasswordChangedEmailHtml(string $toName, string $loginUrl, string $fromName): string
    {
        $logoIcbf = \App\Config\mail_asset_url('/assets/img/logo_icbf.png');
        $logoIq = \App\Config\mail_asset_url('/assets/img/logo_iq.png');
        $year = date('Y');

        $safeName = htmlspecialchars($toName ?: 'Usuario', ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
        $safeFrom = htmlspecialchars($fromName, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Contraseña actualizada</title>
</head>
<body style="margin:0; padding:0; font-family:'Segoe UI', Arial, sans-serif; background-color:#f4f7fb;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" align="center" bgcolor="#f4f7fb" style="padding:30px 15px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; background-color:#ffffff; border-radius:18px;">
                    <tr>
                        <td style="padding:35px 30px;">
                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" style="padding-bottom:20px;">
                                        <table cellpadding="0" cellspacing="0" border="0" align="center">
                                            <tr>
                                                <td style="padding-right:12px;">
                                                    <img src="{$logoIcbf}" alt="ICBF" width="70" style="display:block;">
                                                </td>
                                                <td style="padding-left:12px;">
                                                    <img src="{$logoIq}" alt="IQ Outsourcing" width="110" style="display:block;">
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <h2 style="color:#4CAF50; text-align:center; margin:0 0 10px;">✅ Contraseña actualizada</h2>
                            
                            <p style="margin:0 0 18px; font-size:16px; color:#2c3e50; text-align:center;">
                                Hola <strong style="color:#4CAF50;">{$safeName}</strong>,
                            </p>

                            <p style="color:#334155; font-size:15px; line-height:1.5;">
                                Tu contraseña ha sido actualizada exitosamente.
                            </p>

                            <div style="background-color:#f8fafc; border-radius:14px; padding:25px; margin:20px 0; text-align:center;">
                                <p style="margin:0 0 15px;">Puedes iniciar sesión con tu nueva contraseña:</p>
                                <a href="{$safeUrl}"
                                   style="display:inline-block; background-color:#4CAF50; color:white; padding:12px 30px; border-radius:40px; text-decoration:none;">
                                    INICIAR SESIÓN
                                </a>
                            </div>

                            <div style="background-color:#fff7ed; border-radius:12px; padding:15px; color:#7c2d12;">
                                <strong>🔒 Nota de seguridad:</strong> Si no realizaste este cambio, contacta inmediatamente al administrador.
                            </div>

                            <p style="margin:20px 0 0;">Saludos,<br><strong>{$safeFrom}</strong></p>

                            <hr style="margin:30px 0 20px;">

                            <p style="font-size:12px; color:#64748b; text-align:center;">
                                © {$year} ICBF · IQ Outsourcing
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    private function isStrongPassword(string $password): bool
    {
        return (bool)preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/', $password);
    }

    private function render(string $view, array $params = []): void
    {
        extract($params, EXTR_SKIP);
        $viewPath = dirname(__DIR__) . '/views/' . $view;
        include dirname(__DIR__) . '/views/layout.php';
    }
}