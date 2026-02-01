<?php

require_once __DIR__ . '/../../core/AuthMiddleware.php';

$user = AuthMiddleware::handle();

echo json_encode([
    'id' => $user->sub,
    'email' => $user->email
]);
