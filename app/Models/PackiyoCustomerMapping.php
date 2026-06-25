<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

final class PackiyoCustomerMapping
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $result = $this->connection()->query(
            'SELECT * FROM packiyo_customer_mappings ORDER BY active DESC, priority ASC, match_type ASC, match_value ASC'
        );

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function upsert(array $data): void
    {
        $now = date('Y-m-d H:i:s');
        $matchType = $this->normalizeType((string) ($data['match_type'] ?? ''));
        $matchValue = $this->normalizeValue((string) ($data['match_value'] ?? ''));
        $packiyoCustomerId = trim((string) ($data['packiyo_customer_id'] ?? ''));
        $packiyoCustomerName = trim((string) ($data['packiyo_customer_name'] ?? ''));
        $priority = (int) ($data['priority'] ?? 100);
        $active = !empty($data['active']) ? 1 : 0;

        $statement = $this->connection()->prepare(
            'INSERT INTO packiyo_customer_mappings (
                match_type,
                match_value,
                packiyo_customer_id,
                packiyo_customer_name,
                priority,
                active,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                packiyo_customer_id = VALUES(packiyo_customer_id),
                packiyo_customer_name = VALUES(packiyo_customer_name),
                priority = VALUES(priority),
                active = VALUES(active),
                updated_at = VALUES(updated_at)'
        );
        $statement->bind_param(
            'ssssiiss',
            $matchType,
            $matchValue,
            $packiyoCustomerId,
            $packiyoCustomerName,
            $priority,
            $active,
            $now,
            $now
        );
        $statement->execute();
    }

    public function delete(int $id): void
    {
        $statement = $this->connection()->prepare('DELETE FROM packiyo_customer_mappings WHERE id = ?');
        $statement->bind_param('i', $id);
        $statement->execute();
    }

    /**
     * @param array<string, array<int, string>> $candidates
     * @return array<string, mixed>|null
     */
    public function findForCandidates(array $candidates): ?array
    {
        foreach ($this->all() as $mapping) {
            if ((int) ($mapping['active'] ?? 0) !== 1) {
                continue;
            }

            $matchType = (string) ($mapping['match_type'] ?? '');
            $matchValue = $this->normalizeValue((string) ($mapping['match_value'] ?? ''));

            if ($matchType === 'default') {
                return $mapping;
            }

            foreach (($candidates[$matchType] ?? []) as $candidate) {
                if ($this->normalizeValue($candidate) === $matchValue) {
                    return $mapping;
                }
            }
        }

        return null;
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        return $type !== '' ? $type : 'default';
    }

    private function normalizeValue(string $value): string
    {
        return strtolower(trim($value));
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
