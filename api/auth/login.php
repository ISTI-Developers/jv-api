<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/database.php';

use Firebase\JWT\JWT;

$config = require __DIR__ . '/../../config/jwt.php';

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

$payload = [
    'sub' => $user->id,
    'email' => $user->email,
    'iat' => time(),
    'exp' => time() + $config['expiry']
];

$token = JWT::encode($payload, $config['secret'], $config['algo']);

echo json_encode([
    'token' => $token,
    'user' => [
        'id' => $user->id,
        'email' => $user->email
    ]
]);
