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
    $menuIds = $input['menu_ids'] ?? [];
    $overrides = $input['overrides'] ?? [];

    if ($roleId) {
        $stmt = $db->prepare("
            SELECT menu_id
            FROM role_permissions
            WHERE role_id = ?
            ORDER BY menu_id ASC
        ");
        $stmt->execute([$roleId]);
        $oldMenuIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $newMenuIds = array_values(array_unique(array_map('intval', $menuIds)));

        $db->beginTransaction();

        $delete = $db->prepare("DELETE FROM role_permissions WHERE role_id = ?");
        $delete->execute([$roleId]);

        $insert = $db->prepare("
            INSERT INTO role_permissions (role_id, menu_id)
            VALUES (?, ?)
        ");

        foreach ($menuIds as $menuId) {
            $insert->execute([$roleId, (int) $menuId]);
        }

        $db->commit();

        logAudit(
            $db,
            (int) $admin['id'],
            'UPDATE_ROLE_MENU_ACCESS',
            'ACCESS_CONTROL',
            'roles',
            (string) $roleId,
            [
                'role_id' => $roleId,
                'menu_ids' => $oldMenuIds,
                'menu_count' => count($oldMenuIds),
            ],
            [
                'role_id' => $roleId,
                'menu_ids' => $newMenuIds,
                'menu_count' => count($newMenuIds),
            ],
            'Admin updated role menu access'
        );

        echo json_encode([
            'success' => true,
            'message' => 'Role menu access updated successfully',
        ]);
        exit;
    }

    if ($userId) {
        $stmt = $db->prepare("
            SELECT menu_id, type
            FROM user_permissions
            WHERE user_id = ?
            ORDER BY menu_id ASC
        ");
        $stmt->execute([$userId]);
        $oldOverrides = array_map(function ($override) {
            return [
                'menu_id' => (int) $override['menu_id'],
                'type' => $override['type'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        $newOverrides = [];

        foreach ($overrides as $override) {
            if (empty($override['menu_id']) || empty($override['type'])) {
                continue;
            }

            if (!in_array($override['type'], ['allow', 'deny'], true)) {
                continue;
            }

            $newOverrides[] = [
                'menu_id' => (int) $override['menu_id'],
                'type' => $override['type'],
            ];
        }

        $db->beginTransaction();

        $delete = $db->prepare("DELETE FROM user_permissions WHERE user_id = ?");
        $delete->execute([$userId]);

        $insert = $db->prepare("
            INSERT INTO user_permissions (user_id, menu_id, type)
            VALUES (?, ?, ?)
        ");

        foreach ($newOverrides as $override) {
            $insert->execute([
                $userId,
                $override['menu_id'],
                $override['type'],
            ]);
        }

        $db->commit();

        logAudit(
            $db,
            (int) $admin['id'],
            'UPDATE_USER_MENU_ACCESS',
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
            'Admin updated user menu access overrides'
        );

        echo json_encode([
            'success' => true,
            'message' => 'User menu access updated successfully',
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
