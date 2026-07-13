<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PackiyoCustomer;
use App\Models\PackiyoCustomerMapping;
use App\Support\Config;

final class PackiyoCustomerResolver
{
    private ?string $inactiveCustomerId = null;

    public function __construct(
        private readonly ?PackiyoCustomerMapping $mappings = null,
        private readonly ?PackiyoCustomer $customers = null
    )
    {
    }

    /** @param array<string, mixed> $order */
    public function resolveCustomerId(array $order): ?string
    {
        $this->inactiveCustomerId = null;
        $mapping = $this->mappingModel()->findForCandidates($this->candidates($order));

        if ($mapping !== null && !empty($mapping['packiyo_customer_id'])) {
            return $this->activeCustomerId((string) $mapping['packiyo_customer_id']);
        }

        $fallback = (string) Config::get('packiyo.customer_id', '');

        return $fallback !== '' ? $this->activeCustomerId($fallback) : null;
    }

    public function inactiveCustomerId(): ?string
    {
        return $this->inactiveCustomerId;
    }

    /** @param array<string, mixed> $order */
    public function describeCandidates(array $order): string
    {
        $parts = [];

        foreach ($this->candidates($order) as $type => $values) {
            if ($values === []) {
                continue;
            }

            $parts[] = $type . '=' . implode('|', $values);
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, array<int, string>>
     */
    public function candidates(array $order): array
    {
        $customer = $this->firstArray($order, ['customer', 'Customer', 'customer_data', 'CustomerData', 'client', 'Client']);
        $billing = $this->firstArray($order, ['billing_address', 'billingAddress', 'BillingAddress', 'invoiceAddress', 'InvoiceAddress']);
        $shipping = $this->firstArray($order, ['shipping_address', 'shippingAddress', 'ShippingAddress', 'deliveryAddress', 'DeliveryAddress']);
        $marketplace = $this->firstArray($order, ['marketplace', 'Marketplace', 'platform', 'Platform']);
        $salesChannel = $this->firstArray($order, ['salesChannel', 'SalesChannel', 'channel', 'Channel', 'orderChannel', 'OrderChannel']);
        $shop = $this->firstArray($order, ['shop', 'Shop', 'store', 'Store']);
        $sourceCandidates = $this->sourceCandidatesByType($order);

        return [
            'marketplace' => $this->marketplaceStrings(array_merge([
                $this->firstValue($order, ['marketplace', 'Marketplace', 'marketplaceName', 'MarketplaceName', 'platform', 'Platform']),
                $this->firstValue($marketplace, ['name', 'Name', 'displayName', 'DisplayName', 'title', 'Title', 'id', 'Id']),
            ], $sourceCandidates['marketplace'] ?? [])),
            'sales_channel' => $this->uniqueStrings(array_merge([
                $this->firstValue($order, ['salesChannel', 'SalesChannel', 'salesChannelName', 'SalesChannelName', 'channel', 'Channel', 'orderChannel', 'OrderChannel']),
                $this->firstValue($salesChannel, ['name', 'Name', 'displayName', 'DisplayName', 'title', 'Title', 'id', 'Id']),
            ], $sourceCandidates['sales_channel'] ?? [])),
            'shop' => $this->uniqueStrings(array_merge([
                $this->firstValue($order, ['shop', 'Shop', 'shopName', 'ShopName', 'shopId', 'ShopId', 'store', 'Store', 'storeName', 'StoreName']),
                $this->firstValue($shop, ['name', 'Name', 'displayName', 'DisplayName', 'title', 'Title', 'id', 'Id']),
            ], $sourceCandidates['shop'] ?? [])),
            'customer_number' => $this->uniqueStrings([
                $this->firstValue($customer, ['customerNumber', 'CustomerNumber', 'number', 'Number', 'cKundenNr', 'CKundenNr']),
                $this->firstValue($order, ['customerNumber', 'CustomerNumber', 'cKundenNr', 'CKundenNr']),
            ]),
            'customer_id' => $this->uniqueStrings([
                $this->firstValue($customer, ['id', 'Id', 'customerId', 'CustomerId', 'kKunde', 'KKunde']),
                $this->firstValue($order, ['customerId', 'CustomerId', 'kKunde', 'KKunde']),
            ]),
            'email' => $this->uniqueStrings([
                $this->firstValue($customer, ['email', 'Email', 'mail', 'Mail']),
                $this->firstValue($billing, ['email', 'Email', 'mail', 'Mail']),
                $this->firstValue($shipping, ['email', 'Email', 'mail', 'Mail']),
            ]),
            'company' => $this->uniqueStrings([
                $this->firstValue($customer, ['company', 'Company', 'companyName', 'CompanyName']),
                $this->firstValue($billing, ['company', 'Company', 'companyName', 'CompanyName']),
                $this->firstValue($shipping, ['company', 'Company', 'companyName', 'CompanyName']),
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<int, array{source_type: string, source_value: string, source_path: string}>
     */
    public function sourceCandidateDetails(array $order): array
    {
        $details = [];
        $this->collectSourceCandidateDetails($order, '', $details, 0);

        $seen = [];
        $unique = [];

        foreach ($details as $detail) {
            $key = $detail['source_type'] . ':' . strtolower($detail['source_value']);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $detail;
        }

        return $unique;
    }

    /** @param array<string, mixed> $data */
    private function firstArray(array $data, array $keys): array
    {
        $value = $this->firstValue($data, $keys);

        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $data */
    private function firstValue(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    /** @param array<int, mixed> $values */
    private function uniqueStrings(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $strings[] = trim((string) $value);
            }
        }

        return array_values(array_unique($strings));
    }

    /** @param array<int, mixed> $values */
    private function marketplaceStrings(array $values): array
    {
        $expanded = [];

        foreach ($values as $value) {
            if (!is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $value = trim((string) $value);
            $expanded[] = $value;

            foreach ($this->marketplaceAliases($value) as $alias) {
                $expanded[] = $alias;
            }
        }

        return $this->uniqueStrings($expanded);
    }

    /** @return array<int, string> */
    private function marketplaceAliases(string $value): array
    {
        $normalized = $this->normalizeToken($value);

        if (in_array($normalized, ['bol', 'bolcom', 'bolnl', 'bolbe'], true)) {
            return ['bol', 'BOL', 'bol.com', 'BOL.com'];
        }

        return [];
    }

    private function mappingModel(): PackiyoCustomerMapping
    {
        return $this->mappings ?? new PackiyoCustomerMapping();
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, array<int, string>>
     */
    private function sourceCandidatesByType(array $order): array
    {
        $grouped = [
            'shop' => [],
            'marketplace' => [],
            'sales_channel' => [],
        ];

        foreach ($this->sourceCandidateDetails($order) as $detail) {
            $grouped[$detail['source_type']][] = $detail['source_value'];
        }

        return $grouped;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array{source_type: string, source_value: string, source_path: string}> $details
     */
    private function collectSourceCandidateDetails(array $data, string $path, array &$details, int $depth): void
    {
        if ($depth > 8) {
            return;
        }

        foreach ($data as $key => $value) {
            $keyName = is_int($key) ? (string) $key : (string) $key;
            $nextPath = $path === '' ? $keyName : $path . '.' . $keyName;

            if (is_array($value)) {
                $this->collectSourceCandidateDetails($value, $nextPath, $details, $depth + 1);
                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $sourceValue = trim((string) $value);

            if (!$this->isUsefulSourceValue($sourceValue)) {
                continue;
            }

            foreach ($this->sourceTypesFor($nextPath, $sourceValue) as $type) {
                $details[] = [
                    'source_type' => $type,
                    'source_value' => $sourceValue,
                    'source_path' => $nextPath,
                ];
            }
        }
    }

    /** @return array<int, string> */
    private function sourceTypesFor(string $path, string $value): array
    {
        $normalizedPath = $this->normalizeToken($path);
        $normalizedValue = $this->normalizeToken($value);
        $types = [];

        if ($this->isIgnoredSourcePath($normalizedPath)) {
            return [];
        }

        if ($this->containsAny($normalizedPath, ['shop', 'store', 'webshop', 'kshop'])) {
            $types[] = 'shop';
        }

        if ($this->containsAny($normalizedPath, ['marketplace', 'market', 'platform', 'plattform', 'portal', 'origin', 'source', 'herkunft'])) {
            $types[] = 'marketplace';
        }

        if ($this->containsAny($normalizedPath, ['saleschannel', 'orderchannel', 'channel', 'verkaufskanal', 'vertriebskanal'])) {
            $types[] = 'sales_channel';
        }

        if ($this->containsKnownMarketplace($normalizedValue)) {
            $types[] = 'marketplace';

            if (str_contains(trim($value), ' ') || $this->containsAny($normalizedPath, ['name', 'shop', 'store'])) {
                $types[] = 'shop';
            }
        }

        return array_values(array_unique($types));
    }

    private function isIgnoredSourcePath(string $path): bool
    {
        return $this->containsAny($path, [
            'comment',
            'note',
            'remark',
            'billingaddress',
            'shippingaddress',
            'deliveryaddress',
            'invoiceaddress',
            'customer',
            'contact',
            'firstname',
            'lastname',
            'email',
            'phone',
            'payment',
            'tracking',
        ]);
    }

    private function isUsefulSourceValue(string $value): bool
    {
        if ($value === '' || strlen($value) > 120 || str_contains($value, "\n") || str_contains($value, "\r")) {
            return false;
        }

        if (preg_match('/^\d+$/', $value) === 1 || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            return false;
        }

        if (str_contains($value, '@') || str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return false;
        }

        return true;
    }

    private function normalizeToken(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $value));
    }

    /** @param array<int, string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function containsKnownMarketplace(string $value): bool
    {
        return $this->containsAny($value, [
            'temu',
            'amazon',
            'ebay',
            'kaufland',
            'otto',
            'shopify',
            'zalando',
            'allegro',
            'metro',
            'bol',
            'cdiscount',
            'manomano',
        ]);
    }

    private function customerModel(): PackiyoCustomer
    {
        return $this->customers ?? new PackiyoCustomer();
    }

    private function activeCustomerId(string $customerId): ?string
    {
        if ($this->customerModel()->isKnownInactive($customerId)) {
            $this->inactiveCustomerId = $customerId;

            return null;
        }

        return $customerId;
    }
}
