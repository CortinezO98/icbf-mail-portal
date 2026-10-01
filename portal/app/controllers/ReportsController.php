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

    /**
     * ✅ POST /reports/generate
     * - Render HTML (results.php) o exportar (CSV/EXCEL)
     * - SIN romper export/download existentes
     */
    public function generate(): void
    {
        // CSRF
        $csrf = (string)($_POST['_csrf'] ?? '');
        if (!\App\Auth\Csrf::validate($csrf)) {
            http_response_code(419);
            echo "CSRF inválido";
            exit;
        }

        $start = $this->safeDate($_POST['start_date'] ?? '') ?? date('Y-m-d', strtotime('-7 days'));
        $end   = $this->safeDate($_POST['end_date'] ?? '') ?? date('Y-m-d');

        // ✅ Tipo de reporte: 'sla' (comportamiento original) o los 3 tipos
        // de agentes agregados. results.php tiene columnas fijas de
        // casos/SLA (Asunto, Remitente, Vence...) - mostrar ahí un dataset
        // de agentes se vería roto, así que los tipos de agente SIEMPRE se
        // exportan (csv/excel), nunca se renderizan en la vista HTML
        // compartida. Se fuerza csv como defensa en el backend por si
        // llega format=html para un tipo de agente (la UI ya no debería
        // permitirlo, ver reports/index.php).
        $type = strtolower(trim((string)($_POST['type'] ?? 'sla')));
        $allowedTypes = ['sla', 'agent_presence_history', 'agent_presence_summary', 'agent_presence_live'];
        if (!in_array($type, $allowedTypes, true)) $type = 'sla';

        // filtros (compatibles con tu UI)
        $status   = strtoupper(trim((string)($_POST['status'] ?? '')));   // NUEVO/ASIGNADO/EN_PROCESO/RESPONDIDO/CERRADO
        $agentId  = trim((string)($_POST['agent_id'] ?? ''));
        $semaforo = strtoupper(trim((string)($_POST['semaforo'] ?? ''))); // VERDE/AMARILLO/ROJO/RESPONDIDO
        $format   = strtolower(trim((string)($_POST['format'] ?? 'html')));

        if ($agentId !== '' && !ctype_digit($agentId)) $agentId = '';
        $agentIdInt = $agentId !== '' ? (int)$agentId : null;

        if (!in_array($format, ['html', 'csv', 'excel'], true)) $format = 'html';
        if ($type !== 'sla' && $format === 'html') $format = 'csv';

        // Export si aplica (reusa export() para auditoría y storage)
        if ($format === 'csv' || $format === 'excel') {
            $_GET['type'] = $type;
            $_GET['format'] = ($format === 'excel') ? 'xlsx' : 'csv';
            $_GET['start'] = $start;
            $_GET['end'] = $end;
            if ($agentIdInt) $_GET['user_id'] = $agentIdInt;
            $this->export();
            return;
        }

        // Solo llega aquí type === 'sla' (por la regla de arriba) - mismo
        // comportamiento original, sin cambios.
        $rows = $this->repo->exportSlaDataset($start, $end, null);
        $rows = $this->filterRows($rows, $status, $agentIdInt, $semaforo);
        $summary = $this->buildSummary($rows);

        $this->render('reports/results.php', [
            'data' => $rows,
            'summary' => $summary,
            'params' => [
                'start_date' => $start,
                'end_date' => $end,
                'status' => $status,
                'agent_id' => $agentIdInt ? (string)$agentIdInt : '',
                'semaforo' => $semaforo,
                'format' => $format,
            ],
            'csrfToken' => \App\Auth\Csrf::token(),
            'config' => $this->config,
        ]);
    }

    public function export(): void
    {
        // GET /reports/export?type=sla&start=YYYY-MM-DD&end=YYYY-MM-DD&format=csv|xlsx&mailbox_id=#
        // Tipos de agente: type=agent_presence_history|agent_presence_summary|agent_presence_live
        //   + user_id=# opcional para filtrar por un solo agente
        $type = strtolower(trim((string)($_GET['type'] ?? 'sla')));
        $format = strtolower(trim((string)($_GET['format'] ?? 'xlsx')));

        $end = $this->safeDate($_GET['end'] ?? date('Y-m-d')) ?? date('Y-m-d');
        $start = $this->safeDate($_GET['start'] ?? date('Y-m-d', strtotime('-6 days'))) ?? date('Y-m-d', strtotime('-6 days'));
        $mailboxId = isset($_GET['mailbox_id']) && $_GET['mailbox_id'] !== '' ? (int)$_GET['mailbox_id'] : null;
        $userId2 = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;

        $allowedExportTypes = ['sla', 'agent_presence_history', 'agent_presence_summary', 'agent_presence_live'];
        if (!in_array($type, $allowedExportTypes, true)) {
            http_response_code(400);
            echo "Tipo de export no soportado";
            exit;
        }

        switch ($type) {
            case 'agent_presence_history':
                $rows = $this->repo->exportAgentPresenceHistoryDataset($start, $end, $userId2);
                break;
            case 'agent_presence_summary':
                $rows = $this->repo->exportAgentPresenceSummaryDataset($start, $end, $userId2);
                break;
            case 'agent_presence_live':
                // Snapshot en tiempo real - NO usa rango de fechas, 90s es
                // el mismo default que AGENT_PRESENCE_STALE_SECONDS del
                // worker (ver worker/app/settings.py); si ese valor cambia
                // ahí, actualizar también aquí para que el reporte siga
                // siendo consistente con el criterio real de asignación.
                $rows = $this->repo->exportAgentPresenceLiveDataset(90);
                break;
            default:
                $rows = $this->repo->exportSlaDataset($start, $end, $mailboxId);
                break;
        }

        // Guardar el archivo en portal/storage/reports y registrar en generated_reports
        $reportsDir = dirname(__DIR__, 2) . '/storage/reports';
        if (!is_dir($reportsDir)) {
            mkdir($reportsDir, 0777, true);
        }

        $baseName = ($type === 'agent_presence_live')
            ? "reporte_{$type}_" . date('Ymd_His')
            : "reporte_{$type}_{$start}_{$end}";
        if ($mailboxId) {
            $baseName .= "_mb{$mailboxId}";
        }
        if ($userId2) {
            $baseName .= "_u{$userId2}";
        }

        $userId = (int)(Auth::user()['id'] ?? 0);

        if ($format === 'xlsx') {

            // ✅ Evitar timeouts/memoria en export XLSX (solo aquí)
            @set_time_limit(0);
            if (function_exists('ini_set')) {
                @ini_set('memory_limit', (string)($this->config['reports_xlsx_memory_limit'] ?? '512M'));
            }

            // ✅ Autoload correcto (tu vendor está en /var/www/icbf-mail-portal/vendor)
            $autoload = realpath(dirname(__DIR__, 3) . '/vendor/autoload.php');
            if ($autoload && is_file($autoload)) {
                require_once $autoload;
            }

            // ✅ Validación fuerte: si NO está, no hacemos fallback silencioso
            if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
                http_response_code(500);
                echo "PhpSpreadsheet no disponible (autoload no cargado).";
                exit;
            }

            // ❌ IMPORTANTE: NO usar Settings::setCacheStorageMethod() en tu versión (PhpSpreadsheet 5.4.0)
            // (esa API no existe y rompe XLSX). Dejamos sin cache para no afectar funcionalidad.

            $path = $reportsDir . '/' . $baseName . '_' . date('Ymd_His') . '.xlsx';

            // ✅ Guardar XLSX (método compatible con tu versión)
            $this->saveXlsx($path, $rows, $type);

            $params = [
                'type' => $type,
                'start' => $start,
                'end' => $end,
                'mailbox_id' => $mailboxId,
                'format' => 'xlsx',
            ];

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

        // CSV guardado
        $path = $reportsDir . '/' . $baseName . '_' . date('Ymd_His') . '.csv';
        $this->saveCsv($path, $rows, $type);

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

        // dueño o admin
        $ownerId = (int)($r['generated_by'] ?? ($r['created_by'] ?? 0));
        $roleCode = strtoupper((string)($user['role_code'] ?? $user['role'] ?? ''));
        $isAdmin = in_array($roleCode, ['ADMIN', 'SUPERADMIN', 'ADMINISTRADOR'], true);

        if ($ownerId > 0 && !$isAdmin && $userId > 0 && $ownerId !== $userId) {
            http_response_code(403);
            echo "No autorizado";
            exit;
        }

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

        // absoluto
        if ($this->isAbsolutePath($storedPath)) {
            $real = realpath($storedPath);
            if ($real === false) return null;

            $enforceInsideReports = (bool)($this->config['reports_enforce_inside_storage'] ?? false);
            if ($enforceInsideReports && !str_starts_with($real, $reportsBase)) {
                return null;
            }
            return $real;
        }

        // relativo
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

    /* ==========================================================
       ✅ EXPORTS: headers en español sin romper el dataset interno
       ========================================================== */

    /**
     * Devuelve [keys, headersES]
     * - Si existe exportColumnOrder() en el repo: usa ese orden fijo.
     * - Si no existe: usa el orden natural del dataset.
     */
    /**
     * ✅ Mapea 'type' -> los métodos exportXxxHeaderMap()/exportXxxColumnOrder()
     * del repo. 'sla' conserva el comportamiento original (exportHeaderMap/
     * exportColumnOrder, sin sufijo) para no tocar nada de lo existente.
     */
    private function headerMapMethodsForType(string $type): array
    {
        return match ($type) {
            'agent_presence_history' => ['exportAgentPresenceHistoryHeaderMap', 'exportAgentPresenceHistoryColumnOrder'],
            'agent_presence_summary' => ['exportAgentPresenceSummaryHeaderMap', 'exportAgentPresenceSummaryColumnOrder'],
            'agent_presence_live'    => ['exportAgentPresenceLiveHeaderMap', 'exportAgentPresenceLiveColumnOrder'],
            default                  => ['exportHeaderMap', 'exportColumnOrder'],
        };
    }

    private function getHeaderMapAndKeys(array $rows, string $type = 'sla'): array
    {
        if (empty($rows)) {
            return [[], []];
        }

        [$headerMapMethod, $columnOrderMethod] = $this->headerMapMethodsForType($type);

        $headerMap = method_exists($this->repo, $headerMapMethod)
            ? (array)$this->repo->{$headerMapMethod}()
            : [];

        $rowKeys = array_keys($rows[0]);

        // ✅ Orden fijo si existe en el repo
        if (method_exists($this->repo, $columnOrderMethod)) {
            $ordered = (array)$this->repo->{$columnOrderMethod}();

            // solo columnas presentes en el dataset
            $keys = array_values(array_filter($ordered, static fn($k) => in_array($k, $rowKeys, true)));

            // agrega al final cualquier columna nueva que el dataset traiga y no esté en el orden fijo
            foreach ($rowKeys as $k) {
                if (!in_array($k, $keys, true)) $keys[] = $k;
            }
        } else {
            $keys = $rowKeys;
        }

        $headers = array_map(static fn($k) => $headerMap[$k] ?? $k, $keys);

        return [$keys, $headers];
    }

    private function saveCsv(string $path, array $rows, string $type = 'sla'): void
    {
        $f = fopen($path, 'wb');
        if (!$f) throw new \RuntimeException("No se pudo crear archivo: {$path}");

        if (empty($rows)) {
            fputcsv($f, ['sin_datos']);
            fclose($f);
            return;
        }

        [$keys, $headers] = $this->getHeaderMapAndKeys($rows, $type);

        // ✅ headers en español
        fputcsv($f, $headers);

        foreach ($rows as $r) {
            $line = [];
            foreach ($keys as $k) {
                $v = $r[$k] ?? '';
                if (is_array($v) || is_object($v)) {
                    $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                }
                $line[] = $v;
            }
            fputcsv($f, $line);
        }

        fclose($f);
    }

    private function sheetTitleForType(string $type): string
    {
        return match ($type) {
            'agent_presence_history' => 'Historico Agentes',
            'agent_presence_summary' => 'Resumen Agentes',
            'agent_presence_live'    => 'Agentes Tiempo Real',
            default                  => 'SLA',
        };
    }

    private function saveXlsx(string $path, array $rows, string $type = 'sla'): void
    {
        // ✅ Asegura que PhpSpreadsheet esté cargado (usa el vendor del project root)
        $autoload = realpath(dirname(__DIR__, 3) . '/vendor/autoload.php');
        if ($autoload && is_file($autoload)) {
            require_once $autoload;
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetTitleForType($type));

        if (empty($rows)) {
            $sheet->setCellValue('A1', 'sin_datos');
        } else {
            [$keys, $headers] = $this->getHeaderMapAndKeys($rows, $type);

            // Headers (API compatible con tu versión 5.4.0)
            $col = 1;
            foreach ($headers as $h) {
                $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . '1';
                $sheet->setCellValue($cell, (string)$h);
                $col++;
            }

            // Data (API compatible con tu versión 5.4.0)
            $rowIdx = 2;
            foreach ($rows as $r) {
                $col = 1;
                foreach ($keys as $k) {
                    $v = $r[$k] ?? '';
                    if (is_array($v) || is_object($v)) {
                        $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                    }
                    $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . (string)$rowIdx;
                    $sheet->setCellValue($cell, (string)$v);
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

    /* ===========================
       Helpers (no rompen)
       =========================== */

    private function filterRows(array $rows, string $status, ?int $agentId, string $semaforo): array
    {
        $status = $status !== '' ? strtoupper($status) : '';
        $semaforo = $semaforo !== '' ? strtoupper($semaforo) : '';

        return array_values(array_filter($rows, function ($r) use ($status, $agentId, $semaforo) {
            if (!is_array($r)) return false;

            if ($status !== '') {
                $code = strtoupper((string)($r['status_code'] ?? $r['status_name'] ?? ''));
                $codeNorm = str_replace(' ', '_', $code);
                if ($codeNorm !== $status) return false;
            }

            if ($agentId !== null) {
                $aid = (int)($r['assigned_user_id'] ?? 0);
                if ($aid !== $agentId) return false;
            }

            if ($semaforo !== '') {
                $sf = strtoupper((string)($r['current_sla_state'] ?? $r['semaforo'] ?? $r['sla_state'] ?? ''));
                if ($sf !== $semaforo) return false;
            }

            return true;
        }));
    }

    private function buildSummary(array $rows): array
    {
        $total = count($rows);
        $responded = 0;
        $pending = 0;

        $sumFirstRespHours = 0.0;
        $cntFirstResp = 0;

        $byStatus = [];
        $bySemaforo = [];

        foreach ($rows as $r) {
            if (!is_array($r)) continue;

            $isResponded = (int)($r['is_responded'] ?? 0) === 1;
            if ($isResponded) $responded++; else $pending++;

            if (isset($r['horas_hasta_1ra_respuesta']) && is_numeric($r['horas_hasta_1ra_respuesta'])) {
                $sumFirstRespHours += (float)$r['horas_hasta_1ra_respuesta'];
                $cntFirstResp++;
            } else {
                $recv = $r['received_at'] ?? null;
                $fr = $r['first_response_at'] ?? null;
                if ($recv && $fr) {
                    $ts1 = strtotime((string)$recv);
                    $ts2 = strtotime((string)$fr);
                    if ($ts1 !== false && $ts2 !== false && $ts2 >= $ts1) {
                        $sumFirstRespHours += (($ts2 - $ts1) / 3600);
                        $cntFirstResp++;
                    }
                }
            }

            $scode = (string)($r['status_code'] ?? '');
            $sname = (string)($r['status_name'] ?? $scode);
            if ($scode === '') $scode = $sname !== '' ? $sname : 'SIN_ESTADO';

            if (!isset($byStatus[$scode])) $byStatus[$scode] = ['name' => $sname, 'count' => 0];
            $byStatus[$scode]['count']++;

            $sf = strtoupper((string)($r['current_sla_state'] ?? $r['semaforo'] ?? $r['sla_state'] ?? ''));
            if ($sf === '') $sf = 'N/A';

            if (!isset($bySemaforo[$sf])) {
                $color = match ($sf) {
                    'VERDE' => 'success',
                    'AMARILLO' => 'warning',
                    'ROJO' => 'danger',
                    'RESPONDIDO', 'CERRADO' => 'primary',
                    default => 'secondary'
                };
                $bySemaforo[$sf] = ['count' => 0, 'color' => $color];
            }
            $bySemaforo[$sf]['count']++;
        }

        uasort($byStatus, fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));
        uasort($bySemaforo, fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));

        $avg = $cntFirstResp > 0 ? round($sumFirstRespHours / $cntFirstResp, 1) : 0;

        return [
            'total_cases' => $total,
            'responded' => $responded,
            'pending' => $pending,
            'avg_response_hours' => $avg,
            'by_status' => $byStatus,
            'by_semaforo' => $bySemaforo,
        ];
    }
}
