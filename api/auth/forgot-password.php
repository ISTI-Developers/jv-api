<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing email']);
        exit;
    }

    $email = trim($input['email']);
    $db = Database::connect();

    // find user
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // always respond success (prevent enumeration)
    $genericResponse = ['message' => 'If the email exists, an OTP was sent.'];

    if (!$user) {
        echo json_encode($genericResponse);
        exit;
    }

    $userId = (int)$user['id'];

    // invalidate old OTPs
    $stmt = $db->prepare("DELETE FROM password_resets WHERE user_id = ?");
    $stmt->execute([$userId]);

    // generate OTP
    $otp = random_int(100000, 999999);
    $otpHash = password_hash((string)$otp, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + (10 * 60)); // 10 minutes

    // store OTP
    $stmt = $db->prepare("
        INSERT INTO password_resets (user_id, otp_hash, expires_at)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$userId, $otpHash, $expiresAt]);

    logAudit(
        $db,
        $userId,
        'PASSWORD_RESET_REQUEST',
        'AUTH',
        'USER',
        (string)$userId,
        null,
        null,
        'User requested password reset OTP'
    );

    // send email
    Mailer::send(
        $email,
        'Your Password Reset Code',
        "Your OTP is: {$otp}\n\nThis code expires in 10 minutes.",
        ['arojo@unmg.com.ph']
    );

    echo json_encode($genericResponse);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
}
