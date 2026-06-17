<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');

try {
    $db = Database::connect();
    require_admin($db);

    $stmt = $db->prepare("
        SELECT 
            m.id AS moa_id,
            m.moa_name,
            m.created_at,

            l.id AS location_id,
            l.structure_id,
            l.location_name,
            l.report_group,
            l.group_name,
            l.unai_management_fee,
            l.jv_management_fee,

            ms.id AS moa_shared_id,
            ms.user_id AS jv_user_id,
            ms.share_percentage,

            u.email AS jv_email,

            up.first_name,
            up.last_name,
            up.company_name

        FROM moa m

        LEFT JOIN moa_locations l
            ON l.moa_id = m.id
            AND l.soft_deleted = 0

        LEFT JOIN moa_share ms
            ON ms.moa_id = m.id
            AND ms.location_id = l.id

        LEFT JOIN users u
            ON u.id = ms.user_id

        LEFT JOIN user_profiles up
            ON up.user_id = u.id

        WHERE m.soft_deleted = 0

        ORDER BY m.created_at DESC, l.id ASC, ms.id ASC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $moas = [];

    foreach ($rows as $row) {
        $moaId = (int) $row['moa_id'];

        if (!isset($moas[$moaId])) {
            $moas[$moaId] = [
                'id' => $moaId,
                'moa_name' => $row['moa_name'],
                'locations' => [],
                'created_at' => $row['created_at'],
            ];
        }

        if (!empty($row['location_id'])) {
            $locId = (int) $row['location_id'];

            if (!isset($moas[$moaId]['locations'][$locId])) {
                $moas[$moaId]['locations'][$locId] = [
                    'id' => $locId,
                    'structure_id' => $row['structure_id'] !== null ? (int) $row['structure_id'] : null,
                    'location_name' => $row['location_name'],
                    'report_group' => $row['report_group'],
                    'group_name' => $row['group_name'],
                    'unai_management_fee' => (float) $row['unai_management_fee'],
                    'jv_management_fee' => (float) $row['jv_management_fee'],
                    'jv_users' => [],
                ];
            }

            if (!empty($row['jv_user_id'])) {
                $jvId = (int) $row['jv_user_id'];

                if (!isset($moas[$moaId]['locations'][$locId]['jv_users'][$jvId])) {
                    $moas[$moaId]['locations'][$locId]['jv_users'][$jvId] = [
                        'id' => $jvId,
                        'moa_shared_id' => (int) $row['moa_shared_id'],
                        'email' => $row['jv_email'],
                        'first_name' => $row['first_name'],
                        'last_name' => $row['last_name'],
                        'company_name' => $row['company_name'],
                        'share_percentage' => (float) $row['share_percentage'],
                    ];
                }
            }
        }
    }

    foreach ($moas as &$moa) {
        foreach ($moa['locations'] as &$loc) {
            $loc['jv_users'] = array_values($loc['jv_users']);
        }
        unset($loc);

        $moa['locations'] = array_values($moa['locations']);
    }
    unset($moa);

    echo json_encode([
        'data' => array_values($moas),
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'error' => 'Server error',
    ]);
}
