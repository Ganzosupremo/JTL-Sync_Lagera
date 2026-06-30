<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

final class ProductSkuAlias
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    public function resolve(string $packiyoCustomerId, string $aliasSku): ?string
    {
        $packiyoCustomerId = trim($packiyoCustomerId);
        $aliasSku = trim($aliasSku);

        if ($packiyoCustomerId === '' || $aliasSku === '') {
            return null;
        }

        $statement = $this->connection()->prepare(
            'SELECT original_sku FROM packiyo_sku_aliases
            WHERE packiyo_customer_id = ? AND alias_sku = ? AND active = 1
            LIMIT 1'
        );
        $statement->bind_param('ss', $packiyoCustomerId, $aliasSku);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();

        if (!is_array($row) || trim((string) ($row['original_sku'] ?? '')) === '') {
            return null;
        }

        return (string) $row['original_sku'];
    }

    /** @return array<int, array<string, mixed>> */
    public function allForCustomer(string $packiyoCustomerId, int $limit = 500): array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM packiyo_sku_aliases
            WHERE packiyo_customer_id = ?
            ORDER BY original_sku ASC, alias_sku ASC
            LIMIT ?'
        );
        $statement->bind_param('si', $packiyoCustomerId, $limit);
        $statement->execute();

        return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** @param array<string, mixed> $data */
    public function upsert(array $data): void
    {
        $now = date('Y-m-d H:i:s');
        $packiyoCustomerId = trim((string) ($data['packiyo_customer_id'] ?? ''));
        $packiyoProductId = trim((string) ($data['packiyo_product_id'] ?? ''));
        $originalSku = trim((string) ($data['original_sku'] ?? ''));
        $aliasSku = trim((string) ($data['alias_sku'] ?? ''));
        $productName = trim((string) ($data['product_name'] ?? ''));
        $active = !empty($data['active']) ? 1 : 0;

        $statement = $this->connection()->prepare(
            'INSERT INTO packiyo_sku_aliases (
                packiyo_customer_id,
                packiyo_product_id,
                original_sku,
                alias_sku,
                product_name,
                active,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                packiyo_product_id = VALUES(packiyo_product_id),
                original_sku = VALUES(original_sku),
                product_name = VALUES(product_name),
                active = VALUES(active),
                updated_at = VALUES(updated_at)'
        );
        $statement->bind_param(
            'sssssiss',
            $packiyoCustomerId,
            $packiyoProductId,
            $originalSku,
            $aliasSku,
            $productName,
            $active,
            $now,
            $now
        );
        $statement->execute();
    }

    public function delete(int $id): void
    {
        $statement = $this->connection()->prepare('DELETE FROM packiyo_sku_aliases WHERE id = ?');
        $statement->bind_param('i', $id);
        $statement->execute();
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
