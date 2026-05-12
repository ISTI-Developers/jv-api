<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../../helpers/audit.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (
        !isset($input['email']) ||
        !isset($input['new_password'])
    ) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing fields']);
        exit;
    }

    $email = trim($input['email']);
    $newPassword = $input['new_password'];

    $db = Database::connect();

    // find user
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }

    $userId = (int)$user['id'];

    // ensure OTP was verified and not expired
    $stmt = $db->prepare("SELECT id
        FROM password_resets
        WHERE user_id = ?
          AND verified_at IS NOT NULL
          AND expires_at > NOW()
        ORDER BY verified_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reset) {
        http_response_code(400);
        echo json_encode(['error' => 'OTP verification required']);
        exit;
    }

    // update password
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

    $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->execute([$newHash, $userId]);

    // cleanup OTPs
    $stmt = $db->prepare("DELETE FROM password_resets WHERE user_id = ?");
    $stmt->execute([$userId]);

    // invalidate sessions
    $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
    $stmt->execute([$userId]);

    logAudit(
        $db,
        $userId,
        'PASSWORD_RESET_SUCCESS',
        'AUTH',
        'USER',
        (string)$userId,
        null,
        null,
        'User successfully reset password via OTP'
    );

    echo json_encode([
        'message' => 'Password reset successful. Please login.'
    ]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
}
