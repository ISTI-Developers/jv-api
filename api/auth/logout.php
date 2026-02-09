<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/AuthMiddleware.php';

$requestUser = AuthMiddleware::handle(); // <-- REQUIRED

$db = Database::connect();

$stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
$stmt->execute([$requestUser->id]);

echo json_encode([
    'message' => 'Logged out'
]);
