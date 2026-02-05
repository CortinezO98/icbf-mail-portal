<?php
// File: portal/app/controllers/ReportsController.php
declare(strict_types=1);

namespace App\Controllers;

use PDO;

use App\Auth\Auth;
use App\Repos\ReportsRepo;
use App\Services\ReportExportService;

use function App\Config\url;

final class ReportsController
{
    private ReportsRepo $repo;
    private ReportExportService $exporter;

    public function __construct(private PDO $pdo, private array $config)
    {
        $this->repo = new ReportsRepo($pdo);
        $this->exporter = new ReportExportService();
    }

    public function dashboard(): void
    {
        $end = $this->safeDate($_GET['end'] ?? date('Y-m-d')) ?? date('Y-m-d');
        $start = $this->safeDate($_GET['start'] ?? date('Y-m-d', strtotime('-6 days'))) ?? date('Y-m-d', strtotime('-6 days'));
        $mailboxId = isset($_GET['mailbox_id']) && $_GET['mailbox_id'] !== '' ? (int)$_GET['mailbox_id'] : null;

        $data = $this->repo->dashboard($start, $end, $mailboxId);
        $agents = $this->repo->agentsMetrics($start, $end);

        // Exportaciones recientes (paginadas)
        $exports = [];
        if (method_exists($this->repo, 'recentExports')) {
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $exports = $this->repo->recentExports($page, 20);
            if (!is_array($exports)) $exports = [];
        }

        $this->render('reports/dashboard.php', [
            'start' => $start,
            'end' => $end,
            'mailbox_id' => $mailboxId,
            'kpis' => $data['kpis'] ?? [],
            'daily' => $data['daily'] ?? [],
            'missing_attachments' => $data['missing_attachments'] ?? 0,
            'agents' => $agents ?? [],
            'exports' => $exports,
            'config' => $this->config,
        ]);
    }

    public function export(): void
    {
        // GET /reports/export?type=sla&start=YYYY-MM-DD&end=YYYY-MM-DD&format=csv|xlsx&mailbox_id=#
        $type = strtolower(trim((string)($_GET['type'] ?? 'sla')));
        $format = strtolower(trim((string)($_GET['format'] ?? 'xlsx')));

        $end = $this->safeDate($_GET['end'] ?? date('Y-m-d')) ?? date('Y-m-d');
        $start = $this->safeDate($_GET['start'] ?? date('Y-m-d', strtotime('-6 days'))) ?? date('Y-m-d', strtotime('-6 days'));
        $mailboxId = isset($_GET['mailbox_id']) && $_GET['mailbox_id'] !== '' ? (int)$_GET['mailbox_id'] : null;

        if (!in_array($type, ['sla'], true)) {
            http_response_code(400);
            echo "Tipo de export no soportado";
            exit;
        }

        $rows = $this->repo->exportSlaDataset($start, $end, $mailboxId);

        // Guardar el archivo en portal/storage/reports y registrar en generated_reports
        $reportsDir = dirname(__DIR__, 2) . '/storage/reports';
        if (!is_dir($reportsDir)) {
            mkdir($reportsDir, 0777, true);
        }

        $baseName = "reporte_{$type}_{$start}_{$end}";
        if ($mailboxId) {
            $baseName .= "_mb{$mailboxId}";
        }

        $userId = (int)(Auth::user()['id'] ?? 0);

        if ($format === 'xlsx') {
            $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
            }

            if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
                $path = $reportsDir . '/' . $baseName . '_' . date('Ymd_His') . '.xlsx';
                $this->saveXlsx($path, $rows);

                $params = [
                    'type' => $type,
                    'start' => $start,
                    'end' => $end,
                    'mailbox_id' => $mailboxId,
                    'format' => 'xlsx',
                ];

                // ✅ Insert con status/finished_at/row_count
                $this->repo->insertGeneratedReport(
                    $userId,
                    'excel_' . $type,
                    $path,
                    $params,
                    $start,
                    $end,
                    'READY',
                    null,
                    is_array($rows) ? count($rows) : null
                );

                header('Location: ' . url('/reports/download?id=' . $this->lastInsertIdSafe()));
                exit;
            }

            // fallback
            $format = 'csv';
        }

        // CSV guardado
        $path = $reportsDir . '/' . $baseName . '_' . date('Ymd_His') . '.csv';
        $this->saveCsv($path, $rows);

        $params = [
            'type' => $type,
            'start' => $start,
            'end' => $end,
            'mailbox_id' => $mailboxId,
            'format' => 'csv',
        ];

        $this->repo->insertGeneratedReport(
            $userId,
            'csv_' . $type,
            $path,
            $params,
            $start,
            $end,
            'READY',
            null,
            is_array($rows) ? count($rows) : null
        );

        header('Location: ' . url('/reports/download?id=' . $this->lastInsertIdSafe()));
        exit;
    }

    /**
     * ✅ Descarga segura + valida status (PRO)
     * - bloquea si no está READY
     * - autoriza dueño o admin
     */
    public function download(): void
    {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo "ID inválido";
            exit;
        }

        $r = $this->repo->getReportById($id);
        if (!$r) {
            http_response_code(404);
            echo "Reporte no encontrado";
            exit;
        }

        $user = Auth::user() ?? [];
        $userId = (int)($user['id'] ?? 0);

        // ✅ Autorización: dueño o admin
        $ownerId = (int)($r['generated_by'] ?? ($r['created_by'] ?? 0));
        $roleCode = strtoupper((string)($user['role_code'] ?? $user['role'] ?? ''));
        $isAdmin = in_array($roleCode, ['ADMIN', 'SUPERADMIN', 'ADMINISTRADOR'], true);

        if ($ownerId > 0 && !$isAdmin && $userId > 0 && $ownerId !== $userId) {
            http_response_code(403);
            echo "No autorizado";
            exit;
        }

        // ✅ Estado del reporte
        $status = strtoupper((string)($r['status'] ?? 'PENDING'));
        if ($status !== 'READY') {
            http_response_code(409);
            echo $status === 'FAILED'
                ? ("Reporte falló: " . (string)($r['error_message'] ?? 'Error no especificado'))
                : "Reporte aún no está listo";
            exit;
        }

        $filePathRaw = (string)($r['file_path'] ?? '');
        if ($filePathRaw === '') {
            http_response_code(404);
            echo "Archivo no registrado";
            exit;
        }

        $fullPath = $this->resolveReportPathSafe($filePathRaw);
        if ($fullPath === null || !is_file($fullPath)) {
            http_response_code(404);
            echo "Archivo no encontrado en disco";
            exit;
        }

        // contador solo si todo está OK
        $this->repo->incrementDownloadCount($id);

        $filename = basename($fullPath);
        $lower = strtolower($filename);

        $contentType = str_ends_with($lower, '.xlsx')
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv; charset=utf-8';

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . (string)filesize($fullPath));
        header('X-Content-Type-Options: nosniff');

        readfile($fullPath);
        exit;
    }

    private function resolveReportPathSafe(string $storedPath): ?string
    {
        $storedPath = trim($storedPath);
        if ($storedPath === '') return null;

        $storageBase = realpath(dirname(__DIR__, 2) . '/storage');
        if ($storageBase === false) return null;

        $reportsBase = realpath($storageBase . DIRECTORY_SEPARATOR . 'reports');
        if ($reportsBase === false) {
            @mkdir($storageBase . DIRECTORY_SEPARATOR . 'reports', 0777, true);
            $reportsBase = realpath($storageBase . DIRECTORY_SEPARATOR . 'reports');
        }
        if ($reportsBase === false) return null;

        // Path absoluto (compat)
        if ($this->isAbsolutePath($storedPath)) {
            $real = realpath($storedPath);
            if ($real === false) return null;

            $enforceInsideReports = (bool)($this->config['reports_enforce_inside_storage'] ?? false);
            if ($enforceInsideReports && !str_starts_with($real, $reportsBase)) {
                return null;
            }
            return $real;
        }

        // Path relativo => restringir a storage/reports
        $storedPath = str_replace(['\\', '//'], ['/', '/'], $storedPath);
        $storedPath = ltrim($storedPath, '/');

        if (!str_starts_with($storedPath, 'reports/')) {
            $storedPath = 'reports/' . $storedPath;
        }

        $candidate = $storageBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath);
        $real = realpath($candidate);
        if ($real === false) return null;

        if (!str_starts_with($real, $reportsBase)) return null;

        return $real;
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/')) return true;
        return (bool)preg_match('/^[A-Za-z]:\\\\/', $path);
    }

    private function safeDate(string $value): ?string
    {
        $value = trim($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
        $ts = strtotime($value);
        if ($ts === false) return null;
        return date('Y-m-d', $ts);
    }

    private function render(string $view, array $params = []): void
    {
        extract($params, EXTR_SKIP);
        $viewPath = dirname(__DIR__) . '/views/' . $view;
        include dirname(__DIR__) . '/views/layout.php';
    }

    private function saveCsv(string $path, array $rows): void
    {
        $f = fopen($path, 'wb');
        if (!$f) throw new \RuntimeException("No se pudo crear archivo: {$path}");

        if (empty($rows)) {
            fputcsv($f, ['sin_datos']);
            fclose($f);
            return;
        }

        fputcsv($f, array_keys($rows[0]));
        foreach ($rows as $r) {
            foreach ($r as $k => $v) {
                if (is_array($v) || is_object($v)) {
                    $r[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
                }
            }
            fputcsv($f, array_values($r));
        }
        fclose($f);
    }

    private function saveXlsx(string $path, array $rows): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('SLA');

        if (empty($rows)) {
            $sheet->setCellValue('A1', 'sin_datos');
        } else {
            $headers = array_keys($rows[0]);

            $col = 1;
            foreach ($headers as $h) {
                $sheet->setCellValueByColumnAndRow($col, 1, (string)$h);
                $col++;
            }

            $rowIdx = 2;
            foreach ($rows as $r) {
                $col = 1;
                foreach ($headers as $h) {
                    $v = $r[$h] ?? '';
                    if (is_array($v) || is_object($v)) {
                        $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                    }
                    $sheet->setCellValueByColumnAndRow($col, $rowIdx, (string)$v);
                    $col++;
                }
                $rowIdx++;
            }
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($path);
    }

    private function lastInsertIdSafe(): int
    {
        $id = (int)$this->pdo->lastInsertId();
        return $id > 0 ? $id : 0;
    }
}
