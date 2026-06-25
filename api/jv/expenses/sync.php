<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../../helpers/audit.php';
require_once __DIR__ . '/../../../helpers/transaction_log.php';

header('Content-Type: application/json');

try {
    $db = Database::connect();
    $user = require_jv($db);
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

    $stmt = $db->prepare("
        SELECT ms.id, ms.location_id, l.structure_id
        FROM moa_share ms
        INNER JOIN moa_locations l
            ON l.id = ms.location_id
           AND l.moa_id = ms.moa_id
           AND l.soft_deleted = 0
        WHERE ms.moa_id = ?
          AND ms.user_id = ?
    ");
    $stmt->execute([$moaId, $user['id']]);

    $shareRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $shareByLocation = [];
    $structureByLocation = [];
    $allowedShareIds = [];

    foreach ($shareRows as $shareRow) {
        $shareId = (int) $shareRow['id'];
        $locationId = (int) $shareRow['location_id'];

        if (!isset($shareByLocation[$locationId])) {
            $shareByLocation[$locationId] = $shareId;
            $structureByLocation[$locationId] = $shareRow['structure_id'];
        }

        $allowedShareIds[] = $shareId;
    }

    if (empty($allowedShareIds)) {
        throw new Exception('You do not have access to this MOA');
    }

    $placeholders = implode(',', array_fill(0, count($allowedShareIds), '?'));

    $stmt = $db->prepare("
        SELECT
            e.id,
            e.moa_shared_id,
            e.account_no,
            e.user_id,
            e.input_source,
            e.due_date,
            e.ref_no,
            e.payee,
            e.particulars,
            e.amount,
            ms.moa_id,
            ms.location_id,
            l.structure_id
        FROM moa_jv_expenses e
        INNER JOIN moa_share ms
            ON ms.id = e.moa_shared_id
        LEFT JOIN moa_locations l
            ON l.id = ms.location_id
           AND l.moa_id = ms.moa_id
        WHERE e.moa_shared_id IN ($placeholders)
          AND e.user_id = ?
          AND e.input_source = 'JV'
    ");
    $stmt->execute([...$allowedShareIds, $user['id']]);

    $existingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $existingIds = [];
    $existingMap = [];
    $existingDetails = [];

    foreach ($existingRows as $row) {
        $expenseId = (int) $row['id'];
        $existingIds[] = $expenseId;
        $existingMap[$expenseId] = (int) $row['moa_shared_id'];
        $existingDetails[$expenseId] = [
            'id' => $expenseId,
            'moa_id' => (int) $row['moa_id'],
            'moa_shared_id' => (int) $row['moa_shared_id'],
            'location_id' => (int) $row['location_id'],
            'structure_id' => $row['structure_id'],
            'account_no' => $row['account_no'],
            'user_id' => (int) $row['user_id'],
            'input_source' => $row['input_source'],
            'due_date' => $row['due_date'],
            'ref_no' => $row['ref_no'],
            'payee' => $row['payee'],
            'particulars' => $row['particulars'],
            'amount' => $row['amount'] !== null ? (float) $row['amount'] : null,
        ];
    }

    $incomingIds = [];

    $insertStmt = $db->prepare("
        INSERT INTO moa_jv_expenses (
            moa_shared_id,
            account_no,
            user_id,
            input_source,
            due_date,
            ref_no,
            payee,
            particulars,
            amount
        ) VALUES (?, ?, ?, 'JV', ?, ?, ?, ?)
    ");

    $updateStmt = $db->prepare("
        UPDATE moa_jv_expenses
        SET
            moa_shared_id = ?,
            account_no = ?,
            due_date = ?,
            ref_no = ?,
            payee = ?,
            particulars = ?,
            amount = ?
        WHERE id = ?
          AND user_id = ?
          AND input_source = 'JV'
    ");

    $insertedIds = [];
    $updatedIds = [];
    $deletedIds = [];
    $locationIds = [];
    $moaShareIds = [];
    $insertedDetails = [];
    $updatedDetails = [];
    $deletedDetails = [];

    foreach ($expenses as $exp) {
        if (
            !isset($exp['location_id']) ||
            !isset($exp['account_no']) ||
            !isset($exp['amount']) ||
            !isset($exp['due_date']) ||
            !isset($exp['ref_no']) ||
            !isset($exp['payee'])
        ) {
            continue;
        }

        $id = isset($exp['id']) && $exp['id'] !== '' ? (int) $exp['id'] : null;
        $locationId = (int) $exp['location_id'];
        $accountNo = trim((string) $exp['account_no']);
        $amount = (float) $exp['amount'];
        $refNo = trim((string) $exp['ref_no']);
        $payee = trim((string) $exp['payee']);
        $particulars = trim((string) ($exp['particulars'] ?? $exp['name'] ?? ''));
        $dueDate = trim((string) $exp['due_date']);

        if ($locationId <= 0) {
            throw new Exception('Invalid location');
        }

        if (!isset($shareByLocation[$locationId])) {
            throw new Exception("No moa_share found for location ID {$locationId} under this MOA");
        }

        if ($accountNo === '' || $amount <= 0 || $dueDate === '' || $refNo === '' || $payee === '') {
            throw new Exception('Missing required expense fields');
        }

        $moaShareId = $shareByLocation[$locationId];

        if ($id) {
            if (!isset($existingMap[$id])) {
                continue;
            }

            $incomingIds[] = $id;

            $updateStmt->execute([
                $moaShareId,
                $accountNo,
                $dueDate,
                $refNo,
                $payee,
                $particulars !== '' ? $particulars : null,
                $amount,
                $id,
                $user['id'],
            ]);

            $updatedIds[] = $id;
            $updatedDetails[] = [
                'id' => $id,
                'old' => $existingDetails[$id] ?? null,
                'new' => [
                    'id' => $id,
                    'moa_id' => $moaId,
                    'moa_shared_id' => $moaShareId,
                    'location_id' => $locationId,
                    'structure_id' => $structureByLocation[$locationId] ?? null,
                    'account_no' => $accountNo,
                    'user_id' => (int) ($existingDetails[$id]['user_id'] ?? $user['id']),
                    'input_source' => 'JV',
                    'due_date' => $dueDate,
                    'ref_no' => $refNo,
                    'payee' => $payee,
                    'particulars' => $particulars !== '' ? $particulars : null,
                    'amount' => $amount,
                ],
            ];
        } else {
            $insertStmt->execute([
                $moaShareId,
                $accountNo,
                $user['id'],
                $dueDate,
                $refNo,
                $payee,
                $particulars !== '' ? $particulars : null,
                $amount,
            ]);

            $expenseId = (int) $db->lastInsertId();
            $insertedIds[] = $expenseId;
            $insertedDetails[] = [
                'id' => $expenseId,
                'moa_id' => $moaId,
                'moa_shared_id' => $moaShareId,
                'location_id' => $locationId,
                'structure_id' => $structureByLocation[$locationId] ?? null,
                'account_no' => $accountNo,
                'user_id' => (int) $user['id'],
                'input_source' => 'JV',
                'due_date' => $dueDate,
                'ref_no' => $refNo,
                'payee' => $payee,
                'particulars' => $particulars !== '' ? $particulars : null,
                'amount' => $amount,
            ];
        }

        $locationIds[] = $locationId;
        $moaShareIds[] = $moaShareId;
    }

    $toDelete = array_diff($existingIds, $incomingIds);

    if (!empty($toDelete)) {
        $deletePlaceholders = implode(',', array_fill(0, count($toDelete), '?'));

        $deleteStmt = $db->prepare("
            DELETE FROM moa_jv_expenses
            WHERE id IN ($deletePlaceholders)
              AND user_id = ?
              AND input_source = 'JV'
        ");
        $deleteStmt->execute([...array_values($toDelete), $user['id']]);

        $deletedIds = array_values(array_map('intval', $toDelete));
        foreach ($deletedIds as $deletedId) {
            if (isset($existingDetails[$deletedId])) {
                $deletedDetails[] = $existingDetails[$deletedId];
            }
        }
    }

    $db->commit();

    try {
        logTransaction($db, [
            'transaction_type' => 'JV_EXPENSE',
            'moa_id' => $moaId,
            'reference_table' => 'moa_jv_expenses',
            'reference_id' => null,
            'action' => 'SYNCED',
            'status' => 'SUCCESS',
            'description' => 'JV expense rows synced',
            'metadata' => [
                'moa_id' => $moaId,
                'input_source' => 'JV',
                'inserted' => count($insertedIds),
                'updated' => count(array_unique($updatedIds)),
                'deleted' => count($deletedIds),
                'inserted_ids' => $insertedIds,
                'updated_ids' => array_values(array_unique($updatedIds)),
                'deleted_ids' => $deletedIds,
                'location_ids' => array_values(array_unique($locationIds)),
                'moa_shared_ids' => array_values(array_unique($moaShareIds)),
                'submitted_count' => count($expenses),
                'existing_count' => count($existingIds),
                'inserted_rows' => $insertedDetails,
                'updated_rows' => $updatedDetails,
                'deleted_rows' => $deletedDetails,
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
            'SYNC_JV_EXPENSES',
            'JV_EXPENSES',
            'moa_jv_expenses',
            (string) $moaId,
            null,
            [
                'moa_id' => $moaId,
                'input_source' => 'JV',
                'inserted' => count($insertedIds),
                'updated' => count(array_unique($updatedIds)),
                'deleted' => count($deletedIds),
                'inserted_ids' => $insertedIds,
                'updated_ids' => array_values(array_unique($updatedIds)),
                'deleted_ids' => $deletedIds,
                'location_ids' => array_values(array_unique($locationIds)),
                'moa_shared_ids' => array_values(array_unique($moaShareIds)),
                'submitted_count' => count($expenses),
                'existing_count' => count($existingIds),
            ],
            'JV expense rows synced'
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
        'error' => $e->getMessage(),
    ]);
}
