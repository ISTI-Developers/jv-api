<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../../helpers/audit.php';

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
        FROM moa_all_expense
        WHERE transaction_no = ?
        LIMIT 1
    ");

    $insertStmt = $db->prepare("
        INSERT INTO moa_all_expense (
            moa_shared_id,
            user_id,
            invoice_id,
            account_no,
            transaction_no,
            due_date_from,
            due_date_to,
            structure_id,
            amount,
            group_name
        ) VALUES (
            :moa_shared_id,
            :user_id,
            :invoice_id,
            :account_no,
            :transaction_no,
            :due_date_from,
            :due_date_to,
            :structure_id,
            :amount,
            :group_name
        )
    ");

    $updateStmt = $db->prepare("
        UPDATE moa_all_expense
        SET
            moa_shared_id = :moa_shared_id,
            user_id = :user_id,
            invoice_id = :invoice_id,
            account_no = :account_no,
            due_date_from = :due_date_from,
            due_date_to = :due_date_to,
            structure_id = :structure_id,
            amount = :amount,
            group_name = :group_name
        WHERE id = :id
    ");

    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $insertedIds = [];
    $updatedIds = [];
    $transactionNos = [];
    $moaSharedIds = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            $skipped++;
            continue;
        }

        $transactionNo = !empty($row['cTranNo'])
            ? trim((string) $row['cTranNo'])
            : null;

        if ($transactionNo === null || $transactionNo === '') {
            $skipped++;
            continue;
        }

        $amount = $row['realized_expense'] ?? null;

        if ($amount === null || $amount === '') {
            $skipped++;
            continue;
        }

        $invoiceId = null;

        $moaSharedId = isset($row['moa_shared_id']) && $row['moa_shared_id'] !== ''
            ? (int) $row['moa_shared_id']
            : null;

        $accountNo = !empty($row['cAcctNo'])
            ? trim((string) $row['cAcctNo'])
            : null;

        $dueDateFrom = !empty($row['dDueDateFrom'])
            ? date('Y-m-d', strtotime($row['dDueDateFrom']))
            : null;

        $dueDateTo = !empty($row['dDueDateTo'])
            ? date('Y-m-d', strtotime($row['dDueDateTo']))
            : null;

        $structureId = !empty($row['cStructureID'])
            ? trim((string) $row['cStructureID'])
            : null;

        $groupName = !empty($row['cGroupName'])
            ? trim((string) $row['cGroupName'])
            : null;

        $selectStmt->execute([$transactionNo]);
        $existing = $selectStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $expenseId = (int) $existing['id'];
            $updateStmt->bindValue(':id', (int) $existing['id'], PDO::PARAM_INT);
            $updateStmt->bindValue(':moa_shared_id', $moaSharedId, $moaSharedId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $updateStmt->bindValue(':user_id', (int) $user['id'], PDO::PARAM_INT);
            $updateStmt->bindValue(':invoice_id', $invoiceId, PDO::PARAM_NULL);
            $updateStmt->bindValue(':account_no', $accountNo, $accountNo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':due_date_from', $dueDateFrom, $dueDateFrom === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':due_date_to', $dueDateTo, $dueDateTo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':structure_id', $structureId, $structureId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':amount', (float) $amount, PDO::PARAM_STR);
            $updateStmt->bindValue(':group_name', $groupName, $groupName === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->execute();

            $updated++;
            $updatedIds[] = $expenseId;
        } else {
            $insertStmt->bindValue(':moa_shared_id', $moaSharedId, $moaSharedId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $insertStmt->bindValue(':user_id', (int) $user['id'], PDO::PARAM_INT);
            $insertStmt->bindValue(':invoice_id', $invoiceId, PDO::PARAM_NULL);
            $insertStmt->bindValue(':account_no', $accountNo, $accountNo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':transaction_no', $transactionNo, PDO::PARAM_STR);
            $insertStmt->bindValue(':due_date_from', $dueDateFrom, $dueDateFrom === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':due_date_to', $dueDateTo, $dueDateTo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':structure_id', $structureId, $structureId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':amount', (float) $amount, PDO::PARAM_STR);
            $insertStmt->bindValue(':group_name', $groupName, $groupName === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->execute();

            $inserted++;
            $insertedIds[] = (int) $db->lastInsertId();
        }

        $transactionNos[] = $transactionNo;

        if ($moaSharedId !== null) {
            $moaSharedIds[] = $moaSharedId;
        }
    }

    $db->commit();

    logAudit(
        $db,
        (int) $user['id'],
        'SAVE_REALIZED_EXPENSES',
        'EXPENSES',
        'moa_all_expense',
        null,
        null,
        [
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'inserted_ids' => $insertedIds,
            'updated_ids' => $updatedIds,
            'transaction_nos' => array_values(array_unique($transactionNos)),
            'moa_shared_ids' => array_values(array_unique($moaSharedIds)),
            'row_count' => count($rows),
        ],
        'Realized expense rows saved'
    );

    echo json_encode([
        'success' => true,
        'message' => 'Expense data saved successfully',
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => $skipped,
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
