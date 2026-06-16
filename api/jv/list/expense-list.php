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
            moa_shared_id,
            user_id,
            invoice_id,
            account_no,
            transaction_no,
            job_number,
            due_date_from,
            due_date_to,
            structure_id,
            site_id,
            amount,
            remarks,
            group_name,
            date_created
        FROM moa_all_expense
        ORDER BY date_created DESC
    ");

    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $rows,
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
