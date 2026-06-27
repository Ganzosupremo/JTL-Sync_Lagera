<?php

declare(strict_types=1);

namespace App\Services;

use App\Clients\JtlClient;
use App\Clients\PackiyoClient;
use App\Models\ProductMapping;
use App\Support\Config;
use RuntimeException;

final class ProductImportService
{
    public function __construct(
        private readonly ?PackiyoClient $packiyo = null,
        private readonly ?JtlClient $jtl = null,
        private readonly ?ProductMapping $mappings = null
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function preview(string $customerId): array
    {
        $rows = [];

        foreach ($this->packiyoClient()->listProductsForCustomer($customerId) as $product) {
            $row = $this->row($product, $customerId);
            $mapping = $this->mappingModel()->findByPackiyoProductId($row['packiyo_product_id']);

            if ($mapping === null && $row['sku'] !== '') {
                $mapping = $this->mappingModel()->findBySku($row['sku']);
            }

            if ($mapping !== null) {
                $row['status'] = 'importado';
                $row['jtl_item_id'] = (string) ($mapping['jtl_item_id'] ?? '');
            } elseif ($row['archived']) {
                $row['status'] = 'archivado';
            } elseif ($row['sku'] === '') {
                $row['status'] = 'sin_sku';
            } else {
                $row['status'] = 'listo';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<int, string> $productIds
     * @return array{total: int, imported: int, matched: int, stock_adjusted: int, stock_skipped: int, skipped: int, failed: int, message: string}
     */
    public function importSelected(string $customerId, array $productIds, string $categoryId, ?string $warehouseId = null): array
    {
        if (trim($categoryId) === '') {
            throw new RuntimeException('Configura una categoria JTL destino antes de importar productos.');
        }

        $warehouseId = trim((string) ($warehouseId ?? Config::get('jtl.product_import_warehouse_id', '')));

        $selected = array_flip(array_filter(array_map('strval', $productIds)));

        if ($selected === []) {
            throw new RuntimeException('Selecciona al menos un producto.');
        }

        $summary = [
            'total' => count($selected),
            'imported' => 0,
            'matched' => 0,
            'stock_adjusted' => 0,
            'stock_skipped' => 0,
            'skipped' => 0,
            'failed' => 0,
            'message' => '',
        ];
        $lastError = null;

        foreach ($this->packiyoClient()->listProductsForCustomer($customerId) as $product) {
            $row = $this->row($product, $customerId);

            if (!isset($selected[$row['packiyo_product_id']])) {
                continue;
            }

            try {
                if ($row['archived'] || $row['sku'] === '') {
                    $summary['skipped']++;
                    continue;
                }

                $existingMapping = $this->mappingModel()->findByPackiyoProductId($row['packiyo_product_id'])
                    ?? $this->mappingModel()->findBySku($row['sku']);

                if ($existingMapping !== null) {
                    $jtlItemId = (string) ($existingMapping['jtl_item_id'] ?? '');

                    if ($this->syncStock($jtlItemId, $warehouseId, $row)) {
                        $summary['stock_adjusted']++;
                    } else {
                        $summary['stock_skipped']++;
                    }
                    continue;
                }

                $existingItem = $this->findJtlItemBySku($row['sku']);

                if ($existingItem !== null) {
                    $jtlItemId = $this->jtlItemId($existingItem);
                    $this->mappingModel()->upsert(
                        $row['packiyo_product_id'],
                        $customerId,
                        $row['sku'],
                        $jtlItemId,
                        $this->jtlItemSku($existingItem) ?? $row['sku'],
                        $row['name'],
                        'matched'
                    );
                    if ($this->syncStock($jtlItemId, $warehouseId, $row)) {
                        $summary['stock_adjusted']++;
                    } else {
                        $summary['stock_skipped']++;
                    }
                    $summary['matched']++;
                    continue;
                }

                $created = $this->jtlClient()->createItem($this->payload($row, $categoryId));
                $jtlItemId = $this->jtlItemId($created);

                if ($jtlItemId === '') {
                    throw new RuntimeException('JTL no devolvio ID del articulo creado para SKU ' . $row['sku']);
                }

                $this->mappingModel()->upsert(
                    $row['packiyo_product_id'],
                    $customerId,
                    $row['sku'],
                    $jtlItemId,
                    $this->jtlItemSku($created) ?? $row['sku'],
                    $row['name'],
                    'imported'
                );
                if ($this->syncStock($jtlItemId, $warehouseId, $row)) {
                    $summary['stock_adjusted']++;
                } else {
                    $summary['stock_skipped']++;
                }
                $summary['imported']++;
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $lastError = $exception->getMessage();
            }
        }

        $summary['message'] = sprintf(
            'Importacion de productos terminada. total=%d imported=%d matched=%d stock_adjusted=%d stock_skipped=%d skipped=%d failed=%d',
            $summary['total'],
            $summary['imported'],
            $summary['matched'],
            $summary['stock_adjusted'],
            $summary['stock_skipped'],
            $summary['skipped'],
            $summary['failed']
        );

        if ($lastError !== null) {
            $summary['message'] .= '. Ultimo error: ' . $lastError;
        }

        return $summary;
    }

    /** @param array<string, mixed> $row */
    private function syncStock(string $jtlItemId, string $warehouseId, array $row): bool
    {
        if ($warehouseId === '' || $jtlItemId === '' || $row['quantity_on_hand'] === null) {
            return false;
        }

        $targetQuantity = (float) $row['quantity_on_hand'];
        $currentQuantity = $this->currentJtlStock($jtlItemId, $warehouseId);
        $delta = $targetQuantity - $currentQuantity;

        if (abs($delta) < 0.0001) {
            return false;
        }

        $this->jtlClient()->createStockAdjustment([
            'WarehouseId' => is_numeric($warehouseId) ? (int) $warehouseId : $warehouseId,
            'ItemId' => is_numeric($jtlItemId) ? (int) $jtlItemId : $jtlItemId,
            'Quantity' => $delta,
            'Comment' => 'Packiyo stock sync. target=' . $targetQuantity . ' current=' . $currentQuantity . ' SKU=' . $row['sku'],
        ]);

        return true;
    }

    private function currentJtlStock(string $jtlItemId, string $warehouseId): float
    {
        $total = 0.0;

        foreach ($this->jtlClient()->getStocks($jtlItemId, $warehouseId) as $stock) {
            $stockWarehouseId = (string) ($stock['WarehouseId'] ?? $stock['warehouseId'] ?? '');

            if ($stockWarehouseId !== '' && $stockWarehouseId !== (string) $warehouseId) {
                continue;
            }

            $quantity = $stock['QuantityTotal'] ?? $stock['quantityTotal'] ?? 0;

            if (is_numeric($quantity)) {
                $total += (float) $quantity;
            }
        }

        return $total;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function row(array $product, string $customerId): array
    {
        $attributes = $this->attributes($product);
        $id = $this->resourceId($product);

        return [
            'packiyo_product_id' => $id,
            'packiyo_customer_id' => $customerId,
            'sku' => $this->firstScalar($attributes, ['sku', 'SKU']) ?? '',
            'name' => $this->firstScalar($attributes, ['name', 'Name']) ?? '',
            'barcode' => $this->firstScalar($attributes, ['barcode', 'Barcode']) ?? '',
            'price' => $this->firstNumber($attributes, ['price', 'value', 'customs_price']),
            'weight' => $this->firstNumber($attributes, ['weight']),
            'length' => $this->firstNumber($attributes, ['length']),
            'width' => $this->firstNumber($attributes, ['width']),
            'height' => $this->firstNumber($attributes, ['height']),
            'country_of_origin' => $this->firstScalar($attributes, ['country_of_origin']),
            'hs_code' => $this->firstScalar($attributes, ['hs_code']),
            'quantity_on_hand' => $this->firstNumber($attributes, ['quantity_on_hand']),
            'quantity_available' => $this->firstNumber($attributes, ['quantity_available']),
            'archived' => $this->firstScalar($attributes, ['archived_at']) !== null,
            'status' => '',
            'jtl_item_id' => '',
        ];
    }

    /** @param array<string, mixed> $row */
    private function payload(array $row, string $categoryId): array
    {
        $payload = [
            'SKU' => $row['sku'],
            'Name' => $row['name'] !== '' ? $row['name'] : $row['sku'],
            'Categories' => [
                ['CategoryId' => is_numeric($categoryId) ? (int) $categoryId : $categoryId],
            ],
            'StorageOptions' => [
                'InventoryManagementActive' => true,
            ],
            'AllowNegativeStock' => false,
            'Annotation' => 'Imported from Packiyo product #' . $row['packiyo_product_id'] . ' customer #' . $row['packiyo_customer_id'],
        ];

        if ($row['barcode'] !== '') {
            $payload['Identifiers'] = ['Gtin' => $row['barcode']];
        }

        if ($row['price'] !== null) {
            $payload['ItemPriceData'] = ['SalesPriceNet' => $row['price']];
        }

        $dimensions = array_filter([
            'Length' => $row['length'],
            'Width' => $row['width'],
            'Height' => $row['height'],
        ], static fn (mixed $value): bool => $value !== null);

        if ($dimensions !== []) {
            $payload['Dimensions'] = $dimensions;
        }

        if ($row['weight'] !== null) {
            $payload['Weights'] = [
                'ItemWeigth' => $row['weight'],
                'ShippingWeight' => $row['weight'],
            ];
        }

        if ($row['country_of_origin'] !== null) {
            $payload['CountryOfOrigin'] = $row['country_of_origin'];
        }

        if ($row['hs_code'] !== null) {
            $payload['Taric'] = $row['hs_code'];
        }

        return $payload;
    }

    /** @return array<string, mixed>|null */
    private function findJtlItemBySku(string $sku): ?array
    {
        foreach ($this->jtlClient()->queryItems($sku) as $item) {
            if (strcasecmp((string) ($this->jtlItemSku($item) ?? ''), $sku) === 0) {
                return $item;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $item */
    private function jtlItemId(array $item): string
    {
        return (string) ($item['Id'] ?? $item['id'] ?? $item['ItemId'] ?? $item['itemId'] ?? '');
    }

    /** @param array<string, mixed> $item */
    private function jtlItemSku(array $item): ?string
    {
        $sku = $item['SKU'] ?? $item['sku'] ?? null;

        return is_scalar($sku) && trim((string) $sku) !== '' ? (string) $sku : null;
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

    /** @param array<string, mixed> $data */
    private function firstNumber(array $data, array $keys): float|int|null
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_numeric($data[$key])) {
                return (float) $data[$key];
            }
        }

        return null;
    }

    private function packiyoClient(): PackiyoClient
    {
        return $this->packiyo ?? new PackiyoClient();
    }

    private function jtlClient(): JtlClient
    {
        return $this->jtl ?? new JtlClient();
    }

    private function mappingModel(): ProductMapping
    {
        return $this->mappings ?? new ProductMapping();
    }
}
