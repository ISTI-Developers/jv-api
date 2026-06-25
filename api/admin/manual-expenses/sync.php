<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../../helpers/audit.php';
require_once __DIR__ . '/../../../helpers/transaction_log.php';

header('Content-Type: application/json');

function manualExpenseString(array $row, string $key): string
{
    if (!array_key_exists($key, $row) || $row[$key] === null) {
        return '';
    }

    return trim((string) $row[$key]);
}

function manualExpenseDate(array $row, string $key): string
{
    $value = manualExpenseString($row, $key);

    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? $value : date('Y-m-d', $timestamp);
}

function manualExpenseRow(array $row, array $shareById): array
{
    $id = isset($row['id']) && $row['id'] !== '' ? (int) $row['id'] : null;
    $locationId = isset($row['location_id']) && $row['location_id'] !== ''
        ? (int) $row['location_id']
        : 0;
    $accountNo = manualExpenseString($row, 'account_no');
    $amount = isset($row['amount']) ? (float) $row['amount'] : 0.0;
    $dueDate = manualExpenseDate($row, 'due_date');
    $dueDateFrom = manualExpenseDate($row, 'due_date_from');
    $dueDateTo = manualExpenseDate($row, 'due_date_to');
    $refNo = manualExpenseString($row, 'ref_no');
    $payee = manualExpenseString($row, 'payee');
    $particulars = manualExpenseString($row, 'particulars');

    if (
        $locationId <= 0 ||
        !isset($shareById[$locationId]) ||
        $accountNo === '' ||
        $amount <= 0 ||
        $dueDate === '' ||
        $refNo === '' ||
        $payee === ''
    ) {
        throw new InvalidArgumentException('Missing required manual expense fields');
    }

    return [
        'id' => $id,
        'moa_shared_id' => $shareById[$locationId]['moa_shared_id'],
        'location_id' => $locationId,
        'structure_id' => $shareById[$locationId]['structure_id'],
        'account_no' => $accountNo,
        'amount' => $amount,
        'due_date' => $dueDate,
        'due_date_from' => $dueDateFrom !== '' ? $dueDateFrom : null,
        'due_date_to' => $dueDateTo !== '' ? $dueDateTo : null,
        'ref_no' => $refNo,
        'payee' => $payee,
        'particulars' => $particulars !== '' ? $particulars : null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = Database::connect();
    $user = require_admin($db);
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
        exit;
    }

    $moaId = isset($input['moa_id']) ? (int) $input['moa_id'] : 0;
    $upserts = $input['upserts'] ?? [];
    $deletes = $input['deletes'] ?? [];

    if ($moaId <= 0 || !is_array($upserts) || !is_array($deletes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid payload']);
        exit;
    }

    $db->beginTransaction();

    $shareStmt = $db->prepare("
        SELECT
            ms.id,
            ms.location_id,
            l.structure_id,
            l.group_name
        FROM moa_share ms
        INNER JOIN moa_locations l
            ON l.id = ms.location_id
           AND l.moa_id = ms.moa_id
           AND l.soft_deleted = 0
        WHERE ms.moa_id = ?
    ");
    $shareStmt->execute([$moaId]);
    $shareRows = $shareStmt->fetchAll(PDO::FETCH_ASSOC);

    $shareByLocation = [];
    foreach ($shareRows as $shareRow) {
        $locationId = (int) $shareRow['location_id'];

        if (isset($shareByLocation[$locationId])) {
            continue;
        }

        $shareByLocation[$locationId] = [
            'moa_shared_id' => (int) $shareRow['id'],
            'structure_id' => $shareRow['structure_id'],
            'group_name' => $shareRow['group_name'],
        ];
    }

    if (empty($shareByLocation)) {
        throw new InvalidArgumentException('MOA has no valid shared locations');
    }

    $allowedShareIds = array_values(array_map(function ($share) {
        return (int) $share['moa_shared_id'];
    }, $shareByLocation));
    $sharePlaceholders = implode(',', array_fill(0, count($allowedShareIds), '?'));

    $existingStmt = $db->prepare("
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
            ms.location_id,
            l.structure_id
        FROM moa_jv_expenses e
        INNER JOIN moa_share ms
            ON ms.id = e.moa_shared_id
        LEFT JOIN moa_locations l
            ON l.id = ms.location_id
           AND l.moa_id = ms.moa_id
        WHERE e.moa_shared_id IN ($sharePlaceholders)
          AND e.input_source = 'UNAI'
    ");
    $existingStmt->execute($allowedShareIds);

    $existingById = [];
    foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existingById[(int) $row['id']] = $row;
    }

    $insertStmt = $db->prepare("
        INSERT INTO moa_jv_expenses (
            moa_shared_id,
            account_no,
            user_id,
            input_source,
            due_date,
            due_date_from,
            due_date_to,
            ref_no,
            payee,
            particulars,
            amount
        ) VALUES (?, ?, ?, 'UNAI', ?, ?, ?, ?, ?, ?, ?)
    ");

    $updateStmt = $db->prepare("
        UPDATE moa_jv_expenses
        SET
            moa_shared_id = ?,
            account_no = ?,
            due_date = ?,
            due_date_from = ?,
            due_date_to = ?,
            ref_no = ?,
            payee = ?,
            particulars = ?,
            amount = ?
        WHERE id = ?
          AND moa_shared_id IN ($sharePlaceholders)
          AND input_source = 'UNAI'
    ");

    $insertedIds = [];
    $updatedIds = [];
    $deletedIds = [];
    $skippedDeletes = [];
    $upsertedRows = [];
    $deletedRows = [];
    $moaSharedIds = [];
    $locationIds = [];

    foreach ($upserts as $row) {
        if (!is_array($row)) {
            throw new InvalidArgumentException('Invalid manual expense row');
        }

        $expense = manualExpenseRow($row, $shareByLocation);
        $moaSharedIds[] = $expense['moa_shared_id'];
        $locationIds[] = $expense['location_id'];

        if ($expense['id'] !== null) {
            if (!isset($existingById[$expense['id']])) {
                throw new InvalidArgumentException('Manual expense row does not belong to this MOA');
            }

            $updateStmt->execute([
                $expense['moa_shared_id'],
                $expense['account_no'],
                $expense['due_date'],
                $expense['due_date_from'],
                $expense['due_date_to'],
                $expense['ref_no'],
                $expense['payee'],
                $expense['particulars'],
                $expense['amount'],
                $expense['id'],
                ...$allowedShareIds,
            ]);

            $updatedIds[] = $expense['id'];
            $upsertedRows[] = $expense + ['input_source' => 'UNAI'];
        } else {
            $insertStmt->execute([
                $expense['moa_shared_id'],
                $expense['account_no'],
                (int) $user['id'],
                $expense['due_date'],
                $expense['due_date_from'],
                $expense['due_date_to'],
                $expense['ref_no'],
                $expense['payee'],
                $expense['particulars'],
                $expense['amount'],
            ]);

            $expense['id'] = (int) $db->lastInsertId();
            $insertedIds[] = $expense['id'];
            $upsertedRows[] = $expense + [
                'user_id' => (int) $user['id'],
                'input_source' => 'UNAI',
            ];
        }
    }

    $deleteIds = array_values(array_unique(array_filter(array_map(function ($value) {
        return is_numeric($value) ? (int) $value : 0;
    }, $deletes), fn($value) => $value > 0)));

    if (!empty($deleteIds)) {
        $validDeleteIds = [];

        foreach ($deleteIds as $deleteId) {
            if (isset($existingById[$deleteId])) {
                $validDeleteIds[] = $deleteId;
                $deletedRows[] = $existingById[$deleteId];
            } else {
                $skippedDeletes[] = $deleteId;
            }
        }

        if (!empty($validDeleteIds)) {
            $deletePlaceholders = implode(',', array_fill(0, count($validDeleteIds), '?'));
            $deleteStmt = $db->prepare("
                DELETE FROM moa_jv_expenses
                WHERE id IN ($deletePlaceholders)
                  AND moa_shared_id IN ($sharePlaceholders)
                  AND input_source = 'UNAI'
            ");
            $deleteStmt->execute([...$validDeleteIds, ...$allowedShareIds]);
            $deletedIds = $validDeleteIds;
        }
    }

    $db->commit();

    try {
        logTransaction($db, [
            'transaction_type' => 'UNAI_MANUAL_EXPENSE',
            'moa_id' => $moaId,
            'reference_table' => 'moa_jv_expenses',
            'reference_id' => count($insertedIds) + count($updatedIds) + count($deletedIds) === 1
                ? (string) (($insertedIds[0] ?? $updatedIds[0] ?? $deletedIds[0]))
                : null,
            'action' => 'SYNCED',
            'status' => 'SUCCESS',
            'description' => 'UNAI manual expense rows synced',
            'metadata' => [
                'moa_id' => $moaId,
                'input_source' => 'UNAI',
                'inserted' => count($insertedIds),
                'updated' => count(array_unique($updatedIds)),
                'deleted' => count($deletedIds),
                'skipped_deletes' => $skippedDeletes,
                'inserted_ids' => $insertedIds,
                'updated_ids' => array_values(array_unique($updatedIds)),
                'deleted_ids' => $deletedIds,
                'moa_shared_ids' => array_values(array_unique($moaSharedIds)),
                'location_ids' => array_values(array_unique($locationIds)),
                'submitted_upserts' => count($upserts),
                'submitted_deletes' => count($deletes),
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
            'SYNC_UNAI_MANUAL_EXPENSES',
            'EXPENSES',
            'moa_jv_expenses',
            (string) $moaId,
            null,
            [
                'moa_id' => $moaId,
                'input_source' => 'UNAI',
                'inserted' => count($insertedIds),
                'updated' => count(array_unique($updatedIds)),
                'deleted' => count($deletedIds),
                'skipped_deletes' => $skippedDeletes,
                'inserted_ids' => $insertedIds,
                'updated_ids' => array_values(array_unique($updatedIds)),
                'deleted_ids' => $deletedIds,
                'moa_shared_ids' => array_values(array_unique($moaSharedIds)),
                'submitted_upserts' => count($upserts),
                'submitted_deletes' => count($deletes),
            ],
            'UNAI manual expense rows synced'
        );
    } catch (Throwable $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'inserted' => count($insertedIds),
        'updated' => count(array_unique($updatedIds)),
        'deleted' => count($deletedIds),
        'skipped_deletes' => $skippedDeletes,
        'upserted_rows' => $upsertedRows,
        'deleted_rows' => $deletedRows,
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
