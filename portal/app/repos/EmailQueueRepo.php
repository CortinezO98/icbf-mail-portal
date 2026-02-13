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
        string $username,
        string $tempPassword,
        string $loginUrl,
        string $fromName
    ): string {
        $eUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $ePass = htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8');
        $eUrl  = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
        $eFrom = htmlspecialchars($fromName, ENT_QUOTES, 'UTF-8');

        return "<!doctype html>
<html>
<head>
  <meta charset='utf-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1.0'>
  <title>Bienvenido</title>
</head>
<body style='font-family: Arial, sans-serif; color:#111; line-height:1.4;'>
  <h2 style='margin:0 0 10px;'>Bienvenido al Sistema de Gestión de Correo ICBF</h2>
  <p style='margin:0 0 12px;'>Tu cuenta ha sido creada exitosamente.</p>

  <div style='background:#f8f9fa; padding:14px; border-radius:6px; border:1px solid #e9ecef; margin:14px 0;'>
    <div style='margin-bottom:6px;'><strong>Usuario:</strong> {$eUser}</div>
    <div style='margin-bottom:6px;'><strong>Contraseña temporal:</strong> {$ePass}</div>
    <div><strong>Acceso:</strong> <a href='{$eUrl}'>{$eUrl}</a></div>
  </div>

  <p style='margin:0 0 12px;'><em>Por seguridad, cambia tu contraseña en tu primer acceso.</em></p>
  <p style='margin:0;'>Saludos,<br>{$eFrom}</p>
</body>
</html>";
    }
}
