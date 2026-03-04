<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../api/middleware/auth.php';
require_once __DIR__ . '/../../../core/Password.php';
require_once __DIR__ . '/../../../core/Mailer.php';

try {
    $db = Database::connect();

    $admin = require_admin($db);

    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Email is required']);
        exit;
    }

    $email = trim(strtolower($input['email']));

    $allowedRoles = [1, 2, 3];
    $roleId = isset($input['role_id']) ? (int)$input['role_id'] : 3;

    if (!in_array($roleId, $allowedRoles, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid role selected']);
        exit;
    }

    if ($roleId === 1 && (int)$admin['role_id'] !== 1) {
        http_response_code(403);
        echo json_encode(['error' => 'Only Super User can create another Super User']);
        exit;
    }

    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);

    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'User already exists']);
        exit;
    }

    $tempPassword = Password::generateTemp();
    $tempHash = password_hash($tempPassword, PASSWORD_DEFAULT);

    // Insert user with dynamic role
    $stmt = $db->prepare("
        INSERT INTO users (
            email,
            password,
            created_at,
            force_password_change,
            is_active,
            role_id,
            force_update_profile
        )
        VALUES (?, ?, NOW(), 1, 1, ?, 1)
    ");

    $stmt->execute([$email, $tempHash, $roleId]);

    $YOUR_LOGIN_URL = "http://localhost:3000/login";

    Mailer::send(
        $email,
        'Your JV Microsite Account Credentials',
        "You have been invited to JV Microsite.\n\n" .
            "Login Credentials:\n" .
            "Email: {$email}\n" .
            "Temporary Password: {$tempPassword}\n\n" .
            "You will be required to change your password and update your profile upon first login.\n\n" .
            "Login here: {$YOUR_LOGIN_URL}\n\n" .
            "Regards,\nJV Microsite Team",
        ['arojo@unmg.com.ph']
    );

    echo json_encode(['message' => 'Invitation sent successfully']);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
    exit;
}
