<?php

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../middleware/auth.php';
require_once __DIR__ . '/../../../../helpers/account_titles.php';

header('Content-Type: application/json');

try {
    $db = Database::connect();
    require_admin($db);

    try {
        $externalAccountTitles = fetchExternalAccountTitles();
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to fetch external account titles',
        ]);
        exit;
    }

    $stmt = $db->query("
        SELECT account_no, is_enabled
        FROM master_account_titles
    ");

    $savedByAccountNo = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $savedByAccountNo[(string) $row['account_no']] = (int) $row['is_enabled'];
    }

    $data = array_map(function ($accountTitle) use ($savedByAccountNo) {
        $accountNo = $accountTitle['account_no'];
        $saved = array_key_exists($accountNo, $savedByAccountNo);

        return [
            'account_no' => $accountNo,
            'account_title' => $accountTitle['account_title'],
            'is_enabled' => $saved ? $savedByAccountNo[$accountNo] : 0,
            'saved' => $saved,
        ];
    }, $externalAccountTitles);

    usort($data, function ($a, $b) {
        return strcasecmp($a['account_title'], $b['account_title']);
    });

    echo json_encode([
        'success' => true,
        'data' => $data,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
    ]);
}
