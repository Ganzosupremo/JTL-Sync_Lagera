<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

final class AppSetting
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $result = $this->connection()->query('SELECT setting_key, setting_value FROM app_settings');
        $settings = [];

        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        return $settings;
    }

    public function get(string $key): ?string
    {
        $statement = $this->connection()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
        $statement->bind_param('s', $key);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();

        if (!is_array($row) || !array_key_exists('setting_value', $row)) {
            return null;
        }

        return (string) ($row['setting_value'] ?? '');
    }

    /** @param array<string, string> $settings */
    public function upsertMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->upsert($key, $value);
        }
    }

    public function upsert(string $key, string $value): void
    {
        $now = date('Y-m-d H:i:s');
        $statement = $this->connection()->prepare(
            'INSERT INTO app_settings (
                setting_key,
                setting_value,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_at = VALUES(updated_at)'
        );
        $statement->bind_param('ssss', $key, $value, $now, $now);
        $statement->execute();
    }

    public function delete(string $key): void
    {
        $statement = $this->connection()->prepare('DELETE FROM app_settings WHERE setting_key = ?');
        $statement->bind_param('s', $key);
        $statement->execute();
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
