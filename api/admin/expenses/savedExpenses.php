<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');

function savedExpenseStringValue(array $row, string $key): string
{
    if (!array_key_exists($key, $row) || $row[$key] === null) {
        return '';
    }

    return trim((string) $row[$key]);
}

function savedExpenseDateValue(array $row, string $key): string
{
    $value = savedExpenseStringValue($row, $key);

    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? $value : date('Y-m-d H:i:s', $timestamp);
}

function savedExpenseAmountValue(array $row, string $key): string
{
    $value = savedExpenseStringValue($row, $key);

    if ($value === '') {
        return '';
    }

    $normalized = str_replace(',', '', $value);

    return is_numeric($normalized) ? number_format((float) $normalized, 2, '.', '') : $value;
}

function generateSavedExpenseSourceHash(array $row): string
{
    $basis = [
        'cCompanyID' => savedExpenseStringValue($row, 'cCompanyID'),
        'cTranNo' => savedExpenseStringValue($row, 'cTranNo'),
        'cAcctNo' => savedExpenseStringValue($row, 'cAcctNo'),
        'cTitle' => savedExpenseStringValue($row, 'cTitle'),
        'cLocation' => savedExpenseStringValue($row, 'cLocation'),
        'cGroupName' => savedExpenseStringValue($row, 'cGroupName'),
        'nAmount' => savedExpenseAmountValue($row, 'nAmount'),
        'dCreateDate' => savedExpenseDateValue($row, 'dCreateDate'),
    ];

    return hash('sha256', json_encode($basis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function getSavedExpenseTableColumns(PDO $db): array
{
    $stmt = $db->query("SHOW COLUMNS FROM moa_all_expense");
    $columns = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }

    return $columns;
}

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
    require_auth($db);

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON payload',
        ]);
        exit;
    }

    $expenseColumns = getSavedExpenseTableColumns($db);
    $hasSourceHash = isset($expenseColumns['source_hash']);

    $sourceHashes = $input['source_hashes'] ?? null;

    if ($sourceHashes === null && isset($input['source_hash'])) {
        $sourceHashes = is_array($input['source_hash'])
            ? $input['source_hash']
            : [$input['source_hash']];
    }

    if ($sourceHashes === null && isset($input['rows']) && is_array($input['rows'])) {
        $sourceHashes = [];

        foreach ($input['rows'] as $row) {
            if (is_array($row)) {
                $sourceHashes[] = generateSavedExpenseSourceHash($row);
            }
        }
    }

    $transactionNos = $input['transaction_ids'] ?? $input['transaction_nos'] ?? null;
    $lookupBySourceHash = $hasSourceHash && is_array($sourceHashes) && !empty($sourceHashes);
    $lookupValues = $lookupBySourceHash ? $sourceHashes : $transactionNos;

    if (!is_array($lookupValues) || empty($lookupValues)) {
        echo json_encode([
            'success' => true,
            'data' => [],
        ]);
        exit;
    }

    $lookupValues = array_values(array_unique(array_filter(array_map(function ($value) {
        return trim((string) $value);
    }, $lookupValues))));

    if (empty($lookupValues)) {
        echo json_encode([
            'success' => true,
            'data' => [],
        ]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($lookupValues), '?'));
    $sourceHashSelect = $hasSourceHash ? 'r.source_hash,' : 'NULL AS source_hash,';
    $whereColumn = $lookupBySourceHash ? 'r.source_hash' : 'r.transaction_no';

    $stmt = $db->prepare("
        SELECT
            r.id,
            $sourceHashSelect
            r.transaction_no,
            r.amount,
            r.user_id,
            r.date_created,
            u.email,
            up.first_name,
            up.last_name,
            up.company_name
        FROM moa_all_expense r
        LEFT JOIN users u
            ON u.id = r.user_id
        LEFT JOIN user_profiles up
            ON up.user_id = u.id
        WHERE $whereColumn IN ($placeholders)
    ");

    $stmt->execute($lookupValues);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $savedExpenses = [];

    foreach ($rows as $row) {
        $transactionNo = trim((string) $row['transaction_no']);
        $sourceHash = $row['source_hash'] !== null ? trim((string) $row['source_hash']) : null;
        $key = $lookupBySourceHash && $sourceHash !== null && $sourceHash !== ''
            ? $sourceHash
            : $transactionNo;

        $savedExpenses[$key] = [
            'id' => (int) $row['id'],
            'source_hash' => $sourceHash,
            'transaction_no' => $transactionNo,
            'amount' => $row['amount'] !== null ? (float) $row['amount'] : null,
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'date_created' => $row['date_created'],
            'user' => [
                'email' => $row['email'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'company_name' => $row['company_name'],
            ],
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $savedExpenses,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
