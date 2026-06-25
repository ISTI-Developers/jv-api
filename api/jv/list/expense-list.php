<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');

function getTableColumns(PDO $db, string $table): array
{
    $stmt = $db->query("SHOW COLUMNS FROM {$table}");
    $columns = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }

    return $columns;
}

try {
    $db = Database::connect();
    require_auth($db);

    $jvExpenseColumns = getTableColumns($db, 'moa_jv_expenses');
    $allExpenseColumns = getTableColumns($db, 'moa_all_expense');

    $jvDueDateSelect = isset($jvExpenseColumns['due_date']) ? 'A.due_date' : 'NULL';
    $jvDueDateFromSelect = isset($jvExpenseColumns['due_date_from']) ? 'COALESCE(A.due_date_from, ' . $jvDueDateSelect . ')' : $jvDueDateSelect;
    $jvDueDateToSelect = isset($jvExpenseColumns['due_date_to']) ? 'COALESCE(A.due_date_to, ' . $jvDueDateSelect . ')' : $jvDueDateSelect;
    $jvInputSourceSelect = isset($jvExpenseColumns['input_source']) ? "A.input_source" : "'JV'";
    $unaiSourceHashSelect = isset($allExpenseColumns['source_hash']) ? 'source_hash' : 'NULL';

    $stmt = $db->prepare("
        SELECT
            *
        FROM (
            SELECT
                CONCAT(CASE WHEN {$jvInputSourceSelect} = 'UNAI' THEN 'UNAI-MANUAL-' ELSE 'JV-' END, A.id) AS external_key,
                CASE WHEN {$jvInputSourceSelect} = 'UNAI' THEN 'UNAI' ELSE 'JV' END AS source_type,
                {$jvInputSourceSelect} AS input_source,
                A.id AS source_id,
                NULL AS source_hash,
                A.id,
                A.moa_shared_id,
                A.account_no,
                A.user_id,
                A.ref_no,
                '' AS job_number,
                {$jvDueDateSelect} AS due_date,
                {$jvDueDateFromSelect} AS due_date_from,
                {$jvDueDateToSelect} AS due_date_to,
                '' AS structure_id,
                A.payee,
                A.particulars,
                CASE WHEN {$jvInputSourceSelect} = 'UNAI' THEN 0 ELSE A.amount END AS jv_amount,
                CASE WHEN {$jvInputSourceSelect} = 'UNAI' THEN A.amount ELSE 0 END AS un_amount,
                C.group_name
            FROM moa_jv_expenses A
            LEFT OUTER JOIN moa_share B
                ON A.moa_shared_id = B.id
            LEFT OUTER JOIN moa_locations C
                ON B.location_id = C.id
                AND B.moa_id = C.moa_id

            UNION ALL

            SELECT
                CONCAT('UNAI-', id) AS external_key,
                'UNAI' AS source_type,
                'UNAI' AS input_source,
                id AS source_id,
                {$unaiSourceHashSelect} AS source_hash,
                id,
                moa_shared_id,
                account_no,
                user_id,
                transaction_no AS ref_no,
                job_number,
                due_date_from AS due_date,
                due_date_from,
                due_date_to,
                structure_id,
                '' AS payee,
                '' AS particulars,
                0 AS jv_amount,
                amount AS un_amount,
                group_name
            FROM moa_all_expense
        ) X
        ORDER BY due_date_from DESC, ref_no DESC, id DESC
    ");

    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $rows,
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
