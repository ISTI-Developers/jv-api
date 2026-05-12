<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');

try {
    $db = Database::connect();
    $user = require_auth($db);

    $isAdmin = in_array((int) $user['role_id'], [1, 2], true);

    if ($isAdmin) {
        $stmt = $db->prepare("
            SELECT 
                m.id AS moa_id,
                m.moa_name,
                l.id AS location_id,
                l.location_name,
                l.report_group
            FROM moa m
            LEFT JOIN moa_locations l
                ON l.moa_id = m.id
                AND l.soft_deleted = 0
            WHERE m.soft_deleted = 0
            ORDER BY m.created_at DESC, l.id ASC
        ");
        $stmt->execute();
    } else {
        $stmt = $db->prepare("
            SELECT 
                m.id AS moa_id,
                m.moa_name,
                l.id AS location_id,
                l.location_name,
                l.report_group
            FROM moa m
            INNER JOIN moa_locations l
                ON l.moa_id = m.id
                AND l.soft_deleted = 0
            INNER JOIN moa_share ms
                ON ms.moa_id = m.id
                AND ms.location_id = l.id
                AND ms.user_id = ?
            WHERE m.soft_deleted = 0
            ORDER BY m.created_at DESC, l.id ASC
        ");
        $stmt->execute([$user['id']]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $moas = [];

    foreach ($rows as $row) {
        $moaId = (int) $row['moa_id'];

        if (!isset($moas[$moaId])) {
            $moas[$moaId] = [
                'id' => $moaId,
                'moa_name' => $row['moa_name'],
                'locations' => [],
            ];
        }

        if (!empty($row['location_id'])) {
            $locationId = (int) $row['location_id'];

            if (!isset($moas[$moaId]['locations'][$locationId])) {
                $moas[$moaId]['locations'][$locationId] = [
                    'id' => $locationId,
                    'location_name' => $row['location_name'],
                    'report_group' => $row['report_group'],
                ];
            }
        }
    }

    foreach ($moas as &$moa) {
        $moa['locations'] = array_values($moa['locations']);
    }
    unset($moa);

    echo json_encode([
        'data' => array_values($moas),
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'error' => $e->getMessage(),
    ]);
}
