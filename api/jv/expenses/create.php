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

    if ($moaId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid MOA ID']);
        exit;
    }

    $db->beginTransaction();

    $shareStmt = $db->prepare("
        SELECT id, location_id
        FROM moa_share
        WHERE moa_id = ?
          AND user_id = ?
    ");
    $shareStmt->execute([$moaId, $user['id']]);
    $shareRows = $shareStmt->fetchAll(PDO::FETCH_ASSOC);

    $shareByLocation = [];
    foreach ($shareRows as $shareRow) {
        $shareByLocation[(int) $shareRow['location_id']] = (int) $shareRow['id'];
    }

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
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

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
        'error' => 'Server error',
    ]);
}
