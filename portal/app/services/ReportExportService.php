<?php
declare(strict_types=1);

namespace App\Services;

final class ReportExportService
{
    public function streamCsv(string $filename, array $rows): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        if (!$out) {
            http_response_code(500);
            echo "No se pudo abrir el stream de salida.";
            exit;
        }

        if (empty($rows)) {
            fputcsv($out, ['sin_datos']);
            fclose($out);
            exit;
        }

        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $r) {
            foreach ($r as $k => $v) {
                if (is_array($v) || is_object($v)) {
                    $r[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
                }
            }
            fputcsv($out, array_values($r));
        }

        fclose($out);
        exit;
    }

    /**
     * XLSX real si existe vendor/autoload.php y PhpSpreadsheet.
     * Si no existe, el controller debe hacer fallback a CSV.
     */
    public function streamXlsxIfAvailable(string $filename, array $rows): bool
    {
        // portal/app/services -> portal/vendor/autoload.php
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!file_exists($autoload)) return false;

        require_once $autoload;
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) return false;

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Reporte');

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

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('X-Content-Type-Options: nosniff');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}
