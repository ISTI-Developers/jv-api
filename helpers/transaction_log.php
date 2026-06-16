<?php

function logTransaction(PDO $db, array $data): void
{
    $stmt = $db->prepare("
        INSERT INTO transaction_logs (
            transaction_type,
            moa_id,
            moa_share_id,
            structure_id,
            account_no,
            amount,
            reference_table,
            reference_id,
            reference_no,
            action,
            status,
            description,
            metadata,
            performed_by
        ) VALUES (
            :transaction_type,
            :moa_id,
            :moa_share_id,
            :structure_id,
            :account_no,
            :amount,
            :reference_table,
            :reference_id,
            :reference_no,
            :action,
            :status,
            :description,
            :metadata,
            :performed_by
        )
    ");

    $metadata = $data['metadata'] ?? null;

    $stmt->execute([
        ':transaction_type' => $data['transaction_type'],
        ':moa_id' => $data['moa_id'] ?? null,
        ':moa_share_id' => $data['moa_share_id'] ?? null,
        ':structure_id' => $data['structure_id'] ?? null,
        ':account_no' => $data['account_no'] ?? null,
        ':amount' => $data['amount'] ?? null,
        ':reference_table' => $data['reference_table'],
        ':reference_id' => $data['reference_id'] ?? null,
        ':reference_no' => $data['reference_no'] ?? null,
        ':action' => $data['action'],
        ':status' => $data['status'] ?? null,
        ':description' => $data['description'] ?? null,
        ':metadata' => $metadata !== null ? json_encode($metadata) : null,
        ':performed_by' => $data['performed_by'],
    ]);
}
