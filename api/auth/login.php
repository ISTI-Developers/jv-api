<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/audit.php';

$input = json_decode(file_get_contents("php://input"), true);

$email = $input['email'] ?? null;
$password = $input['password'] ?? null;

if (!$email || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing credentials']);
    exit;
}

$db = Database::connect();

$stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user->password)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials']);
    exit;
}

if ((int)$user->is_active !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'Account is deactivated']);
    exit;
}

// update last login
$stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
$stmt->execute([$user->id]);

// session TTL (minutes)
$ttlMinutes = (int)($_ENV['SESSION_TTL_MINUTES'] ?? 120);
$expiresAt = date('Y-m-d H:i:s', time() + ($ttlMinutes * 60));

// generate single-session id
$sessionId = bin2hex(random_bytes(32));

// insert / replace session (single login enforced)
$stmt = $db->prepare("
    INSERT INTO user_sessions (user_id, session_id, created_at, expires_at)
    VALUES (?, ?, NOW(), ?)
    ON DUPLICATE KEY UPDATE
        session_id = VALUES(session_id),
        created_at = NOW(),
        expires_at = VALUES(expires_at)
");
$stmt->execute([$user->id, $sessionId, $expiresAt]);

logAudit(
    $db,
    $user->id,
    'LOGIN',
    'AUTH',
    'users',
    $user->id,
    null,
    null,
    'User logged in'
);

echo json_encode([
    'session' => $sessionId,
    'user' => [
        'id' => $user->id,
        'email' => $user->email,
        'last_login' => $user->last_login,
        'force_update_profile' => (bool)$user->force_update_profile,
        'force_password_change' => (bool)$user->force_password_change
    ]
]);
exit;
