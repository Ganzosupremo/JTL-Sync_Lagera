<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

final class PackiyoProductCatalogCache
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    /** @return array<int, array{id:string,sku:string,name:string}> */
    public function allForCustomer(string $customerId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT packiyo_product_id, sku, product_name
             FROM packiyo_product_catalog_cache
             WHERE packiyo_customer_id = ? ORDER BY product_name ASC, sku ASC'
        );
        $statement->bind_param('s', $customerId);
        $statement->execute();
        $rows = [];
        foreach ($statement->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $rows[] = [
                'id' => (string) ($row['packiyo_product_id'] ?? ''),
                'sku' => (string) ($row['sku'] ?? ''),
                'name' => (string) ($row['product_name'] ?? ''),
            ];
        }
        return $rows;
    }

    /** @param array<int, array{id:string,sku:string,name:string}> $products */
    public function replaceForCustomer(string $customerId, array $products): void
    {
        $db = $this->connection();
        $db->begin_transaction();
        try {
            $delete = $db->prepare('DELETE FROM packiyo_product_catalog_cache WHERE packiyo_customer_id = ?');
            $delete->bind_param('s', $customerId);
            $delete->execute();

            $insert = $db->prepare(
                'INSERT INTO packiyo_product_catalog_cache (
                    packiyo_customer_id, packiyo_product_id, sku, product_name, synced_at
                ) VALUES (?, ?, ?, ?, ?)'
            );
            $now = date('Y-m-d H:i:s');
            foreach ($products as $product) {
                $id = (string) ($product['id'] ?? '');
                $sku = (string) ($product['sku'] ?? '');
                $name = (string) ($product['name'] ?? $sku);
                $insert->bind_param('sssss', $customerId, $id, $sku, $name, $now);
                $insert->execute();
            }
            $db->commit();
        } catch (\Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
