<?php

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/AuthMiddleware.php';

$user = AuthMiddleware::handle();
$db = Database::connect();

$stmt = $db->prepare("SELECT 
    u.id,
    u.email,
    u.last_login,
    u.created_at,
    u.force_password_change,
    u.is_active,
    u.role_id,
    u.force_update_profile,
    r.name AS role_name,
    up.user_id,
    up.first_name,
    up.last_name,
    up.entity_type,
    up.company_name,
    up.updated_at
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    LEFT JOIN user_profiles up ON up.user_id = u.id WHERE u.id = ?");
$stmt->execute([$user->id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

$result = [
    'id' => (int) $row['id'],
    'email' => $row['email'],
    'last_login' => $row['last_login'],
    'created_at' => $row['created_at'],
    'force_password_change' => (int) $row['force_password_change'],
    'is_active' => (int) $row['is_active'],
    'role_id' => (int) $row['role_id'],
    'force_update_profile' => (int) $row['force_update_profile'],
    'role_name' => $row['role_name'],
    'profile' => $row['user_id'] ? [
        'user_id' => (int) $row['user_id'],
        'first_name' => $row['first_name'],
        'last_name' => $row['last_name'],
        'entity_type' => $row['entity_type'],
        'company_name' => $row['company_name'],
        'updated_at' => $row['updated_at'],
    ] : null,
];

echo json_encode($result);
exit;
