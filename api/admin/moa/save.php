<?php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../../helpers/audit.php';

header('Content-Type: application/json');

function fetchMoaAuditSummary(PDO $db, int $moaId): ?array
{
    $stmt = $db->prepare("
        SELECT id, moa_name, soft_deleted, created_at
        FROM moa
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$moaId]);
    $moa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$moa) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT id, structure_id, location_name, report_group, soft_deleted
        FROM moa_locations
        WHERE moa_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$moaId]);
    $locations = array_map(function ($location) {
        return [
            'id' => (int) $location['id'],
            'structure_id' => $location['structure_id'] !== null ? (int) $location['structure_id'] : null,
            'location_name' => $location['location_name'],
            'report_group' => $location['report_group'],
            'soft_deleted' => (int) $location['soft_deleted'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $db->prepare("
        SELECT id, user_id, location_id, share_percentage
        FROM moa_share
        WHERE moa_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$moaId]);
    $shares = array_map(function ($share) {
        return [
            'id' => (int) $share['id'],
            'user_id' => (int) $share['user_id'],
            'location_id' => (int) $share['location_id'],
            'share_percentage' => (float) $share['share_percentage'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    return [
        'id' => (int) $moa['id'],
        'moa_name' => $moa['moa_name'],
        'soft_deleted' => (int) $moa['soft_deleted'],
        'created_at' => $moa['created_at'],
        'location_count' => count($locations),
        'location_ids' => array_map(fn($location) => $location['id'], $locations),
        'share_count' => count($shares),
        'share_ids' => array_map(fn($share) => $share['id'], $shares),
        'locations' => $locations,
        'shares' => $shares,
    ];
}

try {
    $db = Database::connect();
    $admin = require_admin($db);
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request body']);
        exit;
    }

    if (isset($input['delete_moa_id'])) {
        $moaId = (int) $input['delete_moa_id'];

        if ($moaId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid MOA ID']);
            exit;
        }

        $oldValues = fetchMoaAuditSummary($db, $moaId);

        $db->beginTransaction();

        $stmt = $db->prepare("
            SELECT id
            FROM moa_locations
            WHERE moa_id = ? AND soft_deleted = 0
        ");
        $stmt->execute([$moaId]);
        $locationIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($locationIds)) {
            $placeholders = implode(',', array_fill(0, count($locationIds), '?'));

            $stmt = $db->prepare("
                SELECT id
                FROM moa_share
                WHERE moa_id = ?
                  AND location_id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$moaId], $locationIds));
            $moaShareIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($moaShareIds)) {
                $sharePlaceholders = implode(',', array_fill(0, count($moaShareIds), '?'));

                $stmt = $db->prepare("
                    DELETE FROM moa_jv_expenses
                    WHERE moa_share_id IN ($sharePlaceholders)
                ");
                $stmt->execute($moaShareIds);
            }

            $stmt = $db->prepare("
                DELETE FROM moa_share
                WHERE moa_id = ?
                  AND location_id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$moaId], $locationIds));

            $stmt = $db->prepare("
                UPDATE moa_locations
                SET soft_deleted = 1
                WHERE moa_id = ?
                  AND id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$moaId], $locationIds));
        }

        $stmt = $db->prepare("
            UPDATE moa
            SET soft_deleted = 1
            WHERE id = ?
        ");
        $stmt->execute([$moaId]);

        $db->commit();

        $newValues = fetchMoaAuditSummary($db, $moaId);

        logAudit(
            $db,
            (int) $admin['id'],
            'DELETE_MOA',
            'MOA',
            'moa',
            (string) $moaId,
            $oldValues,
            $newValues,
            'Admin soft-deleted MOA'
        );

        echo json_encode(['success' => true]);
        exit;
    }

    if (
        !isset($input['moa_name']) ||
        !isset($input['locations']) ||
        !is_array($input['locations'])
    ) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing fields']);
        exit;
    }

    $moaId = isset($input['moa_id']) ? (int) $input['moa_id'] : null;
    $isCreate = !$moaId;
    $moaName = trim((string) $input['moa_name']);
    $locations = $input['locations'];

    if ($moaName === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid MOA name']);
        exit;
    }

    $oldValues = $isCreate ? null : fetchMoaAuditSummary($db, (int) $moaId);

    $db->beginTransaction();

    if ($moaId) {
        $stmt = $db->prepare("
            UPDATE moa
            SET moa_name = ?
            WHERE id = ?
        ");
        $stmt->execute([$moaName, $moaId]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO moa (moa_name)
            VALUES (?)
        ");
        $stmt->execute([$moaName]);
        $moaId = (int) $db->lastInsertId();
    }

    $stmt = $db->prepare("
        SELECT id
        FROM moa_locations
        WHERE moa_id = ?
          AND soft_deleted = 0
    ");
    $stmt->execute([$moaId]);
    $existingLocationIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $stmt = $db->prepare("
        SELECT id, structure_id
        FROM moa_locations
        WHERE moa_id = ?
        ORDER BY soft_deleted ASC, id ASC
    ");
    $stmt->execute([$moaId]);
    $existingLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $existingLocationById = [];
    $existingLocationByStructureId = [];

    foreach ($existingLocations as $existingLocation) {
        $existingId = (int) $existingLocation['id'];
        $existingLocationById[$existingId] = $existingId;

        if ($existingLocation['structure_id'] !== null) {
            $existingStructureId = (int) $existingLocation['structure_id'];

            if (!isset($existingLocationByStructureId[$existingStructureId])) {
                $existingLocationByStructureId[$existingStructureId] = $existingId;
            }
        }
    }

    $incomingLocationIds = [];

    $insertLocation = $db->prepare("
        INSERT INTO moa_locations (
            moa_id,
            structure_id,
            location_name,
            report_group,
            soft_deleted
        ) VALUES (?, ?, ?, ?, 0)
    ");

    $updateLocation = $db->prepare("
        UPDATE moa_locations
        SET structure_id = ?,
            location_name = ?,
            report_group = ?,
            soft_deleted = 0
        WHERE id = ?
          AND moa_id = ?
    ");

    foreach ($locations as $loc) {
        $locId = isset($loc['id']) ? (int) $loc['id'] : null;
        $structureId = isset($loc['structure_id']) && $loc['structure_id'] !== ''
            ? (int) $loc['structure_id']
            : null;
        $locationName = trim((string) ($loc['location_name'] ?? $loc['name'] ?? ''));
        $reportGroup = trim((string) ($loc['report_group'] ?? ''));

        if ($locationName === '') {
            continue;
        }

        if ($locId && isset($existingLocationById[$locId])) {
            $updateLocation->execute([$structureId, $locationName, $reportGroup, $locId, $moaId]);
        } elseif ($structureId && isset($existingLocationByStructureId[$structureId])) {
            $locId = $existingLocationByStructureId[$structureId];
            $updateLocation->execute([$structureId, $locationName, $reportGroup, $locId, $moaId]);
        } else {
            $insertLocation->execute([$moaId, $structureId, $locationName, $reportGroup]);
            $locId = (int) $db->lastInsertId();
        }

        $incomingLocationIds[] = $locId;

        $jvUsers = isset($loc['jv_users']) && is_array($loc['jv_users']) ? $loc['jv_users'] : [];

        $totalShare = 0;
        foreach ($jvUsers as $jv) {
            $totalShare += (float) ($jv['share_percentage'] ?? 0);
        }

        if (round($totalShare, 4) >= 100) {
            throw new Exception('Total JV share must be less than 100% per location');
        }

        $stmt = $db->prepare("
            SELECT id, user_id
            FROM moa_share
            WHERE moa_id = ?
              AND location_id = ?
        ");
        $stmt->execute([$moaId, $locId]);
        $existingShares = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $existingShareMap = [];
        foreach ($existingShares as $shareRow) {
            $existingShareMap[(int) $shareRow['user_id']] = (int) $shareRow['id'];
        }

        $incomingUserIds = [];

        $insertShare = $db->prepare("
            INSERT INTO moa_share (
                moa_id,
                user_id,
                location_id,
                share_percentage
            ) VALUES (?, ?, ?, ?)
        ");

        $updateShare = $db->prepare("
            UPDATE moa_share
            SET share_percentage = ?
            WHERE moa_id = ?
              AND location_id = ?
              AND user_id = ?
        ");

        foreach ($jvUsers as $jv) {
            $userId = (int) ($jv['id'] ?? 0);
            $sharePercentage = (float) ($jv['share_percentage'] ?? 0);

            if ($userId <= 0) {
                continue;
            }

            $incomingUserIds[] = $userId;

            if (isset($existingShareMap[$userId])) {
                $updateShare->execute([$sharePercentage, $moaId, $locId, $userId]);
            } else {
                $insertShare->execute([$moaId, $userId, $locId, $sharePercentage]);
            }
        }

        $existingUserIds = array_keys($existingShareMap);
        $userIdsToDelete = array_diff($existingUserIds, $incomingUserIds);

        if (!empty($userIdsToDelete)) {
            $shareIdsToDelete = [];

            foreach ($userIdsToDelete as $userIdToDelete) {
                if (isset($existingShareMap[(int) $userIdToDelete])) {
                    $shareIdsToDelete[] = $existingShareMap[(int) $userIdToDelete];
                }
            }

            if (!empty($shareIdsToDelete)) {
                $sharePlaceholders = implode(',', array_fill(0, count($shareIdsToDelete), '?'));

                $stmt = $db->prepare("
                    DELETE FROM moa_jv_expenses
                    WHERE moa_share_id IN ($sharePlaceholders)
                ");
                $stmt->execute($shareIdsToDelete);

                $stmt = $db->prepare("
                    DELETE FROM moa_share
                    WHERE id IN ($sharePlaceholders)
                ");
                $stmt->execute($shareIdsToDelete);
            }
        }
    }

    $locationIdsToDelete = array_diff($existingLocationIds, $incomingLocationIds);

    if (!empty($locationIdsToDelete)) {
        $locationPlaceholders = implode(',', array_fill(0, count($locationIdsToDelete), '?'));

        $stmt = $db->prepare("
            SELECT id
            FROM moa_share
            WHERE moa_id = ?
              AND location_id IN ($locationPlaceholders)
        ");
        $stmt->execute(array_merge([$moaId], $locationIdsToDelete));
        $shareIdsToDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($shareIdsToDelete)) {
            $sharePlaceholders = implode(',', array_fill(0, count($shareIdsToDelete), '?'));

            $stmt = $db->prepare("
                DELETE FROM moa_jv_expenses
                WHERE moa_share_id IN ($sharePlaceholders)
            ");
            $stmt->execute($shareIdsToDelete);

            $stmt = $db->prepare("
                DELETE FROM moa_share
                WHERE id IN ($sharePlaceholders)
            ");
            $stmt->execute($shareIdsToDelete);
        }

        $stmt = $db->prepare("
            UPDATE moa_locations
            SET soft_deleted = 1
            WHERE moa_id = ?
              AND id IN ($locationPlaceholders)
        ");
        $stmt->execute(array_merge([$moaId], $locationIdsToDelete));
    }

    $db->commit();

    $newValues = fetchMoaAuditSummary($db, (int) $moaId);

    logAudit(
        $db,
        (int) $admin['id'],
        $isCreate ? 'CREATE_MOA' : 'UPDATE_MOA',
        'MOA',
        'moa',
        (string) $moaId,
        $oldValues,
        $newValues,
        $isCreate ? 'Admin created MOA' : 'Admin updated MOA'
    );

    echo json_encode([
        'success' => true,
        'moa_id' => $moaId,
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ]);
}
