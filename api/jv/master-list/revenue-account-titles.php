<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');

try {
    $db = Database::connect();
    require_auth($db);

    $stmt = $db->prepare("
        SELECT
            id,
            account_no,
            account_title
        FROM master_revenue_account_titles
        WHERE is_enabled = 1
        ORDER BY account_title ASC
    ");
    $stmt->execute();

    $data = array_map(function ($row) {
        return [
            'id' => (int) $row['id'],
            'account_no' => $row['account_no'],
            'account_title' => $row['account_title'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        'success' => true,
        'data' => $data,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
    ]);
}
