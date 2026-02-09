<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/AuthMiddleware.php';

$user = AuthMiddleware::handle();
$db = Database::connect();

$stmt = $db->prepare("SELECT id, email FROM users WHERE id = ?");
$stmt->execute([$user->id]);
$data = $stmt->fetch();

echo json_encode($data);
