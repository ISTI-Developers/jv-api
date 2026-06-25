<?php

require_once __DIR__ . '/../../config/database.php';

function get_authenticated_user(PDO $db): ?array
{
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? null;

    if (!$authHeader) {
        return null;
    }

    $session = trim(str_replace('Bearer', '', $authHeader));

    $stmt = $db->prepare("
        SELECT u.*
        FROM users u
        JOIN user_sessions s ON s.user_id = u.id
        WHERE s.session_id = ?
        AND s.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$session]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function require_auth(PDO $db): array
{
    $user = get_authenticated_user($db);

    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    return $user;
}

function require_admin(PDO $db): array
{
    $user = require_auth($db);

    $roleId = (int) $user['role_id'];

    if (!in_array($roleId, [1, 2], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    return $user;
}

function require_jv(PDO $db): array
{
    $user = require_auth($db);

    if ((int) $user['role_id'] !== 3) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    return $user;
}
