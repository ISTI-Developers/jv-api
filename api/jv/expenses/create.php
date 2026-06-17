<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../../helpers/audit.php';
require_once __DIR__ . '/../../../helpers/transaction_log.php';

header('Content-Type: application/json');

try {
    $db = Database::connect();
    $user = require_auth($db);
    $input = json_decode(file_get_contents('php://input'), true);

    if (
        !isset($input['moa_id']) ||
        !isset($input['expenses']) ||
        !is_array($input['expenses'])
    ) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        exit;
    }

    $moaId = (int) $input['moa_id'];
    $expenses = $input['expenses'];

    if ($moaId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid MOA ID']);
        exit;
    }

    $db->beginTransaction();

    $shareStmt = $db->prepare("
        SELECT ms.id, ms.location_id, l.structure_id
        FROM moa_share ms
        LEFT JOIN moa_locations l
            ON l.id = ms.location_id
           AND l.moa_id = ms.moa_id
        WHERE ms.moa_id = ?
          AND ms.user_id = ?
    ");
    $shareStmt->execute([$moaId, $user['id']]);
    $shareRows = $shareStmt->fetchAll(PDO::FETCH_ASSOC);

    $shareByLocation = [];
    $structureByLocation = [];
    foreach ($shareRows as $shareRow) {
        $shareLocationId = (int) $shareRow['location_id'];
        $shareByLocation[$shareLocationId] = (int) $shareRow['id'];
        $structureByLocation[$shareLocationId] = $shareRow['structure_id'];
    }

    $insertStmt = $db->prepare("
        INSERT INTO moa_jv_expenses (
            moa_shared_id,
            account_no,
            user_id,
            due_date_from,
            due_date_to,
            ref_no,
            payee,
            particulars,
            amount
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $inserted = 0;
    $insertedIds = [];
    $locationIds = [];
    $moaShareIds = [];
    $insertedDetails = [];

    foreach ($expenses as $exp) {
        if (
            !isset($exp['location_id']) ||
            !isset($exp['account_no']) ||
            !isset($exp['amount']) ||
            !isset($exp['ref_no']) ||
            !isset($exp['payee'])
        ) {
            continue;
        }

        $locationId = (int) $exp['location_id'];
        $accountNo = trim((string) $exp['account_no']);
        $amount = (float) $exp['amount'];
        $refNo = trim((string) $exp['ref_no']);
        $payee = trim((string) $exp['payee']);
        $particulars = trim((string) ($exp['particulars'] ?? $exp['name'] ?? ''));
        $dueDateFrom = !empty($exp['due_date_from']) ? $exp['due_date_from'] : (!empty($exp['date']) ? $exp['date'] : null);
        $dueDateTo = !empty($exp['due_date_to']) ? $exp['due_date_to'] : null;

        if (
            $locationId <= 0 ||
            !isset($shareByLocation[$locationId]) ||
            $accountNo === '' ||
            $amount <= 0 ||
            $refNo === '' ||
            $payee === ''
        ) {
            continue;
        }

        $insertStmt->execute([
            $shareByLocation[$locationId],
            $accountNo,
            $user['id'],
            $dueDateFrom,
            $dueDateTo,
            $refNo,
            $payee,
            $particulars !== '' ? $particulars : null,
            $amount,
        ]);

        $inserted++;
        $expenseId = (int) $db->lastInsertId();
        $moaShareId = $shareByLocation[$locationId];
        $insertedIds[] = $expenseId;
        $locationIds[] = $locationId;
        $moaShareIds[] = $moaShareId;
        $insertedDetails[] = [
            'id' => $expenseId,
            'moa_id' => $moaId,
            'moa_shared_id' => $moaShareId,
            'location_id' => $locationId,
            'structure_id' => $structureByLocation[$locationId] ?? null,
            'account_no' => $accountNo,
            'amount' => $amount,
            'ref_no' => $refNo,
            'payee' => $payee,
            'particulars' => $particulars !== '' ? $particulars : null,
            'due_date_from' => $dueDateFrom,
            'due_date_to' => $dueDateTo,
        ];
    }

    $db->commit();

    try {
        logTransaction($db, [
            'transaction_type' => 'JV_EXPENSE',
            'moa_id' => $moaId,
            'reference_table' => 'moa_jv_expenses',
            'reference_id' => count($insertedIds) === 1 ? (string) $insertedIds[0] : null,
            'action' => 'CREATED',
            'status' => 'SUCCESS',
            'description' => 'JV expense rows created',
            'metadata' => [
                'inserted' => $inserted,
                'inserted_ids' => $insertedIds,
                'location_ids' => array_values(array_unique($locationIds)),
                'moa_shared_ids' => array_values(array_unique($moaShareIds)),
                'row_count' => count($expenses),
                'expenses' => $insertedDetails,
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
            'CREATE_JV_EXPENSES',
            'JV_EXPENSES',
            'moa_jv_expenses',
            (string) $moaId,
            null,
            [
                'moa_id' => $moaId,
                'inserted' => $inserted,
                'inserted_ids' => $insertedIds,
                'location_ids' => array_values(array_unique($locationIds)),
                'moa_shared_ids' => array_values(array_unique($moaShareIds)),
                'row_count' => count($expenses),
            ],
            'JV expense rows created'
        );
    } catch (Throwable $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'error' => 'Server error',
    ]);
}
