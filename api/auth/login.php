<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/database.php';

$input = json_decode(file_get_contents("php://input"), true);

$email = $input['email'] ?? null;
$password = $input['password'] ?? null;

if (!$email || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing credentials']);
    exit;
}

$db = Database::connect();

$stmt = $db->prepare("SELECT id, email, password FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user->password)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials']);
    exit;
}

// generate single-session id
$sessionId = bin2hex(random_bytes(32));

// insert / replace session (single login enforced by UNIQUE(user_id))
$stmt = $db->prepare("
    INSERT INTO user_sessions (user_id, session_id, created_at, expires_at)
    VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))
    ON DUPLICATE KEY UPDATE
        session_id = VALUES(session_id),
        created_at = NOW(),
        expires_at = VALUES(expires_at)
");
$stmt->execute([$user->id, $sessionId]);

echo json_encode([
    'session' => $sessionId,
    'user' => [
        'id' => $user->id,
        'email' => $user->email
    ]
]);
