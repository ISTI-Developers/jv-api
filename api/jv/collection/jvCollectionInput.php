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
    $user = require_auth($db);

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON payload',
        ]);
        exit;
    }

    $rows = $input['rows'] ?? null;

    if (!is_array($rows) || empty($rows)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Rows are required',
        ]);
        exit;
    }

    $db->beginTransaction();

    $selectStmt = $db->prepare("
        SELECT id
        FROM moa_all_revenue
        WHERE invoice_id = ?
        LIMIT 1
    ");

    $insertStmt = $db->prepare("
        INSERT INTO moa_all_revenue (
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
            group_name
        ) VALUES (
            :moa_shared_id,
            :user_id,
            :invoice_id,
            :account_no,
            :transaction_no,
            :job_number,
            :due_date_from,
            :due_date_to,
            :structure_id,
            :site_id,
            :amount,
            :remarks,
            :group_name
        )
    ");

    $updateStmt = $db->prepare("
        UPDATE moa_all_revenue
        SET
            moa_shared_id = :moa_shared_id,
            user_id = :user_id,
            account_no = :account_no,
            transaction_no = :transaction_no,
            job_number = :job_number,
            due_date_from = :due_date_from,
            due_date_to = :due_date_to,
            structure_id = :structure_id,
            site_id = :site_id,
            amount = :amount,
            remarks = :remarks,
            group_name = :group_name
        WHERE id = :id
    ");

    $inserted = 0;
    $updated = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $amount = $row['collection_amount'] ?? null;

        if ($amount === null || $amount === '') {
            continue;
        }

        $invoiceId = !empty($row['invoice_id']) ? trim((string) $row['invoice_id']) : null;

        if ($invoiceId === null || $invoiceId === '') {
            continue;
        }

        $moaSharedId = isset($row['moa_shared_id']) && $row['moa_shared_id'] !== ''
            ? (int) $row['moa_shared_id']
            : null;

        $jobNumber = !empty($row['job_number']) ? trim((string) $row['job_number']) : null;
        $dueDateFrom = !empty($row['date_from']) ? date('Y-m-d', strtotime($row['date_from'])) : null;
        $dueDateTo = !empty($row['date_to']) ? date('Y-m-d', strtotime($row['date_to'])) : null;
        $structureId = !empty($row['structure_id']) ? trim((string) $row['structure_id']) : null;
        $remarks = !empty($row['remarks']) ? trim((string) $row['remarks']) : null;

        $accountNo = null;
        $transactionNo = null;
        $siteId = null;
        $groupName = null;

        $selectStmt->execute([$invoiceId]);
        $existing = $selectStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $updateStmt->bindValue(':id', (int) $existing['id'], PDO::PARAM_INT);
            $updateStmt->bindValue(':moa_shared_id', $moaSharedId, $moaSharedId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $updateStmt->bindValue(':user_id', (int) $user['id'], PDO::PARAM_INT);
            $updateStmt->bindValue(':account_no', $accountNo, PDO::PARAM_NULL);
            $updateStmt->bindValue(':transaction_no', $transactionNo, PDO::PARAM_NULL);
            $updateStmt->bindValue(':job_number', $jobNumber, $jobNumber === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':due_date_from', $dueDateFrom, $dueDateFrom === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':due_date_to', $dueDateTo, $dueDateTo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':structure_id', $structureId, $structureId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':site_id', $siteId, PDO::PARAM_NULL);
            $updateStmt->bindValue(':amount', (float) $amount, PDO::PARAM_STR);
            $updateStmt->bindValue(':remarks', $remarks, $remarks === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':group_name', $groupName, PDO::PARAM_NULL);
            $updateStmt->execute();

            $updated++;
        } else {
            $insertStmt->bindValue(':moa_shared_id', $moaSharedId, $moaSharedId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $insertStmt->bindValue(':user_id', (int) $user['id'], PDO::PARAM_INT);
            $insertStmt->bindValue(':invoice_id', $invoiceId, PDO::PARAM_STR);
            $insertStmt->bindValue(':account_no', $accountNo, PDO::PARAM_NULL);
            $insertStmt->bindValue(':transaction_no', $transactionNo, PDO::PARAM_NULL);
            $insertStmt->bindValue(':job_number', $jobNumber, $jobNumber === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':due_date_from', $dueDateFrom, $dueDateFrom === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':due_date_to', $dueDateTo, $dueDateTo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':structure_id', $structureId, $structureId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':site_id', $siteId, PDO::PARAM_NULL);
            $insertStmt->bindValue(':amount', (float) $amount, PDO::PARAM_STR);
            $insertStmt->bindValue(':remarks', $remarks, $remarks === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':group_name', $groupName, PDO::PARAM_NULL);
            $insertStmt->execute();

            $inserted++;
        }
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'JV collection input saved successfully',
        'inserted' => $inserted,
        'updated' => $updated,
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
