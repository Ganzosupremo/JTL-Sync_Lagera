<?php

declare(strict_types=1);

namespace App\Services;

use App\Clients\PackiyoClient;
use App\Models\ProductSkuAlias;
use RuntimeException;

final class ProductSkuAliasService
{
    public function __construct(
        private readonly ?PackiyoClient $packiyo = null,
        private readonly ?ProductSkuAlias $aliases = null
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function preview(string $customerId): array
    {
        $customerId = trim($customerId);

        if ($customerId === '') {
            return [];
        }

        $aliasesBySku = $this->aliasesByOriginalSku($customerId);
        $rows = [];

        foreach ($this->packiyoClient()->listProductsForCustomer($customerId) as $product) {
            $row = $this->productRow($product, $customerId);

            if ($row['sku'] === '') {
                continue;
            }

            $existingAliases = $aliasesBySku[strtolower($row['sku'])] ?? [];
            $existingValues = array_map(
                static fn (array $alias): string => strtolower((string) ($alias['alias_sku'] ?? '')),
                $existingAliases
            );
            $suggestedAliases = array_values(array_filter(
                $this->commonAliases($row['sku']),
                static fn (string $alias): bool => !in_array(strtolower($alias), $existingValues, true)
            ));

            $row['aliases'] = $existingAliases;
            $row['suggested_aliases'] = $suggestedAliases;
            $rows[] = $row;
        }

        return $rows;
    }

    public function generateCommonAliases(string $customerId, string $productId, string $originalSku, ?string $productName = null): int
    {
        $customerId = trim($customerId);
        $originalSku = trim($originalSku);

        if ($customerId === '' || $originalSku === '') {
            throw new RuntimeException('Cliente Packiyo y SKU original son requeridos.');
        }

        $created = 0;

        foreach ($this->commonAliases($originalSku) as $aliasSku) {
            $this->aliasModel()->upsert([
                'packiyo_customer_id' => $customerId,
                'packiyo_product_id' => $productId,
                'original_sku' => $originalSku,
                'alias_sku' => $aliasSku,
                'product_name' => $productName ?? '',
                'active' => true,
            ]);
            $created++;
        }

        return $created;
    }

    /** @return array{products: int, aliases: int, skipped: int} */
    public function generateCommonAliasesForCustomer(string $customerId): array
    {
        $customerId = trim($customerId);

        if ($customerId === '') {
            throw new RuntimeException('Cliente Packiyo requerido.');
        }

        $summary = [
            'products' => 0,
            'aliases' => 0,
            'skipped' => 0,
        ];

        foreach ($this->packiyoClient()->listProductsForCustomer($customerId) as $product) {
            $row = $this->productRow($product, $customerId);

            if ($row['sku'] === '') {
                $summary['skipped']++;
                continue;
            }

            $aliases = $this->generateCommonAliases(
                $customerId,
                $row['packiyo_product_id'],
                $row['sku'],
                $row['name']
            );

            if ($aliases === 0) {
                $summary['skipped']++;
                continue;
            }

            $summary['products']++;
            $summary['aliases'] += $aliases;
        }

        return $summary;
    }

    /** @return array<int, string> */
    public function commonAliases(string $originalSku): array
    {
        $originalSku = trim($originalSku);

        if ($originalSku === '') {
            return [];
        }

        $aliases = [];

        if (str_starts_with($originalSku, '0')) {
            $withoutFirstZero = substr($originalSku, 1);
            $withoutLeadingZeros = ltrim($originalSku, '0');

            if ($withoutFirstZero !== '') {
                $aliases[] = $withoutFirstZero;
            }

            if ($withoutLeadingZeros !== '') {
                $aliases[] = $withoutLeadingZeros;
            }
        }

        $aliases[] = $originalSku . '_';
        $filtered = [];

        foreach (array_values(array_unique($aliases)) as $alias) {
            if ($alias !== $originalSku && trim($alias) !== '') {
                $filtered[] = $alias;
            }
        }

        return $filtered;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function aliasesByOriginalSku(string $customerId): array
    {
        $grouped = [];

        foreach ($this->aliasModel()->allForCustomer($customerId) as $alias) {
            $originalSku = strtolower(trim((string) ($alias['original_sku'] ?? '')));

            if ($originalSku === '') {
                continue;
            }

            $grouped[$originalSku][] = $alias;
        }

        return $grouped;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function productRow(array $product, string $customerId): array
    {
        $attributes = $this->attributes($product);

        return [
            'packiyo_product_id' => $this->resourceId($product),
            'packiyo_customer_id' => $customerId,
            'sku' => $this->firstScalar($attributes, ['sku', 'SKU']) ?? '',
            'name' => $this->firstScalar($attributes, ['name', 'Name']) ?? '',
        ];
    }

    /** @param array<string, mixed> $resource */
    private function resourceId(array $resource): string
    {
        $id = $resource['id'] ?? $resource['Id'] ?? '';

        return is_scalar($id) ? (string) $id : '';
    }

    /** @param array<string, mixed> $resource */
    private function attributes(array $resource): array
    {
        $attributes = $resource['attributes'] ?? $resource['Attributes'] ?? [];

        return is_array($attributes) ? $attributes : [];
    }

    /** @param array<string, mixed> $data */
    private function firstScalar(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return (string) $data[$key];
            }
        }

        return null;
    }

    private function packiyoClient(): PackiyoClient
    {
        return $this->packiyo ?? new PackiyoClient();
    }

    private function aliasModel(): ProductSkuAlias
    {
        return $this->aliases ?? new ProductSkuAlias();
    }
}
