<?php

declare(strict_types=1);

namespace App\Services;

use App\Clients\PackiyoClient;
use App\Models\ProductNameMapping;
use App\Models\PackiyoProductCatalogCache;
use Throwable;

final class OrderPreparationService
{
    /** @var array<string, array<int, array<string, string>>> */
    private array $catalogCache = [];

    public function __construct(
        private readonly ?PackiyoClient $packiyo = null,
        private readonly ?ProductNameMapping $nameMappings = null,
        private readonly ?PackiyoProductCatalogCache $catalogStore = null
    ) {
    }

    /** @param array<int, array<string, mixed>> $items @return array<int, array<string, mixed>> */
    public function prepareItems(array $items, ?string $customerId, bool $allowRemoteCatalog = true): array
    {
        $prepared = [];

        foreach ($items as $index => $item) {
            if ($this->isShipping($item)) {
                continue;
            }

            $name = $this->firstString($item, ['name', 'Name', 'title', 'Title', 'description', 'Description', 'cName', 'CName']) ?? '';
            $sku = $this->firstString($item, ['sku', 'SKU', 'articleNumber', 'ArticleNumber', 'itemNumber', 'ItemNumber', 'cArtNr', 'CArtNr']) ?? '';
            $externalId = $this->firstString($item, ['external_id', 'externalId', 'ExternalId', 'id', 'Id', 'positionId', 'PositionId'])
                ?? 'jtl-line-' . ($index + 1);
            $row = [
                'external_id' => $externalId,
                'source_name' => $name,
                'name' => $name,
                'sku' => $sku,
                'quantity' => (float) ($this->firstValue($item, ['quantity', 'Quantity', 'qty', 'Qty', 'amount', 'Amount', 'nAnzahl', 'NAnzahl']) ?? 1),
                'price' => (float) ($this->firstValue($item, ['price', 'Price', 'unit_price', 'unitPrice', 'UnitPrice', 'SalesPriceGross', 'salesPriceGross', 'SalesPriceNet', 'salesPriceNet', 'fVKBrutto', 'FVKBrutto', 'fVKNetto', 'FVKNetto']) ?? 0),
                'packiyo_product_id' => '',
                'resolution' => $this->isProvisionalSku($sku) ? 'unresolved' : 'source_sku',
                'score' => null,
                'suggestions' => [],
            ];

            if ($row['resolution'] === 'unresolved' && $customerId !== null && $customerId !== '' && !$this->isMeaninglessName($name)) {
                $normalized = self::normalizeName($name);
                $saved = $this->mappingModel()->find($customerId, $normalized);

                if ($saved !== null) {
                    $row = $this->applySavedNameMapping($row, $saved);
                }

                if ($row['resolution'] === 'unresolved') {
                    try {
                        $matches = $this->matchCandidates($name, $this->catalog($customerId, $allowRemoteCatalog));
                        $row['suggestions'] = array_slice($matches, 0, 5);
                        $best = $matches[0] ?? null;
                        $second = $matches[1] ?? null;

                        if ($best !== null && $this->isHighConfidence($name, $best, $second)) {
                            $row['sku'] = $best['sku'];
                            $row['name'] = $best['name'];
                            $row['packiyo_product_id'] = $best['id'];
                            $row['resolution'] = 'automatic_name';
                            $row['score'] = $best['score'];
                        }
                    } catch (Throwable) {
                        // The order remains reviewable even when the catalog is temporarily unavailable.
                    }
                }
            }

            $prepared[] = $row;
        }

        return $prepared;
    }

    /**
     * Applies a confirmed customer-specific JTL-name to Packiyo-product mapping.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed>|null $mapping
     * @return array<string, mixed>
     */
    public function applySavedNameMapping(array $item, ?array $mapping): array
    {
        $sourceSku = trim((string) ($item['sku'] ?? ''));
        $mappedSku = trim((string) ($mapping['packiyo_sku'] ?? ''));

        if ($mapping === null || !$this->isProvisionalSku($sourceSku) || $this->isProvisionalSku($mappedSku)) {
            return $item;
        }

        $sourceName = trim((string) ($item['source_name'] ?? $item['name'] ?? ''));
        $item['sku'] = $mappedSku;
        $item['name'] = trim((string) (($mapping['packiyo_product_name'] ?? '') ?: $sourceName));
        $item['packiyo_product_id'] = trim((string) ($mapping['packiyo_product_id'] ?? ''));
        $item['resolution'] = 'saved_name';
        $item['score'] = 1.0;

        return $item;
    }

    /** @param array<int, array<string, mixed>> $items @return array<int, string> */
    public function validationErrors(array $items): array
    {
        $errors = [];
        $ids = [];

        if ($items === []) {
            return ['La orden debe tener al menos un articulo.'];
        }

        foreach ($items as $index => $item) {
            $label = 'Linea ' . ($index + 1);
            $sku = trim((string) ($item['sku'] ?? ''));
            $externalId = trim((string) ($item['external_id'] ?? ''));
            $quantity = $item['quantity'] ?? null;
            $price = $item['price'] ?? null;

            if ($this->isProvisionalSku($sku)) {
                $errors[] = $label . ': selecciona un SKU valido.';
            }
            if (!is_numeric($quantity) || (float) $quantity <= 0) {
                $errors[] = $label . ': la cantidad debe ser mayor que cero.';
            }
            if (!is_numeric($price) || (float) $price < 0) {
                $errors[] = $label . ': el precio no puede ser negativo.';
            }
            if ($externalId === '' || isset($ids[$externalId])) {
                $errors[] = $label . ': el identificador debe ser unico.';
            }
            $ids[$externalId] = true;
        }

        return $errors;
    }

    public function isProvisionalSku(string $sku): bool
    {
        $sku = trim($sku);
        return $sku === '' || preg_match('/^JTL-LINE(?:-|$)/i', $sku) === 1;
    }

    /** @param array<int, array<string, mixed>> $items */
    public function shippingAmount(array $items): ?float
    {
        $found = false;
        $total = 0.0;
        foreach ($items as $item) {
            if (!$this->isShipping($item)) {
                continue;
            }
            $found = true;
            $total += (float) ($this->firstValue($item, ['SalesPriceGross', 'salesPriceGross', 'SalesPriceNet', 'salesPriceNet', 'price', 'Price']) ?? 0);
        }
        return $found ? $total : null;
    }

    /** @return array<int, array<string, string>> */
    public function catalog(string $customerId, bool $allowRemote = true, bool $forceRefresh = false): array
    {
        if (!$forceRefresh && isset($this->catalogCache[$customerId])) {
            return $this->catalogCache[$customerId];
        }

        if (!$forceRefresh) {
            $cached = $this->catalogModel()->allForCustomer($customerId);
            if ($cached !== [] || !$allowRemote) {
                return $this->catalogCache[$customerId] = $cached;
            }
        } elseif (!$allowRemote) {
            return $this->catalogCache[$customerId] = $this->catalogModel()->allForCustomer($customerId);
        }

        $catalog = [];
        foreach ($this->packiyoClient()->listProductsForCustomer($customerId) as $product) {
            $attributes = isset($product['attributes']) && is_array($product['attributes']) ? $product['attributes'] : [];
            if (is_scalar($attributes['archived_at'] ?? null) && trim((string) $attributes['archived_at']) !== '') {
                continue;
            }
            $sku = $this->firstString($attributes, ['sku', 'SKU']) ?? '';
            if ($sku === '') {
                continue;
            }
            $catalog[] = [
                'id' => is_scalar($product['id'] ?? null) ? (string) $product['id'] : '',
                'sku' => $sku,
                'name' => $this->firstString($attributes, ['name', 'Name']) ?? $sku,
            ];
        }

        $this->catalogModel()->replaceForCustomer($customerId, $catalog);
        return $this->catalogCache[$customerId] = $catalog;
    }

    /** @param array<int, array<string, string>> $catalog @return array<int, array{id:string,sku:string,name:string,score:float}> */
    public function matchCandidates(string $sourceName, array $catalog): array
    {
        $matches = [];
        foreach ($catalog as $product) {
            $score = $this->similarity($sourceName, $product['name']);
            if ($score < 0.25) {
                continue;
            }
            $matches[] = $product + ['score' => $score];
        }
        usort($matches, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return $matches;
    }

    public static function normalizeName(string $value): string
    {
        $value = strtolower(trim($value));
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted)) {
                $value = strtolower($converted);
            }
        }
        $value = preg_replace('/(\d+)\s*(w|v|a|mah|gb|tb|mm|cm)\b/i', '$1$2', $value) ?? $value;
        $value = str_replace(['usb-c', 'usb c'], 'usbc', $value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function similarity(string $left, string $right): float
    {
        $left = self::normalizeName($left);
        $right = self::normalizeName($right);
        $leftTokens = $this->tokens($left);
        $rightTokens = $this->tokens($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }
        $shared = array_intersect($leftTokens, $rightTokens);
        $containment = count($shared) / max(1, min(count($leftTokens), count($rightTokens)));
        $dice = (2 * count($shared)) / max(1, count($leftTokens) + count($rightTokens));
        similar_text($left, $right, $characterPercent);
        return round(($containment * 0.55) + ($dice * 0.30) + (($characterPercent / 100) * 0.15), 4);
    }

    /** @param array{id:string,sku:string,name:string,score:float} $best @param array{id:string,sku:string,name:string,score:float}|null $second */
    private function isHighConfidence(string $source, array $best, ?array $second): bool
    {
        $sourceTokens = $this->tokens(self::normalizeName($source));
        $bestTokens = $this->tokens(self::normalizeName($best['name']));
        $shared = array_intersect($sourceTokens, $bestTokens);
        $sourceModels = array_values(array_filter($sourceTokens, static fn (string $token): bool => preg_match('/\d/', $token) === 1));
        $modelsMatch = $sourceModels === [] || array_diff($sourceModels, $bestTokens) === [];
        $secondScore = $second !== null && $this->modelsCompatible($sourceModels, $second['name'])
            ? (float) $second['score']
            : 0.0;
        $margin = $best['score'] - $secondScore;
        return $best['score'] >= 0.70 && $margin >= 0.10 && count($shared) >= 3 && $modelsMatch;
    }

    /** @param array<int, string> $sourceModels */
    private function modelsCompatible(array $sourceModels, string $candidateName): bool
    {
        if ($sourceModels === []) {
            return true;
        }
        $candidateTokens = $this->tokens(self::normalizeName($candidateName));
        return array_diff($sourceModels, $candidateTokens) === [];
    }

    /** @return array<int, string> */
    private function tokens(string $value): array
    {
        $stop = ['the', 'and', 'with', 'voor', 'met', 'zonder', 'de', 'het', 'een', 'und', 'mit', 'ohne', 'der', 'die', 'das', 'fur', 'pour', 'avec', 'sans', 'le', 'la', 'les'];
        return array_values(array_unique(array_filter(
            explode(' ', $value),
            static fn (string $token): bool => strlen($token) >= 2 && !in_array($token, $stop, true)
        )));
    }

    private function isMeaninglessName(string $name): bool
    {
        $name = trim($name);
        return $name === '' || preg_match('/^JTL-LINE(?:-|$)/i', $name) === 1;
    }

    /** @param array<string, mixed> $item */
    private function isShipping(array $item): bool
    {
        if ((string) ($item['Type'] ?? $item['type'] ?? '') === '2') {
            return true;
        }
        $name = strtolower((string) ($item['Name'] ?? $item['name'] ?? $item['title'] ?? ''));
        return str_contains($name, 'versand') || str_contains($name, 'shipping');
    }

    /** @param array<string, mixed> $data @param array<int, string> $keys */
    private function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }
        return null;
    }

    /** @param array<string, mixed> $data @param array<int, string> $keys */
    private function firstValue(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
        }
        return null;
    }

    private function packiyoClient(): PackiyoClient
    {
        return $this->packiyo ?? new PackiyoClient();
    }

    private function mappingModel(): ProductNameMapping
    {
        return $this->nameMappings ?? new ProductNameMapping();
    }

    private function catalogModel(): PackiyoProductCatalogCache
    {
        return $this->catalogStore ?? new PackiyoProductCatalogCache();
    }
}
