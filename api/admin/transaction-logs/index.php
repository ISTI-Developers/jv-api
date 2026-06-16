<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

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

    $page = normalizePositiveInt($_GET['page'] ?? null, 1);
    $limit = normalizePositiveInt($_GET['limit'] ?? null, 10, 100);
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];

    $search = trim((string) ($_GET['search'] ?? ''));
    if ($search !== '') {
        $where[] = "(
            tl.transaction_type LIKE :search_transaction_type
            OR tl.action LIKE :search_action
            OR tl.status LIKE :search_status
            OR tl.reference_table LIKE :search_reference_table
            OR tl.reference_id LIKE :search_reference_id
            OR tl.reference_no LIKE :search_reference_no
            OR tl.structure_id LIKE :search_structure_id
            OR tl.account_no LIKE :search_account_no
            OR tl.description LIKE :search_description
            OR u.email LIKE :search_email
            OR up.first_name LIKE :search_first_name
            OR up.last_name LIKE :search_last_name
            OR up.company_name LIKE :search_company_name
        )";
        $searchValue = '%' . $search . '%';
        $params[':search_transaction_type'] = $searchValue;
        $params[':search_action'] = $searchValue;
        $params[':search_status'] = $searchValue;
        $params[':search_reference_table'] = $searchValue;
        $params[':search_reference_id'] = $searchValue;
        $params[':search_reference_no'] = $searchValue;
        $params[':search_structure_id'] = $searchValue;
        $params[':search_account_no'] = $searchValue;
        $params[':search_description'] = $searchValue;
        $params[':search_email'] = $searchValue;
        $params[':search_first_name'] = $searchValue;
        $params[':search_last_name'] = $searchValue;
        $params[':search_company_name'] = $searchValue;
    }

    foreach (['moa_id', 'moa_share_id', 'performed_by'] as $field) {
        if (isset($_GET[$field]) && $_GET[$field] !== '') {
            $value = filter_var($_GET[$field], FILTER_VALIDATE_INT);
            if ($value !== false && $value > 0) {
                $param = ':' . $field;
                $where[] = "tl.$field = $param";
                $params[$param] = $value;
            }
        }
    }

    foreach ([
        'transaction_type',
        'action',
        'reference_table',
        'reference_no',
        'structure_id',
        'account_no',
    ] as $field) {
        if (isset($_GET[$field]) && trim((string) $_GET[$field]) !== '') {
            $param = ':' . $field;
            $where[] = "tl.$field = $param";
            $params[$param] = trim((string) $_GET[$field]);
        }
    }

    if (!empty($_GET['date_from'])) {
        $where[] = "tl.created_at >= :date_from";
        $params[':date_from'] = date('Y-m-d 00:00:00', strtotime((string) $_GET['date_from']));
    }

    if (!empty($_GET['date_to'])) {
        $where[] = "tl.created_at <= :date_to";
        $params[':date_to'] = date('Y-m-d 23:59:59', strtotime((string) $_GET['date_to']));
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countSql = "
        SELECT COUNT(*)
        FROM transaction_logs tl
        LEFT JOIN users u
            ON u.id = tl.performed_by
        LEFT JOIN user_profiles up
            ON up.user_id = u.id
        $whereSql
    ";
    $countStmt = $db->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $sql = "
        SELECT
            tl.id,
            tl.transaction_type,
            tl.moa_id,
            tl.moa_share_id,
            tl.structure_id,
            tl.account_no,
            tl.amount,
            tl.reference_table,
            tl.reference_id,
            tl.reference_no,
            tl.action,
            tl.status,
            tl.description,
            tl.metadata,
            tl.performed_by,
            tl.created_at,
            u.email AS performer_email,
            up.first_name,
            up.last_name,
            up.company_name
        FROM transaction_logs tl
        LEFT JOIN users u
            ON u.id = tl.performed_by
        LEFT JOIN user_profiles up
            ON up.user_id = u.id
        $whereSql
        ORDER BY tl.created_at DESC, tl.id DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = array_map(function ($row) {
        return [
            'id' => (int) $row['id'],
            'transaction_type' => $row['transaction_type'],
            'moa_id' => $row['moa_id'] !== null ? (int) $row['moa_id'] : null,
            'moa_share_id' => $row['moa_share_id'] !== null ? (int) $row['moa_share_id'] : null,
            'structure_id' => $row['structure_id'],
            'account_no' => $row['account_no'],
            'amount' => $row['amount'] !== null ? (float) $row['amount'] : null,
            'reference_table' => $row['reference_table'],
            'reference_id' => $row['reference_id'],
            'reference_no' => $row['reference_no'],
            'action' => $row['action'],
            'status' => $row['status'],
            'description' => $row['description'],
            'metadata' => $row['metadata'] !== null ? json_decode($row['metadata'], true) : null,
            'performed_by' => (int) $row['performed_by'],
            'created_at' => $row['created_at'],
            'performer' => [
                'id' => (int) $row['performed_by'],
                'email' => $row['performer_email'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'company_name' => $row['company_name'],
            ],
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
