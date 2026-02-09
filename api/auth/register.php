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

// check if email exists
$stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);

if ($stmt->fetch()) {
    http_response_code(409);
    echo json_encode(['error' => 'Email already exists']);
    exit;
}

// encrypt password (bcrypt)
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

$stmt = $db->prepare("
    INSERT INTO users (email, password, created_at)
    VALUES (?, ?, NOW())
");

$success = $stmt->execute([$email, $hashedPassword]);

if (!$success) {
    http_response_code(500);
    echo json_encode(['error' => 'Registration failed']);
    exit;
}

echo json_encode([
    'message' => 'User registered successfully'
]);
