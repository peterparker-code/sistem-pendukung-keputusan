<?php

declare(strict_types=1);

/**
 * Helper audit trail aplikasi.
 * Logging dibuat best-effort: kegagalan menulis log tidak membatalkan transaksi bisnis.
 */

function audit_table_exists(PDO $pdo, string $tableName): bool
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . $tableName;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name"
        );
        $statement->execute(['name' => $tableName]);
        $cache[$cacheKey] = (int) $statement->fetchColumn() === 1;
    } catch (Throwable $exception) {
        error_log('Pemeriksaan tabel audit gagal: ' . $exception->getMessage());
        $cache[$cacheKey] = false;
    }

    return $cache[$cacheKey];
}

function audit_client_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== ''
        ? (function_exists('mb_substr') ? mb_substr($ip, 0, 64) : substr($ip, 0, 64))
        : 'unknown';
}

function audit_user_agent(): string
{
    $agent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return $agent !== ''
        ? (function_exists('mb_substr') ? mb_substr($agent, 0, 500) : substr($agent, 0, 500))
        : '';
}

function audit_json(?array $data): ?string
{
    if ($data === null) {
        return null;
    }

    $encoded = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    return is_string($encoded) ? $encoded : null;
}

function audit_log(
    PDO $pdo,
    ?int $actorId,
    string $action,
    string $entityType,
    ?int $entityId = null,
    ?string $description = null,
    ?array $oldData = null,
    ?array $newData = null,
    ?string $actorNameOverride = null,
    ?string $actorRoleOverride = null
): void {
    if (!audit_table_exists($pdo, 'audit_logs')) {
        return;
    }

    $action = trim($action);
    $entityType = trim($entityType);
    if ($action === '' || $entityType === '') {
        return;
    }

    $actorName = $actorNameOverride !== null ? trim($actorNameOverride) : '';
    $actorRole = $actorRoleOverride !== null ? trim($actorRoleOverride) : '';

    try {
        if ($actorId !== null && $actorId > 0 && ($actorName === '' || $actorRole === '')) {
            $userStatement = $pdo->prepare(
                'SELECT name, role FROM users WHERE id = :id LIMIT 1'
            );
            $userStatement->execute(['id' => $actorId]);
            $user = $userStatement->fetch();
            if (is_array($user)) {
                $actorName = $actorName !== '' ? $actorName : trim((string) ($user['name'] ?? ''));
                $actorRole = $actorRole !== '' ? $actorRole : trim((string) ($user['role'] ?? ''));
            }
        }

        $statement = $pdo->prepare(
            "INSERT INTO audit_logs (
                actor_id, actor_name, actor_role, action, entity_type, entity_id,
                description, old_data, new_data, ip_address, user_agent, created_at
            ) VALUES (
                :actor_id, :actor_name, :actor_role, :action, :entity_type, :entity_id,
                :description, :old_data, :new_data, :ip_address, :user_agent,
                datetime('now', '+7 hours')
            )"
        );

        $statement->execute([
            'actor_id' => ($actorId !== null && $actorId > 0) ? $actorId : null,
            'actor_name' => $actorName !== '' ? $actorName : null,
            'actor_role' => $actorRole !== '' ? $actorRole : null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => ($entityId !== null && $entityId > 0) ? $entityId : null,
            'description' => $description !== null && trim($description) !== '' ? trim($description) : null,
            'old_data' => audit_json($oldData),
            'new_data' => audit_json($newData),
            'ip_address' => audit_client_ip(),
            'user_agent' => audit_user_agent(),
        ]);
    } catch (Throwable $exception) {
        error_log('Audit trail gagal ditulis: ' . $exception->getMessage());
    }
}
