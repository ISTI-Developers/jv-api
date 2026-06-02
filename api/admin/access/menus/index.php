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
            m.id,
            m.label,
            m.path,
            m.parent_id,
            m.display_order,
            CASE WHEN rp.menu_id IS NOT NULL THEN 1 ELSE 0 END AS role_allowed,
            up.type AS user_override
        FROM menus m
        LEFT JOIN role_permissions rp
            ON rp.menu_id = m.id
            AND rp.role_id = :role_id
        LEFT JOIN user_permissions up
            ON up.menu_id = m.id
            AND up.user_id = :user_id
        WHERE m.is_active = 1
        ORDER BY
            COALESCE(m.parent_id, m.id),
            m.parent_id IS NOT NULL,
            m.display_order
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