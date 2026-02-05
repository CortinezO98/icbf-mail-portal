<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Auth\Auth;
use App\Auth\Csrf;
use App\Repos\MetricsRepo;
use App\Repos\UsersRepo;

use function App\Config\url;

final class ReportsController
{
    private MetricsRepo $metrics;
    private UsersRepo $usersRepo;

    public function __construct(private PDO $pdo, private array $config)
    {
        $this->metrics = new MetricsRepo($pdo);
        $this->usersRepo = new UsersRepo($pdo);
    }

    public function index(): void
    {
        if (!Auth::hasRole('SUPERVISOR') && !Auth::hasRole('ADMIN')) {
            http_response_code(403);
            echo "Acceso denegado";
            exit;
        }

        $agents = $this->metrics->getAgentsForReport();
        $recentReports = $this->metrics->getRecentReports(5);
        
        $this->render('reports/index.php', [
            'agents' => $agents,
            'recentReports' => $recentReports,
            'csrfToken' => Csrf::token()
        ]);
    }

    public function generate(): void
    {
        if (!Auth::hasRole('SUPERVISOR') && !Auth::hasRole('ADMIN')) {
            http_response_code(403);
            echo "Acceso denegado";
            exit;
        }

        Csrf::validate($_POST['_csrf'] ?? null);

        $params = [
            'start_date' => trim($_POST['start_date'] ?? ''),
            'end_date'   => trim($_POST['end_date'] ?? ''),
            'status'     => trim($_POST['status'] ?? ''),
            'agent_id'   => !empty($_POST['agent_id']) ? (int)$_POST['agent_id'] : null,
            'semaforo'   => trim($_POST['semaforo'] ?? ''),
            'format'     => strtolower(trim($_POST['format'] ?? 'html'))
        ];

        $data = $this->metrics->generateReport($params);
        $summary = $this->metrics->getReportSummary($params);
        
        if ($params['format'] === 'csv') {
            $this->exportCSV($data, $params);
        } elseif ($params['format'] === 'excel') {
            $this->exportExcel($data, $params);
        } else {
            $this->render('reports/results.php', [
                'data' => $data,
                'summary' => $summary,
                'params' => $params,
                'agents' => $this->metrics->getAgentsForReport()
            ]);
        }
    }

    public function download(int $reportId): void
    {
        if (!Auth::check()) {
            http_response_code(403);
            echo "Acceso denegado";
            exit;
        }

        $sql = "SELECT file_path, report_type FROM generated_reports WHERE id = :id LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $reportId]);
        $report = $stmt->fetch();

        if (!$report || !file_exists($report['file_path'])) {
            http_response_code(404);
            echo "Reporte no encontrado";
            exit;
        }

        $this->metrics->incrementReportDownload($reportId);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="reporte_' . $reportId . '_' . date('Y-m-d') . '.' . pathinfo($report['file_path'], PATHINFO_EXTENSION) . '"');
        header('Content-Length: ' . filesize($report['file_path']));
        
        readfile($report['file_path']);
        exit;
    }

    private function exportCSV(array $data, array $params): void
    {
        $filename = 'reporte_' . date('Y-m-d_His') . '.csv';
        $filepath = sys_get_temp_dir() . '/' . $filename;
        
        $fp = fopen($filepath, 'w');
        
        // Encabezados
        fputcsv($fp, [
            'Número de Caso',
            'Asunto',
            'Solicitante',
            'Email Solicitante',
            'Estado',
            'Asignado a',
            'Fecha Creación',
            'Fecha Asignación',
            'Fecha Respuesta',
            'Días desde Creación',
            'Semáforo',
            'Horas Respuesta',
            'Respondido'
        ], ';');
        
        // Datos
        foreach ($data as $row) {
            fputcsv($fp, [
                $row['case_number'] ?? '',
                $row['subject'] ?? '',
                $row['requester_name'] ?? '',
                $row['requester_email'] ?? '',
                $row['status_name'] ?? '',
                $row['assigned_to'] ?? '',
                $row['created_at'] ?? '',
                $row['assigned_at'] ?? '',
                $row['first_response_at'] ?? '',
                $row['dias_desde_creacion'] ?? 0,
                $row['semaforo'] ?? '',
                $row['horas_respuesta'] ?? '',
                $row['is_responded'] ? 'Sí' : 'No'
            ], ';');
        }
        
        fclose($fp);
        
        $reportId = $this->metrics->saveGeneratedReport('CSV', $filepath, Auth::id());
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        
        readfile($filepath);
        
        // Limpiar archivo temporal después de 5 minutos
        register_shutdown_function(function() use ($filepath) {
            sleep(300);
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        });
        
        exit;
    }

    private function exportExcel(array $data, array $params): void
    {
        // Para Excel simple, generamos HTML con tabla que Excel puede abrir
        $filename = 'reporte_' . date('Y-m-d_His') . '.xls';
        
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        echo '<html><head>';
        echo '<meta charset="UTF-8">';
        echo '<style>td { border: 1px solid #ccc; padding: 5px; }</style>';
        echo '</head><body>';
        echo '<h2>Reporte de Casos - ' . date('d/m/Y H:i:s') . '</h2>';
        
        if ($params['start_date'] || $params['end_date']) {
            echo '<p><strong>Período:</strong> ';
            echo $params['start_date'] ? 'Desde ' . $params['start_date'] : '';
            echo $params['end_date'] ? ' hasta ' . $params['end_date'] : '';
            echo '</p>';
        }
        
        echo '<table border="1">';
        echo '<tr style="background-color: #f0f0f0; font-weight: bold;">';
        echo '<td># Caso</td><td>Asunto</td><td>Solicitante</td><td>Email</td>';
        echo '<td>Estado</td><td>Asignado a</td><td>Fecha Creación</td>';
        echo '<td>Semáforo</td><td>Días</td><td>Respondido</td>';
        echo '</tr>';
        
        foreach ($data as $row) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($row['case_number'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($row['subject'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($row['requester_name'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($row['requester_email'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($row['status_name'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($row['assigned_to'] ?? 'Sin asignar') . '</td>';
            echo '<td>' . ($row['created_at'] ? date('d/m/Y H:i', strtotime($row['created_at'])) : '') . '</td>';
            
            $semaforo = $row['semaforo'] ?? '';
            $color = match($semaforo) {
                'VERDE' => '#10b981',
                'AMARILLO' => '#f59e0b',
                'ROJO' => '#ef4444',
                'RESPONDIDO' => '#3b82f6',
                default => '#6b7280'
            };
            
            echo '<td style="color: ' . $color . '; font-weight: bold;">' . $semaforo . '</td>';
            echo '<td>' . ($row['dias_desde_creacion'] ?? 0) . '</td>';
            echo '<td>' . ($row['is_responded'] ? 'Sí' : 'No') . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        echo '</body></html>';
        
        $tempFile = sys_get_temp_dir() . '/' . $filename;
        file_put_contents($tempFile, ob_get_contents());
        $this->metrics->saveGeneratedReport('Excel', $tempFile, Auth::id());
        
        exit;
    }

    private function render(string $view, array $params = []): void
    {
        extract($params, EXTR_SKIP);
        $viewPath = dirname(__DIR__) . '/views/' . $view;
        include dirname(__DIR__) . '/views/layout.php';
    }
}