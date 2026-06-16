<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../../helpers/audit.php';

$db = Database::connect();

$admin = require_admin($db); // returns array

$input = json_decode(file_get_contents("php://input"), true);

$userId = $input['user_id'] ?? null;
$status = $input['status'] ?? null;

if (!$userId || !in_array($status, [0, 1], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$stmt = $db->prepare("SELECT id, email, is_active FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$oldUser = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
$stmt->execute([$status, $userId]);

/**
 * Audit log (wrapped to prevent crash)
 */
try {
    logAudit(
        $db,
        (int)$admin['id'],
        $status === 1 ? 'ACTIVATE_USER' : 'DEACTIVATE_USER',
            'USERS',
            'users',
            (string)$userId,
            $oldUser ? [
                'id' => (int) $oldUser['id'],
                'email' => $oldUser['email'],
                'is_active' => (int) $oldUser['is_active'],
            ] : null,
            [
                'id' => (int) $userId,
                'is_active' => (int) $status,
            ],
            'Admin changed user active status'
        );
} catch (Throwable $e) {
    error_log('Audit log failed: ' . $e->getMessage());
}

echo json_encode(['success' => true]);
