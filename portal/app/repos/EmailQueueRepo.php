<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class EmailQueueRepo
{
    public function __construct(private PDO $pdo) {}

    public function enqueue(
        string $kind,
        string $toEmail,
        ?string $toName,
        string $subject,
        string $bodyHtml,
        int $priority = 5
    ): int {
        $kind    = strtoupper(trim($kind));
        $toEmail = trim($toEmail);
        $toName  = $toName !== null ? trim($toName) : null;
        $subject = trim($subject);
        if ($priority < 1) $priority = 1;
        if ($priority > 10) $priority = 10;

        $sql = "
            INSERT INTO email_queue
              (kind, to_email, to_name, subject, body_html, status, priority, attempts, max_attempts, next_attempt_at, created_at, updated_at)
            VALUES
              (:kind, :to_email, :to_name, :subject, :body_html, 'PENDING', :priority, 0, 8, NOW(6), NOW(6), NOW(6))
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':kind'      => $kind,
            ':to_email'  => $toEmail,
            ':to_name'   => ($toName !== null && $toName !== '') ? $toName : null,
            ':subject'   => $subject,
            ':body_html' => $bodyHtml,
            ':priority'  => $priority,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function enqueueWelcomeEmail(
        string $toEmail,
        ?string $toName,
        string $username,
        string $tempPassword,
        string $loginUrl,
        string $fromEmail,
        string $fromName,
        int $priority = 5
    ): int {
        $subject = 'Bienvenido al Sistema ICBF Mail';

        $bodyHtml = $this->buildWelcomeHtml(
            $toName,        
            $username,
            $tempPassword,
            $loginUrl,
            $fromName
        );

        return $this->enqueue(
            'WELCOME',
            $toEmail,
            $toName,
            $subject,
            $bodyHtml,
            $priority
        );
    }

    private function buildWelcomeHtml(
        ?string $toName,
        string $username,
        string $tempPassword,
        string $loginUrl,
        string $fromName
    ): string {
        // Escapes
        $safeName = trim((string)($toName ?? ''));
        $display  = $safeName !== '' ? $safeName : $username;

        $eName = htmlspecialchars($display, ENT_QUOTES, 'UTF-8');
        $eUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $ePass = htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8');
        $eUrl  = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
        $eFrom = htmlspecialchars($fromName, ENT_QUOTES, 'UTF-8');


        $logoIcbf = htmlspecialchars(\App\Config\mail_asset_url('/assets/img/logo_icbf.png'), ENT_QUOTES, 'UTF-8');
        $logoIq   = htmlspecialchars(\App\Config\mail_asset_url('/assets/img/logo_iq.png'), ENT_QUOTES, 'UTF-8');


        $year = (int)date('Y');

        // Preheader (texto oculto que aparece como preview en Gmail)
        $preheader = "Tu cuenta fue creada. Accede con tus credenciales temporales y cambia la contraseña al ingresar.";

        return "<!DOCTYPE html>
<html lang='es'>
<head>
  <meta charset='UTF-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1.0'>
  <meta name='x-apple-disable-message-reformatting'>
  <title>Bienvenido al Sistema ICBF</title>
</head>

<body style='margin:0; padding:0; font-family:\"Segoe UI\", Arial, Helvetica, sans-serif; background-color:#f4f7fb; line-height:1.5;'>

  <!-- Preheader (hidden) -->
  <div style='display:none; font-size:1px; color:#f4f7fb; line-height:1px; max-height:0px; max-width:0px; opacity:0; overflow:hidden;'>
    " . htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8') . "
  </div>

  <table width='100%' cellpadding='0' cellspacing='0' border='0' align='center' bgcolor='#f4f7fb' style='background-color:#f4f7fb; padding:30px 15px;'>
    <tr>
      <td align='center'>

        <!-- Contenedor principal -->
        <table width='600' cellpadding='0' cellspacing='0' border='0' align='center' style='max-width:600px; width:100%; background-color:#ffffff; border-radius:18px; box-shadow:0 8px 25px rgba(0,0,0,0.05); border:1px solid #e9ecef;'>
          <tr>
            <td style='padding:35px 30px 25px 30px;'>

              <!-- HEADER con logos -->
              <table width='100%' cellpadding='0' cellspacing='0' border='0'>
                <tr>
                  <td align='center' style='padding-bottom:20px;'>
                    <table cellpadding='0' cellspacing='0' border='0' align='center'>
                      <tr>
                        <td style='padding-right:12px;'>
                          <img src='{$logoIcbf}' alt='ICBF' width='70' height='auto'
                               style='display:block; max-height:70px; width:auto; border:0; outline:none; text-decoration:none;'>
                        </td>
                        <td style='padding-left:12px;'>
                          <img src='{$logoIq}' alt='IQ Outsourcing' width='110' height='auto'
                               style='display:block; max-height:60px; width:auto; border:0; outline:none; text-decoration:none;'>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>

              <!-- Título -->
              <h2 style='margin:0 0 10px 0; font-size:24px; font-weight:700; color:#4CAF50; text-align:center; letter-spacing:-0.2px;'>
                ✅ Cuenta creada exitosamente
              </h2>

              <!-- Saludo -->
              <p style='margin:0 0 18px 0; font-size:16px; color:#2c3e50; text-align:center;'>
                Hola <strong style='color:#4CAF50;'>{$eName}</strong>, bienvenido al Sistema de Gestión de Correspondencia.
              </p>

              <!-- Card credenciales -->
              <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color:#f8fafc; border-radius:14px; border:1px solid #e2e8f0; margin:20px 0 18px 0;'>
                <tr>
                  <td style='padding:22px 20px;'>
                    <table width='100%' cellpadding='0' cellspacing='0' border='0'>
                      <tr>
                        <td width='40' valign='top' style='padding-right:10px;'>
                          <span style='display:inline-block; width:36px; height:36px; background-color:rgba(76,175,80,0.12); border-radius:50%; text-align:center; line-height:36px; color:#4CAF50; font-size:20px;'>
                            🔐
                          </span>
                        </td>
                        <td>
                          <table width='100%' cellpadding='5' cellspacing='0' border='0'>
                            <tr>
                              <td width='150' style='font-size:14px; color:#475569; font-weight:600;'>Usuario:</td>
                              <td style='font-size:15px; font-weight:600; color:#0f172a; font-family:Consolas, Monaco, \"Courier New\", monospace;'>
                                {$eUser}
                              </td>
                            </tr>
                            <tr>
                              <td style='font-size:14px; color:#475569; font-weight:600;'>Contraseña temporal:</td>
                              <td>
                                <span style='font-size:15px; font-weight:700; color:#b45309; background-color:#fffbeb; padding:6px 12px; border-radius:8px; display:inline-block; font-family:Consolas, Monaco, \"Courier New\", monospace;'>
                                  {$ePass}
                                </span>
                              </td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                    </table>

                    <div style='margin-top:14px; font-size:12.5px; color:#64748b;'>
                      <strong>Acceso:</strong>
                      <a href='{$eUrl}' style='color:#2563eb; text-decoration:underline; word-break:break-all;'>{$eUrl}</a>
                    </div>

                  </td>
                </tr>
              </table>

              <!-- Botón -->
              <table width='100%' cellpadding='0' cellspacing='0' border='0'>
                <tr>
                  <td align='center' style='padding:10px 0 16px 0;'>
                    <a href='{$eUrl}'
                       style='display:inline-block; background-color:#4CAF50; color:#ffffff; font-size:16px; font-weight:600; text-decoration:none; padding:14px 32px; border-radius:40px; box-shadow:0 4px 12px rgba(76,175,80,0.30); border:1px solid #3f9c44;'>
                      👉 INGRESAR AL SISTEMA
                    </a>
                  </td>
                </tr>
              </table>

              <!-- Fallback del botón -->
              <div style='text-align:center; font-size:12px; color:#64748b; margin:0 0 18px 0;'>
                Si el botón no funciona, copia y pega este enlace en tu navegador:
                <br>
                <a href='{$eUrl}' style='color:#2563eb; text-decoration:underline; word-break:break-all;'>{$eUrl}</a>
              </div>

              <!-- Primeros pasos -->
              <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color:#f1f5f9; border-radius:12px; margin:15px 0 8px 0;'>
                <tr>
                  <td style='padding:18px 20px;'>
                    <p style='margin:0 0 8px 0; font-size:14px; font-weight:700; color:#1e293b;'>📌 Primeros pasos:</p>
                    <ul style='margin:0; padding-left:20px; color:#334155; font-size:13.5px;'>
                      <li style='margin-bottom:6px;'>Verifica que tus datos personales sean correctos.</li>
                      <li style='margin-bottom:0;'>Si presentas inconvenientes, contacta a tu supervisor o al administrador del sistema.</li>
                    </ul>
                  </td>
                </tr>
              </table>

              <!-- Nota de seguridad -->
              <div style='margin-top:14px; padding:12px 12px; background:#fff7ed; border:1px solid #fed7aa; border-radius:12px; color:#7c2d12; font-size:12.5px; line-height:1.55;'>
                <strong>Recomendación de seguridad:</strong> No compartas tus credenciales. Este correo contiene información sensible.
              </div>

              <!-- Firma -->
              <p style='margin:16px 0 0 0; font-size:13.5px; color:#334155;'>
                Saludos,<br>
                <strong>{$eFrom}</strong>
              </p>

              <!-- Footer -->
              <table width='100%' cellpadding='0' cellspacing='0' border='0' style='border-top:1px solid #e2e8f0; margin-top:30px; padding-top:20px;'>
                <tr>
                  <td align='center' style='font-size:12px; color:#64748b;'>
                    © {$year} ICBF · IQ Outsourcing · Sistema de Gestión de Correo
                    <br>
                    <span style='color:#94a3b8;'>Este es un mensaje automático, por favor no respondas este correo.</span>
                  </td>
                </tr>
              </table>

            </td>
          </tr>
        </table>
        <!-- Fin contenedor -->

      </td>
    </tr>
  </table>

</body>
</html>";
    }
}
