<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit;
}

try {
    $db = Database::connect();
    require_auth($db);

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON payload',
        ]);
        exit;
    }

    $transactionNos = $input['transaction_ids'] ?? $input['transaction_nos'] ?? null;

    if (!is_array($transactionNos) || empty($transactionNos)) {
        echo json_encode([
            'success' => true,
            'data' => [],
        ]);
        exit;
    }

    $transactionNos = array_values(array_unique(array_filter(array_map(function ($transactionNo) {
        return trim((string) $transactionNo);
    }, $transactionNos))));

    if (empty($transactionNos)) {
        echo json_encode([
            'success' => true,
            'data' => [],
        ]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($transactionNos), '?'));

    $stmt = $db->prepare("
        SELECT
            r.id,
            r.transaction_no,
            r.amount,
            r.user_id,
            r.date_created,
            u.email,
            up.first_name,
            up.last_name,
            up.company_name
        FROM moa_all_expense r
        LEFT JOIN users u
            ON u.id = r.user_id
        LEFT JOIN user_profiles up
            ON up.user_id = u.id
        WHERE r.transaction_no IN ($placeholders)
    ");

    $stmt->execute($transactionNos);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $savedExpenses = [];

    foreach ($rows as $row) {
        $transactionNo = trim((string) $row['transaction_no']);

        $savedExpenses[$transactionNo] = [
            'id' => (int) $row['id'],
            'transaction_no' => $transactionNo,
            'amount' => $row['amount'] !== null ? (float) $row['amount'] : null,
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'date_created' => $row['date_created'],
            'user' => [
                'email' => $row['email'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'company_name' => $row['company_name'],
            ],
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $savedExpenses,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
