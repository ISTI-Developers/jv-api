<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json');

try {
  $db = Database::connect();
  $user = require_auth($db);

  $sql = "
        SELECT
            m.id,
            m.label,
            m.path,
            m.parent_id,
            m.display_order
        FROM menus m
        LEFT JOIN role_permissions rp
            ON rp.menu_id = m.id
            AND rp.role_id = :role_id
        LEFT JOIN user_permissions up
            ON up.menu_id = m.id
            AND up.user_id = :user_id
        LEFT JOIN menus p
            ON p.id = m.parent_id
        WHERE m.is_active = 1
        AND (
            up.type = 'allow'
            OR (
                up.type IS NULL
                AND rp.menu_id IS NOT NULL
            )
        )
        AND (
            up.type IS NULL
            OR up.type != 'deny'
        )
        ORDER BY
            COALESCE(p.display_order, m.display_order) ASC,
            CASE WHEN m.parent_id IS NULL THEN 0 ELSE 1 END ASC,
            m.display_order ASC
    ";

  $stmt = $db->prepare($sql);
  $stmt->execute([
    ':role_id' => $user['role_id'],
    ':user_id' => $user['id'],
  ]);

  echo json_encode([
    'success' => true,
    'data' => $stmt->fetchAll()
  ]);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'message' => $e->getMessage()
  ]);
}
