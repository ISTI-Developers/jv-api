<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../../helpers/audit.php';
require_once __DIR__ . '/../../../helpers/transaction_log.php';

header('Content-Type: application/json');

function expenseStringValue(array $row, string $key): string
{
    if (!array_key_exists($key, $row) || $row[$key] === null) {
        return '';
    }

    return trim((string) $row[$key]);
}

function expenseDateValue(array $row, string $key): string
{
    $value = expenseStringValue($row, $key);

    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? $value : date('Y-m-d H:i:s', $timestamp);
}

function expenseAmountValue(array $row, string $key): string
{
    $value = expenseStringValue($row, $key);

    if ($value === '') {
        return '';
    }

    $normalized = str_replace(',', '', $value);

    return is_numeric($normalized) ? number_format((float) $normalized, 2, '.', '') : $value;
}

function generateExpenseSourceHash(array $row): string
{
    $basis = [
        'cCompanyID' => expenseStringValue($row, 'cCompanyID'),
        'cTranNo' => expenseStringValue($row, 'cTranNo'),
        'cAcctNo' => expenseStringValue($row, 'cAcctNo'),
        'cTitle' => expenseStringValue($row, 'cTitle'),
        'cLocation' => expenseStringValue($row, 'cLocation'),
        'cGroupName' => expenseStringValue($row, 'cGroupName'),
        'nAmount' => expenseAmountValue($row, 'nAmount'),
        'dCreateDate' => expenseDateValue($row, 'dCreateDate'),
    ];

    return hash('sha256', json_encode($basis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function normalizeExpenseDate(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? trim($value) : date('Y-m-d', $timestamp);
}

function nullableTrim(array $row, string $key): ?string
{
    $value = expenseStringValue($row, $key);

    return $value === '' ? null : $value;
}

function bindExpenseValue(PDOStatement $stmt, string $name, $value): void
{
    if ($value === null) {
        $stmt->bindValue($name, null, PDO::PARAM_NULL);
    } elseif (is_int($value)) {
        $stmt->bindValue($name, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($name, $value, PDO::PARAM_STR);
    }
}

function getExpenseTableColumns(PDO $db): array
{
    $stmt = $db->query("SHOW COLUMNS FROM moa_all_expense");
    $columns = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }

    return $columns;
}

function onlyExistingExpenseColumns(array $values, array $columns): array
{
    return array_filter($values, function ($column) use ($columns) {
        return isset($columns[$column]);
    }, ARRAY_FILTER_USE_KEY);
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
    $user = require_admin($db);

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

    $expenseColumns = getExpenseTableColumns($db);
    $hasSourceHash = isset($expenseColumns['source_hash']);

    $selectStmt = $db->prepare($hasSourceHash
        ? "SELECT id FROM moa_all_expense WHERE source_hash = ? LIMIT 1"
        : "SELECT id FROM moa_all_expense WHERE transaction_no = ? LIMIT 1"
    );

    $insertColumns = onlyExistingExpenseColumns([
        'moa_shared_id' => null,
        'user_id' => null,
        'invoice_id' => null,
        'account_no' => null,
        'transaction_no' => null,
        'due_date_from' => null,
        'due_date_to' => null,
        'structure_id' => null,
        'lease_contract_id' => null,
        'amount' => null,
        'remarks' => null,
        'group_name' => null,
        'source_hash' => null,
        'source_company_id' => null,
        'source_transaction_no' => null,
        'source_location' => null,
        'source_module' => null,
        'source_category' => null,
        'source_code' => null,
        'source_name' => null,
        'source_payee' => null,
        'source_due_date_from' => null,
        'source_due_date_to' => null,
        'source_structure_id' => null,
        'source_department' => null,
        'source_employee_id' => null,
        'source_employee_name' => null,
        'source_lease_contract_id' => null,
        'source_main_ref' => null,
        'source_ref_type' => null,
        'source_site_owner_name' => null,
        'source_account_no' => null,
        'source_title' => null,
        'source_report_group' => null,
        'source_group_name' => null,
        'source_amount' => null,
        'source_create_date' => null,
        'source_created_at' => null,
    ], $expenseColumns);

    $columnNames = array_keys($insertColumns);
    $insertStmt = $db->prepare(sprintf(
        "INSERT INTO moa_all_expense (%s) VALUES (%s)",
        implode(', ', $columnNames),
        implode(', ', array_map(fn($column) => ':' . $column, $columnNames))
    ));

    $updateColumns = array_values(array_filter($columnNames, fn($column) => $column !== 'transaction_no'));
    $updateStmt = $db->prepare(sprintf(
        "UPDATE moa_all_expense SET %s WHERE id = :id",
        implode(', ', array_map(fn($column) => $column . ' = :' . $column, $updateColumns))
    ));

    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $insertedIds = [];
    $updatedIds = [];
    $transactionNos = [];
    $moaSharedIds = [];
    $referenceNos = [];
    $structureIds = [];
    $sourceHashes = [];
    $amountTotal = 0.0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            $skipped++;
            continue;
        }

        $transactionNo = !empty($row['cTranNo'])
            ? trim((string) $row['cTranNo'])
            : null;

        if ($transactionNo === null || $transactionNo === '') {
            $skipped++;
            continue;
        }

        $sourceHash = generateExpenseSourceHash($row);
        $amount = $row['realized_expense'] ?? null;

        if ($amount === null || $amount === '') {
            $skipped++;
            continue;
        }

        $invoiceId = null;

        $moaSharedId = isset($row['moa_shared_id']) && $row['moa_shared_id'] !== ''
            ? (int) $row['moa_shared_id']
            : null;

        $accountNo = !empty($row['cAcctNo'])
            ? trim((string) $row['cAcctNo'])
            : null;

        $dueDateFrom = normalizeExpenseDate($row['dDueDateFrom'] ?? null);
        $dueDateTo = normalizeExpenseDate($row['dDueDateTo'] ?? null);

        $structureId = !empty($row['cStructureID'])
            ? trim((string) $row['cStructureID'])
            : null;

        $leaseContractId = nullableTrim($row, 'cLeaseContractID') ?? nullableTrim($row, 'cleaseContractID');

        $groupName = !empty($row['cGroupName'])
            ? trim((string) $row['cGroupName'])
            : null;

        $remarks = nullableTrim($row, 'remarks');

        $values = onlyExistingExpenseColumns([
            'moa_shared_id' => $moaSharedId,
            'user_id' => (int) $user['id'],
            'invoice_id' => $invoiceId,
            'account_no' => $accountNo,
            'transaction_no' => $transactionNo,
            'due_date_from' => $dueDateFrom,
            'due_date_to' => $dueDateTo,
            'structure_id' => $structureId,
            'lease_contract_id' => $leaseContractId,
            'amount' => (float) $amount,
            'remarks' => $remarks,
            'group_name' => $groupName,
            'source_hash' => $sourceHash,
            'source_company_id' => nullableTrim($row, 'cCompanyID'),
            'source_transaction_no' => nullableTrim($row, 'cTranNo'),
            'source_location' => nullableTrim($row, 'cLocation'),
            'source_module' => nullableTrim($row, 'cModule'),
            'source_category' => nullableTrim($row, 'cCategory'),
            'source_code' => nullableTrim($row, 'cCode'),
            'source_name' => nullableTrim($row, 'cName'),
            'source_payee' => nullableTrim($row, 'cName'),
            'source_due_date_from' => $dueDateFrom,
            'source_due_date_to' => $dueDateTo,
            'source_structure_id' => nullableTrim($row, 'cStructureID'),
            'source_department' => nullableTrim($row, 'cDepartment'),
            'source_employee_id' => nullableTrim($row, 'cEmpID'),
            'source_employee_name' => nullableTrim($row, 'cEmpName'),
            'source_lease_contract_id' => $leaseContractId,
            'source_main_ref' => nullableTrim($row, 'cMainRef'),
            'source_ref_type' => nullableTrim($row, 'cRefType'),
            'source_site_owner_name' => nullableTrim($row, 'cSiteOwnerName'),
            'source_account_no' => nullableTrim($row, 'cAcctNo'),
            'source_title' => nullableTrim($row, 'cTitle'),
            'source_report_group' => nullableTrim($row, 'cReportGroup'),
            'source_group_name' => nullableTrim($row, 'cGroupName'),
            'source_amount' => expenseAmountValue($row, 'nAmount') !== '' ? expenseAmountValue($row, 'nAmount') : null,
            'source_create_date' => expenseDateValue($row, 'dCreateDate') !== '' ? expenseDateValue($row, 'dCreateDate') : null,
            'source_created_at' => expenseDateValue($row, 'dCreateDate') !== '' ? expenseDateValue($row, 'dCreateDate') : null,
        ], $expenseColumns);

        $selectStmt->execute([$hasSourceHash ? $sourceHash : $transactionNo]);
        $existing = $selectStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $expenseId = (int) $existing['id'];
            $updateStmt->bindValue(':id', (int) $existing['id'], PDO::PARAM_INT);

            foreach ($updateColumns as $column) {
                bindExpenseValue($updateStmt, ':' . $column, $values[$column] ?? null);
            }

            $updateStmt->execute();

            $updated++;
            $updatedIds[] = $expenseId;
        } else {
            foreach ($columnNames as $column) {
                bindExpenseValue($insertStmt, ':' . $column, $values[$column] ?? null);
            }

            $insertStmt->execute();

            $inserted++;
            $insertedIds[] = (int) $db->lastInsertId();
        }

        $transactionNos[] = $transactionNo;
        $referenceNos[] = $transactionNo;
        $sourceHashes[] = $sourceHash;

        if ($moaSharedId !== null) {
            $moaSharedIds[] = $moaSharedId;
        }

        if ($structureId !== null) {
            $structureIds[] = $structureId;
        }

        $amountTotal += (float) $amount;
    }

    $db->commit();

    try {
        logTransaction($db, [
            'transaction_type' => 'EXPENSE',
            'reference_table' => 'moa_all_expense',
            'reference_id' => count($insertedIds) + count($updatedIds) === 1
                ? (string) (($insertedIds[0] ?? $updatedIds[0]))
                : null,
            'reference_no' => count(array_unique($referenceNos)) === 1 ? $referenceNos[0] : null,
            'action' => 'IMPORTED',
            'status' => 'SUCCESS',
            'description' => 'Realized expense rows imported',
            'amount' => $amountTotal,
            'metadata' => [
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'inserted_ids' => $insertedIds,
                'updated_ids' => $updatedIds,
                'transaction_nos' => array_values(array_unique($transactionNos)),
                'source_hashes' => array_values(array_unique($sourceHashes)),
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
            'SAVE_REALIZED_EXPENSES',
            'EXPENSES',
            'moa_all_expense',
            null,
            null,
            [
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'inserted_ids' => $insertedIds,
                'updated_ids' => $updatedIds,
                'transaction_nos' => array_values(array_unique($transactionNos)),
                'source_hashes' => array_values(array_unique($sourceHashes)),
                'moa_shared_ids' => array_values(array_unique($moaSharedIds)),
                'row_count' => count($rows),
            ],
            'Realized expense rows saved'
        );
    } catch (Throwable $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Expense data saved successfully',
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => $skipped,
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
