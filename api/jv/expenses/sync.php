<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

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
    $isAdmin = in_array((int) $user['role_id'], [1, 2], true);

    if ($moaId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid MOA ID']);
        exit;
    }

    $db->beginTransaction();

    if ($isAdmin) {
        $stmt = $db->prepare("
            SELECT ms.id, ms.location_id
            FROM moa_share ms
            INNER JOIN moa_locations l
                ON l.id = ms.location_id
               AND l.moa_id = ms.moa_id
               AND l.soft_deleted = 0
            WHERE ms.moa_id = ?
        ");
        $stmt->execute([$moaId]);
    } else {
        $stmt = $db->prepare("
            SELECT ms.id, ms.location_id
            FROM moa_share ms
            INNER JOIN moa_locations l
                ON l.id = ms.location_id
               AND l.moa_id = ms.moa_id
               AND l.soft_deleted = 0
            WHERE ms.moa_id = ?
              AND ms.user_id = ?
        ");
        $stmt->execute([$moaId, $user['id']]);
    }

    $shareRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $shareByLocation = [];
    $allowedShareIds = [];

    foreach ($shareRows as $shareRow) {
        $shareId = (int) $shareRow['id'];
        $locationId = (int) $shareRow['location_id'];

        if (!isset($shareByLocation[$locationId])) {
            $shareByLocation[$locationId] = $shareId;
        }

        $allowedShareIds[] = $shareId;
    }

    if (empty($allowedShareIds)) {
        throw new Exception('You do not have access to this MOA');
    }

    $placeholders = implode(',', array_fill(0, count($allowedShareIds), '?'));

    if ($isAdmin) {
        $stmt = $db->prepare("
            SELECT id, moa_share_id
            FROM moa_jv_expenses
            WHERE moa_share_id IN ($placeholders)
        ");
        $stmt->execute($allowedShareIds);
    } else {
        $stmt = $db->prepare("
            SELECT id, moa_share_id
            FROM moa_jv_expenses
            WHERE moa_share_id IN ($placeholders)
              AND user_id = ?
        ");
        $stmt->execute([...$allowedShareIds, $user['id']]);
    }

    $existingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $existingIds = [];
    $existingMap = [];

    foreach ($existingRows as $row) {
        $expenseId = (int) $row['id'];
        $existingIds[] = $expenseId;
        $existingMap[$expenseId] = (int) $row['moa_share_id'];
    }

    $incomingIds = [];

    $insertStmt = $db->prepare("
        INSERT INTO moa_jv_expenses (
            moa_share_id,
            account_no,
            user_id,
            due_date_from,
            due_date_to,
            ref_no,
            payee,
            particulars,
            amount
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if ($isAdmin) {
        $updateStmt = $db->prepare("
            UPDATE moa_jv_expenses
            SET
                moa_share_id = ?,
                account_no = ?,
                due_date_from = ?,
                due_date_to = ?,
                ref_no = ?,
                payee = ?,
                particulars = ?,
                amount = ?
            WHERE id = ?
        ");
    } else {
        $updateStmt = $db->prepare("
            UPDATE moa_jv_expenses
            SET
                moa_share_id = ?,
                account_no = ?,
                due_date_from = ?,
                due_date_to = ?,
                ref_no = ?,
                payee = ?,
                particulars = ?,
                amount = ?
            WHERE id = ?
              AND user_id = ?
        ");
    }

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

        $id = isset($exp['id']) && $exp['id'] !== '' ? (int) $exp['id'] : null;
        $locationId = (int) $exp['location_id'];
        $accountNo = trim((string) $exp['account_no']);
        $amount = (float) $exp['amount'];
        $refNo = trim((string) $exp['ref_no']);
        $payee = trim((string) $exp['payee']);
        $particulars = trim((string) ($exp['particulars'] ?? $exp['name'] ?? ''));
        $dueDateFrom = !empty($exp['due_date_from']) ? $exp['due_date_from'] : (!empty($exp['date']) ? $exp['date'] : null);
        $dueDateTo = !empty($exp['due_date_to']) ? $exp['due_date_to'] : null;

        if ($locationId <= 0) {
            throw new Exception('Invalid location');
        }

        if (!isset($shareByLocation[$locationId])) {
            throw new Exception("No moa_share found for location ID {$locationId} under this MOA");
        }

        if ($accountNo === '' || $amount <= 0 || $refNo === '' || $payee === '') {
            throw new Exception('Missing required expense fields');
        }

        $moaShareId = $shareByLocation[$locationId];

        if ($id) {
            if (!isset($existingMap[$id])) {
                continue;
            }

            $incomingIds[] = $id;

            if ($isAdmin) {
                $updateStmt->execute([
                    $moaShareId,
                    $accountNo,
                    $dueDateFrom,
                    $dueDateTo,
                    $refNo,
                    $payee,
                    $particulars !== '' ? $particulars : null,
                    $amount,
                    $id,
                ]);
            } else {
                $updateStmt->execute([
                    $moaShareId,
                    $accountNo,
                    $dueDateFrom,
                    $dueDateTo,
                    $refNo,
                    $payee,
                    $particulars !== '' ? $particulars : null,
                    $amount,
                    $id,
                    $user['id'],
                ]);
            }
        } else {
            $insertStmt->execute([
                $moaShareId,
                $accountNo,
                $user['id'],
                $dueDateFrom,
                $dueDateTo,
                $refNo,
                $payee,
                $particulars !== '' ? $particulars : null,
                $amount,
            ]);
        }
    }

    $toDelete = array_diff($existingIds, $incomingIds);

    if (!empty($toDelete)) {
        $deletePlaceholders = implode(',', array_fill(0, count($toDelete), '?'));

        if ($isAdmin) {
            $deleteStmt = $db->prepare("
                DELETE FROM moa_jv_expenses
                WHERE id IN ($deletePlaceholders)
            ");
            $deleteStmt->execute(array_values($toDelete));
        } else {
            $deleteStmt = $db->prepare("
                DELETE FROM moa_jv_expenses
                WHERE id IN ($deletePlaceholders)
                  AND user_id = ?
            ");
            $deleteStmt->execute([...array_values($toDelete), $user['id']]);
        }
    }

    $db->commit();

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
