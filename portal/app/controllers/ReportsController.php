<?php
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

        $this->render('reports/dashboard.php', [
            'start' => $start,
            'end' => $end,
            'mailbox_id' => $mailboxId,
            'kpis' => $data['kpis'],
            'daily' => $data['daily'],
            'missing_attachments' => $data['missing_attachments'],
            'agents' => $agents,
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
        if (!is_dir($reportsDir)) mkdir($reportsDir, 0777, true);

        $baseName = "reporte_{$type}_{$start}_{$end}";
        if ($mailboxId) $baseName .= "_mb{$mailboxId}";

        // Intentar XLSX, si no hay lib -> CSV
        $fileId = null;

        if ($format === 'xlsx') {
            // Si hay PhpSpreadsheet, lo servimos directo. Pero también lo guardamos en disco.
            $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
            }
            if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
                $path = $reportsDir . '/' . $baseName . '_' . date('Ymd_His') . '.xlsx';
                $this->saveXlsx($path, $rows);

                $userId = (int)(Auth::user()['id'] ?? 0);
                $params = ['type'=>$type,'start'=>$start,'end'=>$end,'mailbox_id'=>$mailboxId,'format'=>'xlsx'];
                $this->repo->insertGeneratedReport($userId, 'excel_'.$type, $path, $params, $start, $end);

                header('Location: ' . url('/reports/download?id=' . $this->lastInsertIdSafe()));
                exit;
            }
            // fallback
            $format = 'csv';
        }

        // CSV guardado
        $path = $reportsDir . '/' . $baseName . '_' . date('Ymd_His') . '.csv';
        $this->saveCsv($path, $rows);

        $userId = (int)(Auth::user()['id'] ?? 0);
        $params = ['type'=>$type,'start'=>$start,'end'=>$end,'mailbox_id'=>$mailboxId,'format'=>'csv'];
        $this->repo->insertGeneratedReport($userId, 'csv_'.$type, $path, $params, $start, $end);

        header('Location: ' . url('/reports/download?id=' . $this->lastInsertIdSafe()));
        exit;
    }

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

        $this->repo->incrementDownloadCount($id);

        $filePath = (string)($r['file_path'] ?? '');
        if ($filePath === '' || !file_exists($filePath)) {
            http_response_code(404);
            echo "Archivo no encontrado en disco";
            exit;
        }

        $filename = basename($filePath);
        $contentType = str_ends_with($filename, '.xlsx')
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv';

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . (string)filesize($filePath));
        header('X-Content-Type-Options: nosniff');

        readfile($filePath);
        exit;
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
        // InsertGeneratedReport usa el mismo PDO, por eso podemos leer el lastInsertId
        $id = (int)$this->pdo->lastInsertId();
        return $id > 0 ? $id : 0;
    }
}
