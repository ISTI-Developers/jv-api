<?php

function logAudit(
    PDO $db,
    int $userId,
    string $action,
    ?string $module = null,
    ?string $entityType = null,
    ?string $entityId = null,
    ?array $old = null,
    ?array $new = null,
    ?string $description = null
) {
    $stmt = $db->prepare("INSERT INTO audit_logs (
            user_id, action, module, entity_type, entity_id,
            description, old_values, new_values,
            ip_address, user_agent
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $userId,
        $action,
        $module,
        $entityType,
        $entityId,
        $description,
        $old ? json_encode($old) : null,
        $new ? json_encode($new) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
}
