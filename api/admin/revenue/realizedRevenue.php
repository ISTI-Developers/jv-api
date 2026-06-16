<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../../helpers/audit.php';
require_once __DIR__ . '/../../../helpers/transaction_log.php';

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
    LIMIT 1");

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
    WHERE id = :id");
    $inserted = 0;
    $updated = 0;
    $insertedIds = [];
    $updatedIds = [];
    $invoiceIds = [];
    $moaSharedIds = [];
    $transactionNos = [];
    $referenceNos = [];
    $structureIds = [];
    $amountTotal = 0.0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $amount = $row['realized_revenue'] ?? null;

        if ($amount === null || $amount === '') {
            continue;
        }

        $invoiceId = !empty($row['cInvNo']) ? trim((string) $row['cInvNo']) : null;

        if ($invoiceId === null || $invoiceId === '') {
            continue;
        }

        $moaSharedId = isset($row['moa_shared_id']) && $row['moa_shared_id'] !== '' ? (int) $row['moa_shared_id'] : null;
        $accountNo = !empty($row['cAcctNo']) ? trim((string) $row['cAcctNo']) : null;
        $transactionNo = !empty($row['orNumber']) ? trim((string) $row['orNumber']) : null;
        $jobNumber = !empty($row['cJobNo']) ? trim((string) $row['cJobNo']) : null;
        $dueDateFrom = !empty($row['dueDateFrom']) ? date('Y-m-d', strtotime($row['dueDateFrom'])) : null;
        $dueDateTo = !empty($row['dueDateTo']) ? date('Y-m-d', strtotime($row['dueDateTo'])) : null;
        $structureId = !empty($row['cStuctureID']) ? trim((string) $row['cStuctureID']) : null;
        $siteId = !empty($row['cSiteID']) ? trim((string) $row['cSiteID']) : null;
        $groupName = !empty($row['cGroupName']) ? trim((string) $row['cGroupName']) : null;
        $remarks = !empty($row['remarks']) ? trim((string) $row['remarks']) : null;

        $selectStmt->execute([$invoiceId]);
        $existing = $selectStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $revenueId = (int) $existing['id'];
            $updateStmt->bindValue(':id', (int) $existing['id'], PDO::PARAM_INT);
            $updateStmt->bindValue(':user_id', (int) $user['id'], PDO::PARAM_INT);
            $updateStmt->bindValue(':moa_shared_id', $moaSharedId, $moaSharedId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $updateStmt->bindValue(':account_no', $accountNo, $accountNo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':transaction_no', $transactionNo, $transactionNo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':job_number', $jobNumber, $jobNumber === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':due_date_from', $dueDateFrom, $dueDateFrom === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':due_date_to', $dueDateTo, $dueDateTo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':structure_id', $structureId, $structureId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':site_id', $siteId, $siteId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':amount', (float) $amount, PDO::PARAM_STR);
            $updateStmt->bindValue(':remarks', $remarks, $remarks === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':group_name', $groupName, $groupName === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->execute();

            $updated++;
            $updatedIds[] = $revenueId;
        } else {
            $insertStmt->bindValue(':moa_shared_id', $moaSharedId, $moaSharedId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $insertStmt->bindValue(':user_id', (int) $user['id'], PDO::PARAM_INT);
            $insertStmt->bindValue(':invoice_id', $invoiceId, PDO::PARAM_STR);
            $insertStmt->bindValue(':account_no', $accountNo, $accountNo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':transaction_no', $transactionNo, $transactionNo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':job_number', $jobNumber, $jobNumber === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':due_date_from', $dueDateFrom, $dueDateFrom === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':due_date_to', $dueDateTo, $dueDateTo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':structure_id', $structureId, $structureId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':site_id', $siteId, $siteId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':amount', (float) $amount, PDO::PARAM_STR);
            $insertStmt->bindValue(':remarks', $remarks, $remarks === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->bindValue(':group_name', $groupName, $groupName === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertStmt->execute();

            $inserted++;
            $insertedIds[] = (int) $db->lastInsertId();
        }

        $invoiceIds[] = $invoiceId;

        if ($moaSharedId !== null) {
            $moaSharedIds[] = $moaSharedId;
        }

        if ($transactionNo !== null) {
            $transactionNos[] = $transactionNo;
            $referenceNos[] = $transactionNo;
        }

        if ($structureId !== null) {
            $structureIds[] = $structureId;
        }

        $amountTotal += (float) $amount;
    }

    $db->commit();

    try {
        logTransaction($db, [
            'transaction_type' => 'REVENUE',
            'reference_table' => 'moa_all_revenue',
            'reference_id' => count($insertedIds) + count($updatedIds) === 1
                ? (string) (($insertedIds[0] ?? $updatedIds[0]))
                : null,
            'reference_no' => count(array_unique($referenceNos)) === 1 ? $referenceNos[0] : null,
            'action' => 'IMPORTED',
            'status' => 'SUCCESS',
            'description' => 'Realized revenue rows imported',
            'amount' => $amountTotal,
            'metadata' => [
                'inserted' => $inserted,
                'updated' => $updated,
                'inserted_ids' => $insertedIds,
                'updated_ids' => $updatedIds,
                'invoice_ids' => array_values(array_unique($invoiceIds)),
                'transaction_nos' => array_values(array_unique($transactionNos)),
                'reference_nos' => array_values(array_unique($referenceNos)),
                'structure_ids' => array_values(array_unique($structureIds)),
                'moa_shared_ids' => array_values(array_unique($moaSharedIds)),
                'amount_total' => $amountTotal,
                'row_count' => count($rows),
            ],
            'performed_by' => (int) $user['id'],
        ]);
    } catch (Throwable $e) {
        error_log('Transaction log failed: ' . $e->getMessage());
    }

    try {
        logAudit(
            $db,
            (int) $user['id'],
            'SAVE_REALIZED_REVENUE',
            'REVENUE',
            'moa_all_revenue',
            null,
            null,
            [
                'inserted' => $inserted,
                'updated' => $updated,
                'inserted_ids' => $insertedIds,
                'updated_ids' => $updatedIds,
                'invoice_ids' => array_values(array_unique($invoiceIds)),
                'moa_shared_ids' => array_values(array_unique($moaSharedIds)),
                'row_count' => count($rows),
            ],
            'Realized revenue rows saved'
        );
    } catch (Throwable $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Realized revenue saved successfully',
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
