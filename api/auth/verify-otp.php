<?php

require_once __DIR__ . '/../../config/database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (
        !isset($input['email']) ||
        !isset($input['otp'])
    ) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing fields']);
        exit;
    }

    $email = trim($input['email']);
    $otp = trim($input['otp']);

    $db = Database::connect();

    // find user
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid OTP']);
        exit;
    }

    $userId = (int)$user['id'];

    // fetch latest valid OTP
    $stmt = $db->prepare("SELECT id, otp_hash, expires_at
        FROM password_resets
        WHERE user_id = ?
          AND verified_at IS NULL
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (
        !$reset ||
        strtotime($reset['expires_at']) < time() ||
        !password_verify((string)$otp, $reset['otp_hash'])
    ) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid OTP']);
        exit;
    }

    // mark OTP as verified
    $stmt = $db->prepare("UPDATE password_resets
        SET verified_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$reset['id']]);

    echo json_encode(['message' => 'OTP verified']);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
}
