<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/AuthMiddleware.php';

try {
    $requestUser = AuthMiddleware::handle();
    $db = Database::connect();

    $input = json_decode(file_get_contents("php://input"), true);

    $firstName   = trim($input['first_name'] ?? '');
    $lastName    = trim($input['last_name'] ?? '');
    $entityType  = $input['entity_type'] ?? null;
    $companyName = trim($input['company_name'] ?? '');

    if (!$firstName || !$lastName || !$entityType) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

    if (!in_array($entityType, ['individual', 'company'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid entity type']);
        exit;
    }

    if ($entityType === 'company' && !$companyName) {
        http_response_code(400);
        echo json_encode(['error' => 'Company name required']);
        exit;
    }

    // Check if profile exists
    $stmt = $db->prepare("SELECT user_id FROM user_profiles WHERE user_id = ?");
    $stmt->execute([$requestUser->id]);
    $exists = $stmt->fetch();

    if ($exists) {
        // Update
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
            $requestUser->id
        ]);
    } else {
        // Insert
        $stmt = $db->prepare("
            INSERT INTO user_profiles (user_id, first_name, last_name, entity_type, company_name, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $requestUser->id,
            $firstName,
            $lastName,
            $entityType,
            $entityType === 'company' ? $companyName : null
        ]);
    }

    // Remove force flag after successful update
    $stmt = $db->prepare("UPDATE users SET force_update_profile = 0 WHERE id = ?");
    $stmt->execute([$requestUser->id]);

    echo json_encode(['success' => true]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
}
