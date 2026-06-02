<?php

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../middleware/auth.php';
require_once __DIR__ . '/../../../../helpers/audit.php';

header('Content-Type: application/json');

try {
    $db = Database::connect();
    $admin = require_admin($db);

    $input = json_decode(file_get_contents('php://input'), true);

    $roleId = isset($input['role_id']) ? (int) $input['role_id'] : null;
    $userId = isset($input['user_id']) ? (int) $input['user_id'] : null;
    $permissionIds = $input['permission_ids'] ?? [];
    $overrides = $input['overrides'] ?? [];

    if ($roleId) {
        $stmt = $db->prepare("
            SELECT permission_id
            FROM role_action_permissions
            WHERE role_id = ?
            ORDER BY permission_id ASC
        ");
        $stmt->execute([$roleId]);
        $oldPermissionIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $newPermissionIds = array_values(array_unique(array_map('intval', $permissionIds)));

        $db->beginTransaction();

        $delete = $db->prepare("DELETE FROM role_action_permissions WHERE role_id = ?");
        $delete->execute([$roleId]);

        $insert = $db->prepare("
            INSERT INTO role_action_permissions (role_id, permission_id)
            VALUES (?, ?)
        ");

        foreach ($permissionIds as $permissionId) {
            $insert->execute([$roleId, (int) $permissionId]);
        }

        $db->commit();

        logAudit(
            $db,
            (int) $admin['id'],
            'UPDATE_ROLE_ACTION_ACCESS',
            'ACCESS_CONTROL',
            'roles',
            (string) $roleId,
            [
                'role_id' => $roleId,
                'permission_ids' => $oldPermissionIds,
                'permission_count' => count($oldPermissionIds),
            ],
            [
                'role_id' => $roleId,
                'permission_ids' => $newPermissionIds,
                'permission_count' => count($newPermissionIds),
            ],
            'Admin updated role action access'
        );

        echo json_encode([
            'success' => true,
            'message' => 'Role action access updated successfully',
        ]);
        exit;
    }

    if ($userId) {
        $stmt = $db->prepare("
            SELECT permission_id, type
            FROM user_action_permissions
            WHERE user_id = ?
            ORDER BY permission_id ASC
        ");
        $stmt->execute([$userId]);
        $oldOverrides = array_map(function ($override) {
            return [
                'permission_id' => (int) $override['permission_id'],
                'type' => $override['type'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        $newOverrides = [];

        foreach ($overrides as $override) {
            if (empty($override['permission_id']) || empty($override['type'])) {
                continue;
            }

            if (!in_array($override['type'], ['allow', 'deny'], true)) {
                continue;
            }

            $newOverrides[] = [
                'permission_id' => (int) $override['permission_id'],
                'type' => $override['type'],
            ];
        }

        $db->beginTransaction();

        $delete = $db->prepare("DELETE FROM user_action_permissions WHERE user_id = ?");
        $delete->execute([$userId]);

        $insert = $db->prepare("
            INSERT INTO user_action_permissions (user_id, permission_id, type)
            VALUES (?, ?, ?)
        ");

        foreach ($newOverrides as $override) {
            $insert->execute([
                $userId,
                $override['permission_id'],
                $override['type'],
            ]);
        }

        $db->commit();

        logAudit(
            $db,
            (int) $admin['id'],
            'UPDATE_USER_ACTION_ACCESS',
            'ACCESS_CONTROL',
            'users',
            (string) $userId,
            [
                'user_id' => $userId,
                'overrides' => $oldOverrides,
                'override_count' => count($oldOverrides),
            ],
            [
                'user_id' => $userId,
                'overrides' => $newOverrides,
                'override_count' => count($newOverrides),
            ],
            'Admin updated user action access overrides'
        );

        echo json_encode([
            'success' => true,
            'message' => 'User action access updated successfully',
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'role_id or user_id is required',
    ]);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
