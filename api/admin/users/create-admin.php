<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../api/middleware/auth.php';
require_once __DIR__ . '/../../../core/Password.php';
require_once __DIR__ . '/../../../core/Mailer.php';
require_once __DIR__ . '/../../../helpers/audit.php';

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

    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);

    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'User already exists']);
        exit;
    }

    $tempPassword = Password::generateTemp();
    $tempHash = password_hash($tempPassword, PASSWORD_DEFAULT);

    $stmt = $db->prepare("INSERT INTO users (
            email,
            password,
            created_at,
            force_password_change,
            is_active,
            role_id,
            force_update_profile
        )
        VALUES (?, ?, NOW(), 1, 1, 2, 1)
    ");

    $stmt->execute([$email, $tempHash]);
    $userId = (int) $db->lastInsertId();
    $YOUR_LOGIN_URL = "http://localhost:3000/login";

    Mailer::send(
        $email,
        'Your JV Microsite Account Credentials',
        "You have been assigned as an admin to JV Microsite.\n\n" .
            "Login Credentials:\n" .
            "Email: {$email}\n" .
            "Temporary Password: {$tempPassword}\n\n" .
            "You will be required to change your password and update your profile upon first login.\n\n" .
            "Login here: {$YOUR_LOGIN_URL}\n\n" .
            "Regards,\nJV Microsite Team",
        ['arojo@unmg.com']
    );

    try {
        logAudit(
            $db,
            (int) $admin['id'],
            'CREATE_ADMIN_USER',
            'USERS',
            'users',
            (string) $userId,
            null,
            [
                'id' => $userId,
                'email' => $email,
                'role_id' => 2,
                'force_password_change' => 1,
                'force_update_profile' => 1,
                'is_active' => 1,
            ],
            'Admin created admin user'
        );
    } catch (Throwable $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }

    echo json_encode(['message' => 'Admin created and invitation sent successfully']);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
    exit;
}
