<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

final class AppSyncState
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        $statement = $this->connection()->prepare('SELECT * FROM app_sync_states WHERE sync_key = ? LIMIT 1');
        $statement->bind_param('s', $key);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();

        return is_array($row) ? $row : null;
    }

    public function lastSyncedAt(string $key): ?string
    {
        $state = $this->get($key);
        $value = $state['last_synced_at'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function markSuccess(string $key, ?string $lastSyncedAt, string $message): void
    {
        $now = date('Y-m-d H:i:s');
        $statement = $this->connection()->prepare(
            'INSERT INTO app_sync_states (
                sync_key,
                last_synced_at,
                last_success_at,
                last_message,
                updated_at
            ) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                last_synced_at = VALUES(last_synced_at),
                last_success_at = VALUES(last_success_at),
                last_message = VALUES(last_message),
                updated_at = VALUES(updated_at)'
        );
        $statement->bind_param('sssss', $key, $lastSyncedAt, $now, $message, $now);
        $statement->execute();
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
