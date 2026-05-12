<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');

try {
    $db = Database::connect();
    $user = require_auth($db);

    $stmt = $db->prepare("
        SELECT DISTINCT
            l.structure_id
        FROM moa_share ms
        INNER JOIN moa_locations l
            ON l.id = ms.location_id
            AND l.soft_deleted = 0
        INNER JOIN moa m
            ON m.id = ms.moa_id
            AND m.soft_deleted = 0
        WHERE ms.user_id = ?
          AND l.structure_id IS NOT NULL
        ORDER BY l.structure_id ASC
    ");
    $stmt->execute([$user['id']]);

    $structureIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    echo json_encode([
        'data' => $structureIds,
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'error' => $e->getMessage(),
    ]);
}
