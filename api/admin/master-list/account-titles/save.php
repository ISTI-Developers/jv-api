<?php

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../middleware/auth.php';
require_once __DIR__ . '/../../../../helpers/audit.php';

header('Content-Type: application/json');

try {
    $db = Database::connect();
    $admin = require_admin($db);

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input) || !isset($input['account_titles']) || !is_array($input['account_titles'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid request body',
        ]);
        exit;
    }

    $enabledAccountTitles = [];

    foreach ($input['account_titles'] as $item) {
        if (!is_array($item)) {
            continue;
        }

        $accountNo = trim((string) ($item['account_no'] ?? ''));
        $accountTitle = trim((string) ($item['account_title'] ?? ''));

        if ($accountNo === '' || $accountTitle === '') {
            continue;
        }

        $enabledAccountTitles[$accountNo] = [
            'account_no' => $accountNo,
            'account_title' => $accountTitle,
        ];
    }

    $enabledAccountTitles = array_values($enabledAccountTitles);

    $oldStmt = $db->query("
        SELECT account_no, account_title, is_enabled
        FROM master_account_titles
        ORDER BY account_title ASC
    ");
    $oldAccountTitles = $oldStmt->fetchAll(PDO::FETCH_ASSOC);

    $db->beginTransaction();

    $db->exec("UPDATE master_account_titles SET is_enabled = 0");

    $upsert = $db->prepare("
        INSERT INTO master_account_titles (
            account_no,
            account_title,
            is_enabled,
            created_at,
            updated_at
        )
        VALUES (?, ?, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            account_title = VALUES(account_title),
            is_enabled = VALUES(is_enabled),
            updated_at = NOW()
    ");

    foreach ($enabledAccountTitles as $accountTitle) {
        $upsert->execute([
            $accountTitle['account_no'],
            $accountTitle['account_title'],
        ]);
    }

    $db->commit();

    try {
        logAudit(
            $db,
            (int) $admin['id'],
            'UPDATE_MASTER_ACCOUNT_TITLES',
            'MASTER_LIST',
            'master_account_titles',
            null,
            [
                'enabled_count' => count(array_filter($oldAccountTitles, function ($item) {
                    return (int) $item['is_enabled'] === 1;
                })),
                'records' => $oldAccountTitles,
            ],
            [
                'enabled_count' => count($enabledAccountTitles),
                'records' => $enabledAccountTitles,
            ],
            'Admin updated master account title allowlist'
        );
    } catch (Throwable $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Account titles updated successfully',
        'enabled_count' => count($enabledAccountTitles),
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
    ]);
}
