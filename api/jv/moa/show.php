<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');

try {
    $db = Database::connect();
    $user = require_auth($db);

    $moaId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($moaId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing moa id']);
        exit;
    }

    $isAdmin = in_array((int) $user['role_id'], [1, 2], true);

    $stmt = $db->prepare("
        SELECT id, moa_name, created_at
        FROM moa
        WHERE id = ?
          AND soft_deleted = 0
        LIMIT 1
    ");
    $stmt->execute([$moaId]);

    $moa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$moa) {
        http_response_code(404);
        echo json_encode(['error' => 'MOA not found']);
        exit;
    }

    if ($isAdmin) {
        $stmt = $db->prepare("
            SELECT
                l.id,
                l.location_name,
                l.report_group
            FROM moa_locations l
            WHERE l.moa_id = ?
              AND l.soft_deleted = 0
            ORDER BY l.id ASC
        ");
        $stmt->execute([$moaId]);

        $allowedLocations = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    } else {
        $stmt = $db->prepare("
            SELECT
                l.id,
                l.location_name,
                l.report_group
            FROM moa_locations l
            INNER JOIN moa_share ms
                ON ms.moa_id = l.moa_id
                AND ms.location_id = l.id
                AND ms.user_id = ?
            WHERE l.moa_id = ?
              AND l.soft_deleted = 0
            ORDER BY l.id ASC
        ");
        $stmt->execute([$user['id'], $moaId]);

        $allowedLocations = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    }

    $stmt = $db->prepare("
        SELECT
            l.id AS location_id,
            l.location_name,
            l.report_group,

            ms.id AS moa_share_id,
            ms.user_id AS jv_user_id,
            ms.share_percentage,

            u.email AS jv_email,
            up.first_name,
            up.last_name,
            up.company_name

        FROM moa_locations l
        LEFT JOIN moa_share ms
            ON ms.location_id = l.id
            AND ms.moa_id = l.moa_id
        LEFT JOIN users u
            ON u.id = ms.user_id
        LEFT JOIN user_profiles up
            ON up.user_id = u.id
        WHERE l.moa_id = ?
          AND l.soft_deleted = 0
        ORDER BY l.id ASC, ms.id ASC
    ");
    $stmt->execute([$moaId]);
    $locationRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $locations = [];

    foreach ($locationRows as $row) {
        $locationId = (int) $row['location_id'];

        if (!$isAdmin && !in_array($locationId, $allowedLocations, true)) {
            continue;
        }

        if (!isset($locations[$locationId])) {
            $locations[$locationId] = [
                'id' => $locationId,
                'location_name' => $row['location_name'],
                'report_group' => $row['report_group'],
                'jv_users' => [],
            ];
        }

        if (!empty($row['jv_user_id'])) {
            $jvUserId = (int) $row['jv_user_id'];

            if (!isset($locations[$locationId]['jv_users'][$jvUserId])) {
                $locations[$locationId]['jv_users'][$jvUserId] = [
                    'id' => $jvUserId,
                    'moa_share_id' => (int) $row['moa_share_id'],
                    'email' => $row['jv_email'],
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'company_name' => $row['company_name'],
                    'share_percentage' => (float) $row['share_percentage'],
                ];
            }
        }
    }

    foreach ($locations as &$location) {
        $location['jv_users'] = array_values($location['jv_users']);
    }
    unset($location);

    $expenseParams = [$moaId];
    $expenseSql = "
        SELECT
            e.id,
            e.moa_share_id,
            e.account_no,
            e.user_id,
            e.due_date_from,
            e.due_date_to,
            e.ref_no,
            e.payee,
            e.particulars,
            e.amount,
            e.date_created,

            ms.moa_id,
            ms.location_id,
            ms.share_percentage,

            u.email,
            u.role_id,
            r.name AS role_name,
            up.first_name,
            up.last_name,
            up.company_name

        FROM moa_jv_expenses e
        INNER JOIN moa_share ms
            ON ms.id = e.moa_share_id
        INNER JOIN users u
            ON u.id = e.user_id
        LEFT JOIN roles r
            ON r.id = u.role_id
        LEFT JOIN user_profiles up
            ON up.user_id = u.id
        WHERE ms.moa_id = ?
    ";

    if (!$isAdmin) {
        if (empty($allowedLocations)) {
            $expenseSql .= " AND 1 = 0";
        } else {
            $placeholders = implode(',', array_fill(0, count($allowedLocations), '?'));
            $expenseSql .= " AND ms.location_id IN ($placeholders)";
            $expenseParams = array_merge($expenseParams, $allowedLocations);
        }
    }

    $expenseSql .= " ORDER BY ms.location_id ASC, e.account_no ASC, e.date_created ASC, e.id ASC";

    $stmt = $db->prepare($expenseSql);
    $stmt->execute($expenseParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $expenses = [];

    foreach ($rows as $row) {
        $locId = (int) $row['location_id'];
        $accountNo = (string) ($row['account_no'] ?? '');

        if (!isset($expenses[$locId])) {
            $expenses[$locId] = [];
        }

        if (!isset($expenses[$locId][$accountNo])) {
            $expenses[$locId][$accountNo] = [];
        }

        $expenses[$locId][$accountNo][] = [
            'id' => (int) $row['id'],
            'moa_share_id' => (int) $row['moa_share_id'],
            'user_id' => (int) $row['user_id'],
            'account_no' => $row['account_no'],
            'share_percentage' => (float) $row['share_percentage'],
            'due_date_from' => $row['due_date_from'],
            'due_date_to' => $row['due_date_to'],
            'ref_no' => $row['ref_no'],
            'payee' => $row['payee'],
            'particulars' => $row['particulars'],
            'amount' => (float) $row['amount'],
            'date_created' => $row['date_created'],
            'user' => [
                'id' => (int) $row['user_id'],
                'email' => $row['email'],
                'role_id' => (int) $row['role_id'],
                'role_name' => $row['role_name'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'company_name' => $row['company_name'],
            ],
        ];
    }

    echo json_encode([
        'data' => [
            'moa' => [
                'id' => (int) $moa['id'],
                'moa_name' => $moa['moa_name'],
                'created_at' => $moa['created_at'],
            ],
            'locations' => array_values($locations),
            'categories' => [],
            'expenses' => $expenses,
            'allowed_locations' => $allowedLocations,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
