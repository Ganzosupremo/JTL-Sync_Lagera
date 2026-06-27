<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

final class ProductMapping
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByPackiyoProductId(string $packiyoProductId): ?array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM product_mappings WHERE packiyo_product_id = ? LIMIT 1'
        );
        $statement->bind_param('s', $packiyoProductId);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findBySku(string $sku): ?array
    {
        $statement = $this->connection()->prepare('SELECT * FROM product_mappings WHERE sku = ? LIMIT 1');
        $statement->bind_param('s', $sku);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();

        return is_array($row) ? $row : null;
    }

    public function upsert(
        string $packiyoProductId,
        string $packiyoCustomerId,
        string $sku,
        string $jtlItemId,
        ?string $jtlItemNumber,
        ?string $productName,
        string $status
    ): void {
        $now = date('Y-m-d H:i:s');
        $statement = $this->connection()->prepare(
            'INSERT INTO product_mappings (
                packiyo_product_id,
                packiyo_customer_id,
                sku,
                jtl_item_id,
                jtl_item_number,
                product_name,
                status,
                imported_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                packiyo_customer_id = VALUES(packiyo_customer_id),
                sku = VALUES(sku),
                jtl_item_id = VALUES(jtl_item_id),
                jtl_item_number = VALUES(jtl_item_number),
                product_name = VALUES(product_name),
                status = VALUES(status),
                updated_at = VALUES(updated_at)'
        );
        $statement->bind_param(
            'sssssssss',
            $packiyoProductId,
            $packiyoCustomerId,
            $sku,
            $jtlItemId,
            $jtlItemNumber,
            $productName,
            $status,
            $now,
            $now
        );
        $statement->execute();
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM product_mappings ORDER BY updated_at DESC, id DESC LIMIT ?'
        );
        $statement->bind_param('i', $limit);
        $statement->execute();

        return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
