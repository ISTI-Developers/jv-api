<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');

try {
    $db = Database::connect();
    require_admin($db);

    $moaId = isset($_GET['moa_id']) ? (int) $_GET['moa_id'] : 0;

    if ($moaId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid MOA ID']);
        exit;
    }

    $stmt = $db->prepare("
        SELECT id, moa_name, created_at
        FROM moa
        WHERE id = ? AND soft_deleted = 0
        LIMIT 1
    ");
    $stmt->execute([$moaId]);
    $moa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$moa) {
        http_response_code(404);
        echo json_encode(['error' => 'MOA not found']);
        exit;
    }

    $stmt = $db->prepare("
        SELECT
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
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $locations = [];

    foreach ($rows as $row) {
        $locationId = (int) $row['location_id'];

        if (!isset($locations[$locationId])) {
            $locations[$locationId] = [
                'id' => $locationId,
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
            $userId = (int) $row['jv_user_id'];

            if (!isset($locations[$locationId]['jv_users'][$userId])) {
                $locations[$locationId]['jv_users'][$userId] = [
                    'id' => $userId,
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

    foreach ($locations as &$location) {
        $location['jv_users'] = array_values($location['jv_users']);
    }
    unset($location);

    $stmt = $db->prepare("
        SELECT
            e.id,
            e.moa_shared_id,
            e.account_no,
            e.user_id,
            e.input_source,
            e.due_date,
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
            up.first_name,
            up.last_name,
            up.company_name

        FROM moa_jv_expenses e
        INNER JOIN moa_share ms
            ON ms.id = e.moa_shared_id
        LEFT JOIN users u
            ON u.id = e.user_id
        LEFT JOIN user_profiles up
            ON up.user_id = u.id
        WHERE ms.moa_id = ?
        ORDER BY ms.location_id ASC, e.date_created DESC, e.id DESC
    ");
    $stmt->execute([$moaId]);
    $expenseRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $expenses = [];
    $unaiManualExpenses = [];
    $manualExpenses = [
        'JV' => [],
        'UNAI' => [],
    ];

    foreach ($expenseRows as $row) {
        $locationId = (int) $row['location_id'];
        $accountNo = (string) ($row['account_no'] ?? '');
        $inputSource = $row['input_source'] === 'UNAI' ? 'UNAI' : 'JV';

        $expense = [
            'id' => (int) $row['id'],
            'moa_shared_id' => (int) $row['moa_shared_id'],
            'account_no' => $row['account_no'],
            'user_id' => (int) $row['user_id'],
            'input_source' => $inputSource,
            'location_id' => $locationId,
            'share_percentage' => (float) $row['share_percentage'],
            'due_date' => $row['due_date'],
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
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'company_name' => $row['company_name'],
            ],
        ];

        if (!isset($manualExpenses[$inputSource][$locationId])) {
            $manualExpenses[$inputSource][$locationId] = [];
        }

        if (!isset($manualExpenses[$inputSource][$locationId][$accountNo])) {
            $manualExpenses[$inputSource][$locationId][$accountNo] = [];
        }

        $manualExpenses[$inputSource][$locationId][$accountNo][] = $expense;

        if ($inputSource === 'JV') {
            if (!isset($expenses[$locationId])) {
                $expenses[$locationId] = [];
            }

            if (!isset($expenses[$locationId][$accountNo])) {
                $expenses[$locationId][$accountNo] = [];
            }

            $expenses[$locationId][$accountNo][] = $expense;
        } else {
            if (!isset($unaiManualExpenses[$locationId])) {
                $unaiManualExpenses[$locationId] = [];
            }

            if (!isset($unaiManualExpenses[$locationId][$accountNo])) {
                $unaiManualExpenses[$locationId][$accountNo] = [];
            }

            $unaiManualExpenses[$locationId][$accountNo][] = $expense;
        }
    }

    $stmt = $db->prepare("
        SELECT
            r.id,
            r.moa_shared_id,
            r.account_no,
            r.user_id,
            r.input_source,
            r.due_date,
            r.due_date_from,
            r.due_date_to,
            r.ref_no,
            r.payee,
            r.particulars,
            r.amount,
            r.date_created,

            ms.moa_id,
            ms.location_id,
            ms.share_percentage,

            u.email,
            up.first_name,
            up.last_name,
            up.company_name

        FROM moa_unai_revenue r
        INNER JOIN moa_share ms
            ON ms.id = r.moa_shared_id
        LEFT JOIN users u
            ON u.id = r.user_id
        LEFT JOIN user_profiles up
            ON up.user_id = u.id
        WHERE ms.moa_id = ?
          AND r.input_source = 'UNAI'
        ORDER BY ms.location_id ASC, r.date_created DESC, r.id DESC
    ");
    $stmt->execute([$moaId]);
    $revenueRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unaiManualRevenue = [];

    foreach ($revenueRows as $row) {
        $locationId = (int) $row['location_id'];
        $accountNo = (string) ($row['account_no'] ?? '');

        if (!isset($unaiManualRevenue[$locationId])) {
            $unaiManualRevenue[$locationId] = [];
        }

        if (!isset($unaiManualRevenue[$locationId][$accountNo])) {
            $unaiManualRevenue[$locationId][$accountNo] = [];
        }

        $unaiManualRevenue[$locationId][$accountNo][] = [
            'id' => (int) $row['id'],
            'moa_shared_id' => (int) $row['moa_shared_id'],
            'account_no' => $row['account_no'],
            'user_id' => (int) $row['user_id'],
            'input_source' => $row['input_source'],
            'location_id' => $locationId,
            'share_percentage' => (float) $row['share_percentage'],
            'due_date' => $row['due_date'],
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
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'company_name' => $row['company_name'],
            ],
        ];
    }

    echo json_encode([
        'moa' => [
            'id' => (int) $moa['id'],
            'moa_name' => $moa['moa_name'],
            'created_at' => $moa['created_at'],
        ],
        'locations' => array_values($locations),
        'expenses' => $expenses,
        'manual_expenses' => $manualExpenses,
        'unai_manual_expenses' => $unaiManualExpenses,
        'unai_manual_revenue' => $unaiManualRevenue,
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'error' => 'Server error',
    ]);
}
