<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../api/middleware/auth.php';

try {
    $db = Database::connect();
    $admin = require_admin($db);

    $input = json_decode(file_get_contents("php://input"), true);

    $userId     = (int)($input['user_id'] ?? 0);
    $firstName  = trim($input['first_name'] ?? '');
    $lastName   = trim($input['last_name'] ?? '');
    $entityType = $input['entity_type'] ?? null;
    $companyName = trim($input['company_name'] ?? '');

    if (!$userId || !$firstName || !$lastName || !$entityType) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

    if (!in_array($entityType, ['individual', 'company'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid entity type']);
        exit;
    }

    $stmt = $db->prepare("SELECT user_id FROM user_profiles WHERE user_id = ?");
    $stmt->execute([$userId]);
    $exists = $stmt->fetch();

    if ($exists) {
        $stmt = $db->prepare("
            UPDATE user_profiles
            SET first_name = ?, last_name = ?, entity_type = ?, company_name = ?, updated_at = NOW()
            WHERE user_id = ?
        ");
        $stmt->execute([
            $firstName,
            $lastName,
            $entityType,
            $entityType === 'company' ? $companyName : null,
            $userId
        ]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO user_profiles (user_id, first_name, last_name, entity_type, company_name, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId,
            $firstName,
            $lastName,
            $entityType,
            $entityType === 'company' ? $companyName : null
        ]);
    }

    echo json_encode(['success' => true]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
}
