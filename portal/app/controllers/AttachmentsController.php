<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Auth\Auth;
use App\Repos\CasesRepo;
use App\Repos\AttachmentsRepo;

final class AttachmentsController
{
    private AttachmentsRepo $attachmentsRepo;
    private CasesRepo $casesRepo;

    public function __construct(private PDO $pdo, private array $config)
    {
        $this->attachmentsRepo = new AttachmentsRepo($pdo);
        $this->casesRepo = new CasesRepo($pdo);
    }

    public function download(int $attachmentId): void
    {
        $att = $this->attachmentsRepo->findWithCase($attachmentId);
        if (!$att) {
            http_response_code(404);
            echo "Attachment not found";
            exit;
        }

        $caseId = (int)$att['case_id'];
        $case = $this->casesRepo->findCase($caseId);
        if (!$case) {
            http_response_code(404);
            echo "Case not found";
            exit;
        }

        if (Auth::hasRole('AGENTE') && !Auth::hasRole('SUPERVISOR') && !Auth::hasRole('ADMIN')) {
            if ((int)($case['assigned_user_id'] ?? 0) !== (int)Auth::id()) {
                http_response_code(403);
                echo "Forbidden";
                exit;
            }
        }

        $storagePath = (string)($att['storage_path'] ?? '');
        if ($storagePath === '') {
            http_response_code(500);
            echo "Attachment storage_path missing";
            exit;
        }

        $filePath = $this->resolveStoragePath($storagePath);
        if ($filePath === '' || !is_file($filePath)) {
            http_response_code(404);
            echo "File not found on disk";
            exit;
        }

        $filename = (string)($att['filename'] ?? 'attachment.bin');
        $contentType = (string)($att['content_type'] ?? 'application/octet-stream');

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . (string)filesize($filePath));
        header('X-Content-Type-Options: nosniff');

        readfile($filePath);
        exit;
    }

    private function resolveStoragePath(string $storagePath): string
    {
        $base = (string)($this->config['attachments_dir'] ?? '');
        if ($base === '') {
            return '';
        }
        $p = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storagePath);
        $candidate = $base . DIRECTORY_SEPARATOR . $p;

        if (is_file($candidate)) {
            return realpath($candidate) ?: $candidate;
        }

        $filename = basename($storagePath);
        $parts = explode('_', $filename, 2);
        if (empty($parts[0]) || strlen($parts[0]) < 4) {
            return '';
        }

        $hash = $parts[0];
        $dir1 = substr($hash, 0, 2);
        $dir2 = substr($hash, 2, 2);

        $reconstructed = $base
            . DIRECTORY_SEPARATOR . $dir1
            . DIRECTORY_SEPARATOR . $dir2
            . DIRECTORY_SEPARATOR . $filename;

        if (is_file($reconstructed)) {
            return realpath($reconstructed) ?: $reconstructed;
        }

        return '';
    }
}
