<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../api/middleware/auth.php';

header('Content-Type: application/json');

function normalizePositiveInt($value, int $default, int $max = PHP_INT_MAX): int
{
    $intValue = filter_var($value, FILTER_VALIDATE_INT);

    if ($intValue === false || $intValue < 1) {
        return $default;
    }

    return min($intValue, $max);
}

try {
    $db = Database::connect();
    require_admin($db);

    $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;

    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid user ID']);
        exit;
    }

    $page = normalizePositiveInt($_GET['page'] ?? null, 1);
    $limit = normalizePositiveInt($_GET['limit'] ?? null, 10, 100);
    $offset = ($page - 1) * $limit;

    $type = strtolower(trim((string) ($_GET['type'] ?? 'all')));
    if (!in_array($type, ['audit', 'transaction', 'all'], true)) {
        $type = 'all';
    }

    $search = trim((string) ($_GET['search'] ?? ''));
    $action = trim((string) ($_GET['action'] ?? ''));
    $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
    $dateTo = trim((string) ($_GET['date_to'] ?? ''));

    $queries = [];
    $params = [];

    if ($type === 'audit' || $type === 'all') {
        $auditWhere = ["al.user_id = :audit_user_id"];
        $params[':audit_user_id'] = $userId;

        if ($search !== '') {
            $auditWhere[] = "(
                al.action LIKE :audit_search_action
                OR al.module LIKE :audit_search_module
                OR al.entity_type LIKE :audit_search_entity_type
                OR al.entity_id LIKE :audit_search_entity_id
                OR al.description LIKE :audit_search_description
                OR u.email LIKE :audit_search_email
                OR up.first_name LIKE :audit_search_first_name
                OR up.last_name LIKE :audit_search_last_name
                OR up.company_name LIKE :audit_search_company_name
            )";
            $auditSearchValue = '%' . $search . '%';
            $params[':audit_search_action'] = $auditSearchValue;
            $params[':audit_search_module'] = $auditSearchValue;
            $params[':audit_search_entity_type'] = $auditSearchValue;
            $params[':audit_search_entity_id'] = $auditSearchValue;
            $params[':audit_search_description'] = $auditSearchValue;
            $params[':audit_search_email'] = $auditSearchValue;
            $params[':audit_search_first_name'] = $auditSearchValue;
            $params[':audit_search_last_name'] = $auditSearchValue;
            $params[':audit_search_company_name'] = $auditSearchValue;
        }

        if ($action !== '') {
            $auditWhere[] = "al.action = :audit_action";
            $params[':audit_action'] = $action;
        }

        if ($dateFrom !== '') {
            $auditWhere[] = "al.created_at >= :audit_date_from";
            $params[':audit_date_from'] = date('Y-m-d 00:00:00', strtotime($dateFrom));
        }

        if ($dateTo !== '') {
            $auditWhere[] = "al.created_at <= :audit_date_to";
            $params[':audit_date_to'] = date('Y-m-d 23:59:59', strtotime($dateTo));
        }

        $queries[] = "
            SELECT
                'audit' AS log_type,
                al.id,
                al.created_at,
                al.user_id,
                TRIM(CONCAT(COALESCE(up.first_name, ''), ' ', COALESCE(up.last_name, ''))) AS user_name,
                u.email AS user_email,
                up.company_name AS user_company,
                al.action,
                al.module AS module_or_type,
                al.description,
                NULL AS reference_no,
                NULL AS amount,
                al.new_values AS metadata
            FROM audit_logs al
            LEFT JOIN users u
                ON u.id = al.user_id
            LEFT JOIN user_profiles up
                ON up.user_id = u.id
            WHERE " . implode(' AND ', $auditWhere);
    }

    if ($type === 'transaction' || $type === 'all') {
        $transactionWhere = ["tl.performed_by = :transaction_user_id"];
        $params[':transaction_user_id'] = $userId;

        if ($search !== '') {
            $transactionWhere[] = "(
                tl.transaction_type LIKE :transaction_search_type
                OR tl.action LIKE :transaction_search_action
                OR tl.reference_table LIKE :transaction_search_reference_table
                OR tl.reference_id LIKE :transaction_search_reference_id
                OR tl.reference_no LIKE :transaction_search_reference_no
                OR tl.structure_id LIKE :transaction_search_structure_id
                OR tl.account_no LIKE :transaction_search_account_no
                OR tl.description LIKE :transaction_search_description
                OR u.email LIKE :transaction_search_email
                OR up.first_name LIKE :transaction_search_first_name
                OR up.last_name LIKE :transaction_search_last_name
                OR up.company_name LIKE :transaction_search_company_name
            )";
            $transactionSearchValue = '%' . $search . '%';
            $params[':transaction_search_type'] = $transactionSearchValue;
            $params[':transaction_search_action'] = $transactionSearchValue;
            $params[':transaction_search_reference_table'] = $transactionSearchValue;
            $params[':transaction_search_reference_id'] = $transactionSearchValue;
            $params[':transaction_search_reference_no'] = $transactionSearchValue;
            $params[':transaction_search_structure_id'] = $transactionSearchValue;
            $params[':transaction_search_account_no'] = $transactionSearchValue;
            $params[':transaction_search_description'] = $transactionSearchValue;
            $params[':transaction_search_email'] = $transactionSearchValue;
            $params[':transaction_search_first_name'] = $transactionSearchValue;
            $params[':transaction_search_last_name'] = $transactionSearchValue;
            $params[':transaction_search_company_name'] = $transactionSearchValue;
        }

        if ($action !== '') {
            $transactionWhere[] = "tl.action = :transaction_action";
            $params[':transaction_action'] = $action;
        }

        if ($dateFrom !== '') {
            $transactionWhere[] = "tl.created_at >= :transaction_date_from";
            $params[':transaction_date_from'] = date('Y-m-d 00:00:00', strtotime($dateFrom));
        }

        if ($dateTo !== '') {
            $transactionWhere[] = "tl.created_at <= :transaction_date_to";
            $params[':transaction_date_to'] = date('Y-m-d 23:59:59', strtotime($dateTo));
        }

        $queries[] = "
            SELECT
                'transaction' AS log_type,
                tl.id,
                tl.created_at,
                tl.performed_by AS user_id,
                TRIM(CONCAT(COALESCE(up.first_name, ''), ' ', COALESCE(up.last_name, ''))) AS user_name,
                u.email AS user_email,
                up.company_name AS user_company,
                tl.action,
                tl.transaction_type AS module_or_type,
                tl.description,
                tl.reference_no,
                tl.amount,
                tl.metadata
            FROM transaction_logs tl
            LEFT JOIN users u
                ON u.id = tl.performed_by
            LEFT JOIN user_profiles up
                ON up.user_id = u.id
            WHERE " . implode(' AND ', $transactionWhere);
    }

    $unionSql = implode(' UNION ALL ', $queries);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM ($unionSql) logs");
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT *
        FROM ($unionSql) logs
        ORDER BY created_at DESC, id DESC
        LIMIT :limit OFFSET :offset
    ");

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = array_map(function ($row) {
        $metadata = $row['metadata'] !== null ? json_decode($row['metadata'], true) : null;
        $userName = trim((string) $row['user_name']);

        return [
            'log_type' => $row['log_type'],
            'id' => (int) $row['id'],
            'created_at' => $row['created_at'],
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'user_name' => $userName !== '' ? $userName : null,
            'user_email' => $row['user_email'],
            'user_company' => $row['user_company'],
            'action' => $row['action'],
            'module_or_type' => $row['module_or_type'],
            'description' => $row['description'],
            'reference_no' => $row['reference_no'],
            'amount' => $row['amount'] !== null ? (float) $row['amount'] : null,
            'metadata' => $metadata,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
