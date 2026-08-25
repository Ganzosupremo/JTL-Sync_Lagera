<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

final class ProductNameMapping
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $customerId, string $normalizedName): ?array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM packiyo_product_name_mappings
             WHERE packiyo_customer_id = ? AND normalized_source_name = ? AND active = 1 LIMIT 1'
        );
        $statement->bind_param('ss', $customerId, $normalizedName);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function allForCustomer(string $customerId, int $limit = 500): array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM packiyo_product_name_mappings WHERE packiyo_customer_id = ?
             ORDER BY source_name ASC LIMIT ?'
        );
        $statement->bind_param('si', $customerId, $limit);
        $statement->execute();
        return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** @param array<string, mixed> $data */
    public function upsert(array $data): void
    {
        $now = date('Y-m-d H:i:s');
        $customerId = trim((string) ($data['packiyo_customer_id'] ?? ''));
        $sourceName = trim((string) ($data['source_name'] ?? ''));
        $normalizedName = trim((string) ($data['normalized_source_name'] ?? ''));
        $productId = trim((string) ($data['packiyo_product_id'] ?? ''));
        $sku = trim((string) ($data['packiyo_sku'] ?? ''));
        $productName = trim((string) ($data['packiyo_product_name'] ?? ''));
        $active = 1;
        $statement = $this->connection()->prepare(
            'INSERT INTO packiyo_product_name_mappings (
                packiyo_customer_id, source_name, normalized_source_name, packiyo_product_id,
                packiyo_sku, packiyo_product_name, active, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE packiyo_product_id = VALUES(packiyo_product_id),
                packiyo_sku = VALUES(packiyo_sku), packiyo_product_name = VALUES(packiyo_product_name),
                source_name = VALUES(source_name), active = 1, updated_at = VALUES(updated_at)'
        );
        $statement->bind_param('ssssssiss', $customerId, $sourceName, $normalizedName, $productId, $sku, $productName, $active, $now, $now);
        $statement->execute();
    }

    public function delete(int $id): void
    {
        $statement = $this->connection()->prepare('DELETE FROM packiyo_product_name_mappings WHERE id = ?');
        $statement->bind_param('i', $id);
        $statement->execute();
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
