<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json');

try {
    $db = Database::connect();
    require_auth($db);

    $stmt = $db->prepare("
        SELECT
            *
        FROM (
            SELECT
                CONCAT('JV-', A.id) AS external_key,
                'JV' AS source_type,
                A.id AS source_id,
                A.id,
                A.moa_shared_id,
                A.account_no,
                A.user_id,
                A.ref_no,
                '' AS job_number,
                A.due_date_from,
                A.due_date_to,
                '' AS structure_id,
                A.payee,
                A.particulars,
                A.amount AS jv_amount,
                0 AS un_amount,
                C.group_name
            FROM moa_jv_expenses A
            LEFT OUTER JOIN moa_share B
                ON A.moa_shared_id = B.id
                AND A.user_id = B.user_id
            LEFT OUTER JOIN moa_locations C
                ON B.location_id = C.id
                AND B.moa_id = C.moa_id

            UNION ALL

            SELECT
                CONCAT('UNAI-', id) AS external_key,
                'UNAI' AS source_type,
                id AS source_id,
                id,
                moa_shared_id,
                account_no,
                user_id,
                transaction_no AS ref_no,
                job_number,
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
