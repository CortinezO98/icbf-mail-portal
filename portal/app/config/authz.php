<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function require_login(): int {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) {
        http_response_code(401);
        exit("No autenticado");
    }
    return (int)$uid;
}

function user_has_any_role(PDO $pdo, int $userId, array $roleCodes): bool {
    // roles.code en tu BD: varchar(30)
    $in = implode(',', array_fill(0, count($roleCodes), '?'));
    $sql = "
        SELECT 1
        FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ?
          AND r.code IN ($in)
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $params = array_merge([$userId], $roleCodes);
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
}

function require_roles(array $roleCodes): int {
    $pdo = db();
    $uid = require_login();

    if (!user_has_any_role($pdo, $uid, $roleCodes)) {
        http_response_code(403);
        exit("Acceso denegado. Requiere rol: " . implode(' / ', $roleCodes));
    }
    return $uid;
}
