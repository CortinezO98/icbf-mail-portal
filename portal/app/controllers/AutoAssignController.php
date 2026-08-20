<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Auth\Csrf;

/**
 * Compatibilidad de ruta histórica.
 *
 * R2 elimina la asignación manual paralela para que exista una sola fuente
 * de verdad: el assignment worker, que aplica presencia + capacidad máxima +
 * FIFO + concurrencia transaccional. Mantener este endpoint como no-op evita
 * que clientes antiguos se salten esas reglas.
 */
final class AutoAssignController
{
    public function __construct(private PDO $pdo, private array $config)
    {
    }

    public function run(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        Csrf::validate($_POST['_csrf'] ?? null);

        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'code' => 'ASSIGNMENT_WORKER_MANAGED',
            'message' => 'La asignación ahora es automática y la gestiona el worker según disponibilidad y capacidad de los asesores.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
