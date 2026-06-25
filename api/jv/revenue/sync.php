<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../../helpers/audit.php';
require_once __DIR__ . '/../../../helpers/transaction_log.php';

header('Content-Type: application/json');

function jvManualRevenueString(array $row, string $key): string
{
    if (!array_key_exists($key, $row) || $row[$key] === null) {
        return '';
    }

    return trim((string) $row[$key]);
}

function jvManualRevenueDate(array $row, string $key): string
{
    $value = jvManualRevenueString($row, $key);

    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? $value : date('Y-m-d', $timestamp);
}

function jvManualRevenueRow(array $row, array $shareByLocation): array
{
    $id = isset($row['id']) && $row['id'] !== '' ? (int) $row['id'] : null;
    $locationId = isset($row['location_id']) && $row['location_id'] !== ''
        ? (int) $row['location_id']
        : 0;
    $accountNo = jvManualRevenueString($row, 'account_no');
    $amount = isset($row['amount']) ? (float) $row['amount'] : 0.0;
    $dueDate = jvManualRevenueDate($row, 'due_date');
    $dueDateFrom = jvManualRevenueDate($row, 'due_date_from');
    $dueDateTo = jvManualRevenueDate($row, 'due_date_to');
    $refNo = jvManualRevenueString($row, 'ref_no');
    $payee = jvManualRevenueString($row, 'payee');
    $particulars = jvManualRevenueString($row, 'particulars');

    if ($locationId <= 0) {
        throw new InvalidArgumentException('Invalid location');
    }

    if (!isset($shareByLocation[$locationId])) {
        throw new InvalidArgumentException("No moa_share found for location ID {$locationId} under this MOA");
    }

    if ($accountNo === '' || $amount <= 0 || $dueDate === '' || $refNo === '' || $payee === '') {
        throw new InvalidArgumentException('Missing required manual revenue fields');
    }

    return [
        'id' => $id,
        'moa_shared_id' => $shareByLocation[$locationId]['moa_shared_id'],
        'location_id' => $locationId,
        'structure_id' => $shareByLocation[$locationId]['structure_id'],
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
    $user = require_jv($db);
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
        INNER JOIN moa m
            ON m.id = ms.moa_id
           AND m.soft_deleted = 0
        WHERE ms.moa_id = ?
          AND ms.user_id = ?
    ");
    $shareStmt->execute([$moaId, $user['id']]);
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
        throw new InvalidArgumentException('You do not have access to this MOA');
    }

    $allowedShareIds = array_values(array_map(function ($share) {
        return (int) $share['moa_shared_id'];
    }, $shareByLocation));
    $sharePlaceholders = implode(',', array_fill(0, count($allowedShareIds), '?'));

    $existingStmt = $db->prepare("
        SELECT
            r.id,
            r.moa_shared_id,
            r.account_no,
            r.user_id,
            r.input_source,
            r.due_date,
            r.due_date_from,
            r.due_date_to,
            r.ref_no,
            r.payee,
            r.particulars,
            r.amount,
            r.date_created,
            ms.location_id,
            l.structure_id
        FROM moa_unai_revenue r
        INNER JOIN moa_share ms
            ON ms.id = r.moa_shared_id
        LEFT JOIN moa_locations l
            ON l.id = ms.location_id
           AND l.moa_id = ms.moa_id
        WHERE r.moa_shared_id IN ($sharePlaceholders)
          AND r.user_id = ?
          AND r.input_source = 'JV'
    ");
    $existingStmt->execute([...$allowedShareIds, $user['id']]);

    $existingById = [];
    foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existingById[(int) $row['id']] = $row;
    }

    $insertStmt = $db->prepare("
        INSERT INTO moa_unai_revenue (
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
        ) VALUES (?, ?, ?, 'JV', ?, ?, ?, ?, ?, ?, ?)
    ");

    $updateStmt = $db->prepare("
        UPDATE moa_unai_revenue
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
          AND user_id = ?
          AND input_source = 'JV'
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
            throw new InvalidArgumentException('Invalid manual revenue row');
        }

        $revenue = jvManualRevenueRow($row, $shareByLocation);
        $moaSharedIds[] = $revenue['moa_shared_id'];
        $locationIds[] = $revenue['location_id'];

        if ($revenue['id'] !== null) {
            if (!isset($existingById[$revenue['id']])) {
                throw new InvalidArgumentException('Manual revenue row does not belong to this MOA');
            }

            $updateStmt->execute([
                $revenue['moa_shared_id'],
                $revenue['account_no'],
                $revenue['due_date'],
                $revenue['due_date_from'],
                $revenue['due_date_to'],
                $revenue['ref_no'],
                $revenue['payee'],
                $revenue['particulars'],
                $revenue['amount'],
                $revenue['id'],
                ...$allowedShareIds,
                $user['id'],
            ]);

            $updatedIds[] = $revenue['id'];
            $upsertedRows[] = $revenue + [
                'user_id' => (int) $user['id'],
                'input_source' => 'JV',
            ];
        } else {
            $insertStmt->execute([
                $revenue['moa_shared_id'],
                $revenue['account_no'],
                (int) $user['id'],
                $revenue['due_date'],
                $revenue['due_date_from'],
                $revenue['due_date_to'],
                $revenue['ref_no'],
                $revenue['payee'],
                $revenue['particulars'],
                $revenue['amount'],
            ]);

            $revenue['id'] = (int) $db->lastInsertId();
            $insertedIds[] = $revenue['id'];
            $upsertedRows[] = $revenue + [
                'user_id' => (int) $user['id'],
                'input_source' => 'JV',
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
                DELETE FROM moa_unai_revenue
                WHERE id IN ($deletePlaceholders)
                  AND moa_shared_id IN ($sharePlaceholders)
                  AND user_id = ?
                  AND input_source = 'JV'
            ");
            $deleteStmt->execute([...$validDeleteIds, ...$allowedShareIds, $user['id']]);
            $deletedIds = $validDeleteIds;
        }
    }

    $db->commit();

    try {
        logTransaction($db, [
            'transaction_type' => 'JV_MANUAL_REVENUE',
            'moa_id' => $moaId,
            'reference_table' => 'moa_unai_revenue',
            'reference_id' => count($insertedIds) + count($updatedIds) + count($deletedIds) === 1
                ? (string) (($insertedIds[0] ?? $updatedIds[0] ?? $deletedIds[0]))
                : null,
            'action' => 'jv_manual_revenue_sync',
            'status' => 'SUCCESS',
            'description' => 'JV manual revenue rows synced',
            'metadata' => [
                'moa_id' => $moaId,
                'input_source' => 'JV',
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
            'jv_manual_revenue_sync',
            'REVENUE',
            'moa_unai_revenue',
            (string) $moaId,
            null,
            [
                'moa_id' => $moaId,
                'input_source' => 'JV',
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
            'JV manual revenue rows synced'
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
