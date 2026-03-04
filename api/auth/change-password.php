<?php

require_once __DIR__ . '/../../core/AuthMiddleware.php';
require_once __DIR__ . '/../../config/database.php';

try {
    // 1. Authenticate request
    $requestUser = AuthMiddleware::handle();
    $userId = $requestUser->id;

    // 2. Parse input
    $input = json_decode(file_get_contents('php://input'), true);

    if (
        !isset($input['current_password']) ||
        !isset($input['new_password'])
    ) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing fields']);
        exit;
    }

    $db = Database::connect();

    // 3. Verify current password
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($input['current_password'], $user['password'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid current password']);
        exit;
    }

    // 4. Update password
    $newHash = password_hash($input['new_password'], PASSWORD_DEFAULT);
    $stmt = $db->prepare("
    UPDATE users
    SET password = ?, force_password_change = 0
    WHERE id = ?");
    $stmt->execute([$newHash, $userId]);

    // 5. Respond SUCCESS FIRST
    echo json_encode([
        'message' => 'Password changed. Please login again.'
    ]);

    // 6. Invalidate session (force logout)
    $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
    $stmt->execute([$userId]);

    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
}
