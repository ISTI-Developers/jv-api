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
            al.action LIKE :search_action
            OR al.module LIKE :search_module
            OR al.entity_type LIKE :search_entity_type
            OR al.entity_id LIKE :search_entity_id
            OR al.description LIKE :search_description
            OR u.email LIKE :search_email
            OR up.first_name LIKE :search_first_name
            OR up.last_name LIKE :search_last_name
            OR up.company_name LIKE :search_company_name
        )";
        $searchValue = '%' . $search . '%';
        $params[':search_action'] = $searchValue;
        $params[':search_module'] = $searchValue;
        $params[':search_entity_type'] = $searchValue;
        $params[':search_entity_id'] = $searchValue;
        $params[':search_description'] = $searchValue;
        $params[':search_email'] = $searchValue;
        $params[':search_first_name'] = $searchValue;
        $params[':search_last_name'] = $searchValue;
        $params[':search_company_name'] = $searchValue;
    }

    if (isset($_GET['user_id']) && $_GET['user_id'] !== '') {
        $userId = filter_var($_GET['user_id'], FILTER_VALIDATE_INT);
        if ($userId !== false && $userId > 0) {
            $where[] = "al.user_id = :user_id";
            $params[':user_id'] = $userId;
        }
    }

    foreach (['action', 'module', 'entity_type'] as $field) {
        if (isset($_GET[$field]) && trim((string) $_GET[$field]) !== '') {
            $param = ':' . $field;
            $where[] = "al.$field = $param";
            $params[$param] = trim((string) $_GET[$field]);
        }
    }

    if (!empty($_GET['date_from'])) {
        $where[] = "al.created_at >= :date_from";
        $params[':date_from'] = date('Y-m-d 00:00:00', strtotime((string) $_GET['date_from']));
    }

    if (!empty($_GET['date_to'])) {
        $where[] = "al.created_at <= :date_to";
        $params[':date_to'] = date('Y-m-d 23:59:59', strtotime((string) $_GET['date_to']));
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countSql = "
        SELECT COUNT(*)
        FROM audit_logs al
        LEFT JOIN users u
            ON u.id = al.user_id
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
            al.id,
            al.user_id,
            al.action,
            al.module,
            al.entity_type,
            al.entity_id,
            al.description,
            al.old_values,
            al.new_values,
            al.ip_address,
            al.user_agent,
            al.created_at,
            u.email AS user_email,
            up.first_name,
            up.last_name,
            up.company_name
        FROM audit_logs al
        LEFT JOIN users u
            ON u.id = al.user_id
        LEFT JOIN user_profiles up
            ON up.user_id = u.id
        $whereSql
        ORDER BY al.created_at DESC, al.id DESC
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
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'action' => $row['action'],
            'module' => $row['module'],
            'entity_type' => $row['entity_type'],
            'entity_id' => $row['entity_id'],
            'description' => $row['description'],
            'old_values' => $row['old_values'] !== null ? json_decode($row['old_values'], true) : null,
            'new_values' => $row['new_values'] !== null ? json_decode($row['new_values'], true) : null,
            'ip_address' => $row['ip_address'],
            'user_agent' => $row['user_agent'],
            'created_at' => $row['created_at'],
            'user' => $row['user_id'] !== null ? [
                'id' => (int) $row['user_id'],
                'email' => $row['user_email'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'company_name' => $row['company_name'],
            ] : null,
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
