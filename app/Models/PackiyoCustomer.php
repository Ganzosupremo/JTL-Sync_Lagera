<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

final class PackiyoCustomer
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    /** @return array{active: int, inactive: int, total: int} */
    public function counts(): array
    {
        $result = $this->connection()->query(
            'SELECT
                SUM(active = 1) AS active_count,
                SUM(active = 0) AS inactive_count,
                COUNT(*) AS total_count
            FROM packiyo_customers'
        );
        $row = $result->fetch_assoc() ?: [];

        return [
            'active' => (int) ($row['active_count'] ?? 0),
            'inactive' => (int) ($row['inactive_count'] ?? 0),
            'total' => (int) ($row['total_count'] ?? 0),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function listByActive(bool $active): array
    {
        $activeValue = $active ? 1 : 0;
        $statement = $this->connection()->prepare(
            'SELECT * FROM packiyo_customers
            WHERE active = ?
            ORDER BY COALESCE(name, company_name, email, packiyo_customer_id) ASC
            LIMIT 500'
        );
        $statement->bind_param('i', $activeValue);
        $statement->execute();

        return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function upsertFromApi(array $resource): ?string
    {
        $customerId = $this->resourceId($resource);

        if ($customerId === null) {
            return null;
        }

        $attributes = $this->attributes($resource);
        $name = $this->firstString($attributes, ['name', 'Name', 'full_name', 'fullName']);
        $email = $this->firstString($attributes, ['email', 'Email']);
        $company = $this->firstString($attributes, ['company_name', 'companyName', 'company', 'Company']);
        $remoteCreatedAt = $this->dateString($this->firstString($attributes, ['created_at', 'createdAt']));
        $remoteUpdatedAt = $this->dateString($this->firstString($attributes, ['updated_at', 'updatedAt']));
        $rawAttributes = json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rawAttributes = is_string($rawAttributes) ? $rawAttributes : null;
        $now = date('Y-m-d H:i:s');

        $statement = $this->connection()->prepare(
            'INSERT INTO packiyo_customers (
                packiyo_customer_id,
                name,
                email,
                company_name,
                raw_attributes,
                packiyo_created_at,
                packiyo_updated_at,
                synced_at,
                active,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                email = VALUES(email),
                company_name = VALUES(company_name),
                raw_attributes = VALUES(raw_attributes),
                packiyo_created_at = VALUES(packiyo_created_at),
                packiyo_updated_at = VALUES(packiyo_updated_at),
                synced_at = VALUES(synced_at),
                updated_at = VALUES(updated_at)'
        );
        $statement->bind_param(
            'ssssssssss',
            $customerId,
            $name,
            $email,
            $company,
            $rawAttributes,
            $remoteCreatedAt,
            $remoteUpdatedAt,
            $now,
            $now,
            $now
        );
        $statement->execute();

        return $remoteUpdatedAt ?? $now;
    }

    public function setActive(string $customerId, bool $active): void
    {
        $activeValue = $active ? 1 : 0;
        $now = date('Y-m-d H:i:s');
        $statement = $this->connection()->prepare(
            'UPDATE packiyo_customers SET active = ?, updated_at = ? WHERE packiyo_customer_id = ?'
        );
        $statement->bind_param('iss', $activeValue, $now, $customerId);
        $statement->execute();
    }

    public function isKnownInactive(string $customerId): bool
    {
        $statement = $this->connection()->prepare(
            'SELECT active FROM packiyo_customers WHERE packiyo_customer_id = ? LIMIT 1'
        );
        $statement->bind_param('s', $customerId);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();

        return is_array($row) && (int) ($row['active'] ?? 1) === 0;
    }

    private function resourceId(array $resource): ?string
    {
        $id = $resource['id'] ?? $resource['Id'] ?? null;

        return is_scalar($id) && trim((string) $id) !== '' ? (string) $id : null;
    }

    private function attributes(array $resource): array
    {
        $attributes = $resource['attributes'] ?? $resource['Attributes'] ?? [];

        return is_array($attributes) ? $attributes : $resource;
    }

    private function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return (string) $data[$key];
            }
        }

        return null;
    }

    private function dateString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
