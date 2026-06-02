<?php

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../middleware/auth.php';

header('Content-Type: application/json');

try {
    $db = Database::connect();
    require_admin($db);

    $roleId = isset($_GET['role_id']) ? (int) $_GET['role_id'] : null;
    $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;

    if (!$roleId && !$userId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'role_id or user_id is required',
        ]);
        exit;
    }

    $sql = "
        SELECT
            p.id,
            p.code,
            p.label,
            p.menu_id,
            m.label AS menu_label,
            CASE WHEN rap.permission_id IS NOT NULL THEN 1 ELSE 0 END AS role_allowed,
            uap.type AS user_override
        FROM permissions p
        LEFT JOIN menus m ON m.id = p.menu_id
        LEFT JOIN role_action_permissions rap
            ON rap.permission_id = p.id
            AND rap.role_id = :role_id
        LEFT JOIN user_action_permissions uap
            ON uap.permission_id = p.id
            AND uap.user_id = :user_id
        ORDER BY m.display_order ASC, p.code ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':role_id' => $roleId ?? 0,
        ':user_id' => $userId ?? 0,
    ]);

    echo json_encode([
        'success' => true,
        'data' => $stmt->fetchAll(),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
