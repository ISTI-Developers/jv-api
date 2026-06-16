<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../api/middleware/auth.php';
require_once __DIR__ . '/../../../core/Password.php';
require_once __DIR__ . '/../../../core/Mailer.php';
require_once __DIR__ . '/../../../helpers/audit.php';

use PHPMailer\PHPMailer\PHPMailer;

try {
    $db = Database::connect();

    $admin = require_admin($db);

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['user_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing user_id']);
        exit;
    }

    $userId = (int)$input['user_id'];

    if ($admin['id'] == $userId) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot reset your own password']);
        exit;
    }

    $stmt = $db->prepare("
        SELECT id, email, force_password_change
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['email'])) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }

    $email = $user['email'];

    $tempPassword = Password::generateTemp();
    $tempHash = password_hash($tempPassword, PASSWORD_DEFAULT);

    // update password + force change
    $stmt = $db->prepare("UPDATE users
        SET password = ?, force_password_change = 1
        WHERE id = ?
    ");
    $stmt->execute([$tempHash, $userId]);

    // invalidate sessions
    $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
    $stmt->execute([$userId]);
    $deletedSessions = $stmt->rowCount();

    Mailer::send(
        $email,
        'Temporary Password (Admin Reset)',
        "Your password was reset by an admin.\n\n" .
            "Temporary password: {$tempPassword}\n\n" .
            "You will be forced to change your password after logging in.",
        ['arojo@unmg.com']
    );

    try {
        logAudit(
            $db,
            (int) $admin['id'],
            'RESET_USER_PASSWORD',
            'USERS',
            'users',
            (string) $userId,
            [
                'id' => (int) $user['id'],
                'email' => $email,
                'force_password_change' => (int) $user['force_password_change'],
            ],
            [
                'id' => $userId,
                'email' => $email,
                'force_password_change' => 1,
                'sessions_invalidated' => $deletedSessions,
            ],
            'Admin reset user password'
        );
    } catch (Throwable $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }

    echo json_encode(['message' => 'Temporary password sent']);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
}
