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

    $invoiceIds = $input['invoice_ids'] ?? null;

    if (!is_array($invoiceIds) || empty($invoiceIds)) {
        echo json_encode([
            'success' => true,
            'data' => [],
        ]);
        exit;
    }

    $invoiceIds = array_values(array_unique(array_filter(array_map(function ($invoiceId) {
        return trim((string) $invoiceId);
    }, $invoiceIds))));

    if (empty($invoiceIds)) {
        echo json_encode([
            'success' => true,
            'data' => [],
        ]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));

    $stmt = $db->prepare("
        SELECT
            r.id,
            r.invoice_id,
            r.amount,
            r.user_id,
            r.date_created,
            u.email,
            up.first_name,
            up.last_name,
            up.company_name
        FROM moa_all_revenue r
        LEFT JOIN users u
            ON u.id = r.user_id
        LEFT JOIN user_profiles up
            ON up.user_id = u.id
        WHERE r.invoice_id IN ($placeholders)
    ");

    $stmt->execute($invoiceIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $savedRevenue = [];

    foreach ($rows as $row) {
        $invoiceId = (string) $row['invoice_id'];

        $savedRevenue[$invoiceId] = [
            'id' => (int) $row['id'],
            'invoice_id' => $invoiceId,
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
        'data' => $savedRevenue,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
