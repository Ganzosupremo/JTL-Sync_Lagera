<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OrderMapping;
use App\Support\Config;
use RuntimeException;

final class MappingService
{
    public function __construct(
        private readonly ?OrderMapping $mappings = null,
        private readonly ?PackiyoCustomerResolver $customerResolver = null
    )
    {
    }

    public function hasJtlOrder(string $jtlOrderId): bool
    {
        return $this->mappingModel()->existsByJtlOrderId($jtlOrderId);
    }

    public function deleteJtlOrder(string $jtlOrderId): void
    {
        $this->mappingModel()->deleteByJtlOrderId($jtlOrderId);
    }

    /** @param array<string, mixed> $data */
    public function save(array $data): int
    {
        return $this->mappingModel()->create($data);
    }

    /**
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function toPackiyoPayload(
        array $order,
        array $items,
        ?string $externalIdOverride = null,
        ?string $numberOverride = null,
        ?string $lineItemExternalIdSuffix = null
    ): array
    {
        $jtlOrderId = $this->jtlOrderId($order);

        if ($jtlOrderId === null) {
            throw new RuntimeException('JTL order has no usable id.');
        }

        $jtlOrderNumber = $this->jtlOrderNumber($order);
        $marketplaceOrderNumber = $this->marketplaceOrderNumber($order);
        $packiyoOrderNumber = $numberOverride ?? $marketplaceOrderNumber ?? $jtlOrderNumber ?? $jtlOrderId;
        $externalId = $externalIdOverride ?? $marketplaceOrderNumber ?? $jtlOrderId;
        $lineItems = $this->normalizeLineItems($items);

        if ($lineItemExternalIdSuffix !== null && $lineItemExternalIdSuffix !== '') {
            foreach ($lineItems as $index => $lineItem) {
                $lineItems[$index]['external_id'] = (string) $lineItem['external_id'] . '-' . $lineItemExternalIdSuffix;
            }
        }

        $attributes = [
            'number' => $packiyoOrderNumber,
            'order_channel_name' => (string) Config::get('packiyo.order_channel_name', 'JTL-Wawi'),
            'ordered_at' => $this->packiyoDate($this->firstString($order, ['ordered_at', 'created_at', 'date', 'Date', 'orderDate', 'OrderDate', 'creationDate', 'CreationDate', 'SalesOrderDate'])),
            'external_id' => $externalId,
            'shipping' => $this->shippingAmount($order, $items),
            'tax' => (float) ($this->firstValue($order, ['tax', 'Tax', 'taxAmount', 'TaxAmount']) ?? 0),
            'discount' => (float) ($this->firstValue($order, ['discount', 'Discount', 'discountAmount', 'DiscountAmount']) ?? 0),
            'shipping_contact_information_data' => $this->packiyoContact(
                $this->firstArray($order, ['shipping_address', 'shippingAddress', 'ShippingAddress', 'deliveryAddress', 'DeliveryAddress', 'Shipmentaddress', 'ShipmentAddress', 'shipmentAddress']),
                $this->normalizeCustomer($order)
            ),
            'billing_contact_information_data' => $this->packiyoContact(
                $this->firstArray($order, ['billing_address', 'billingAddress', 'BillingAddress', 'invoiceAddress', 'InvoiceAddress']),
                $this->normalizeCustomer($order)
            ),
            'order_item_data' => $lineItems,
            'tags' => 'jtl',
        ];

        $payload = [
            'data' => [
                'type' => 'orders',
                'attributes' => $attributes,
            ],
        ];

        $customerResolver = $this->customerResolver();
        $customerId = $customerResolver->resolveCustomerId($order);

        if ($customerId !== null && $customerId !== '') {
            $payload['data']['relationships']['customer'] = [
                'data' => [
                    'type' => 'customers',
                    'id' => $customerId,
                ],
            ];
        } elseif ($customerResolver->inactiveCustomerId() !== null) {
            throw new InactivePackiyoCustomerException(
                'Packiyo customer ' . $customerResolver->inactiveCustomerId()
                . ' is inactive in this app. JTL order will not be sent.'
            );
        } elseif ((bool) Config::get('packiyo.require_customer_mapping', true)) {
            throw new RuntimeException(
                'No Packiyo customer mapping matched this JTL order. Candidates: '
                . ($customerResolver->describeCandidates($order) ?: 'none')
            );
        }

        return $payload;
    }

    /** @param array<string, mixed> $order */
    public function jtlOrderId(array $order): ?string
    {
        return $this->firstString($order, [
            'jtl_order_id',
            'id',
            'Id',
            'ID',
            'order_id',
            'orderId',
            'OrderId',
            'salesOrderId',
            'SalesOrderId',
            'salesOrderInternalId',
            'SalesOrderInternalId',
            'internalId',
            'InternalId',
            'kBestellung',
            'KBestellung',
        ]);
    }

    /** @param array<string, mixed> $order */
    public function jtlOrderNumber(array $order): ?string
    {
        return $this->firstString($order, [
            'jtl_order_number',
            'order_number',
            'orderNumber',
            'OrderNumber',
            'salesOrderNumber',
            'SalesOrderNumber',
            'number',
            'Number',
            'externalSalesOrderId',
            'ExternalSalesOrderId',
            'cBestellNr',
            'CBestellNr',
        ]);
    }

    /** @param array<string, mixed> $order */
    public function marketplaceOrderNumber(array $order): ?string
    {
        $direct = $this->firstString($order, [
            'marketplace_order_number',
            'marketplaceOrderNumber',
            'MarketplaceOrderNumber',
            'marketplace_order_id',
            'marketplaceOrderId',
            'MarketplaceOrderId',
            'MarketplaceOrderID',
            'external_number',
            'externalNumber',
            'ExternalNumber',
            'external_order_number',
            'externalOrderNumber',
            'ExternalOrderNumber',
            'external_order_id',
            'externalOrderId',
            'ExternalOrderId',
            'platform_order_number',
            'platformOrderNumber',
            'PlatformOrderNumber',
            'platform_order_id',
            'platformOrderId',
            'PlatformOrderId',
            'channel_order_number',
            'channelOrderNumber',
            'ChannelOrderNumber',
            'channel_order_id',
            'channelOrderId',
            'ChannelOrderId',
            'merchant_order_number',
            'merchantOrderNumber',
            'MerchantOrderNumber',
            'merchant_order_id',
            'merchantOrderId',
            'MerchantOrderId',
            'shop_order_number',
            'shopOrderNumber',
            'ShopOrderNumber',
            'shop_order_id',
            'shopOrderId',
            'ShopOrderId',
            'original_order_number',
            'originalOrderNumber',
            'OriginalOrderNumber',
            'OriginalExternalNumber',
        ]);

        if ($direct !== null) {
            return $direct;
        }

        return $this->findMarketplaceOrderNumber($order);
    }

    /** @param array<string, mixed> $order */
    public function packiyoOrderId(array $order): ?string
    {
        $data = $this->firstArray($order, ['data', 'order']);

        return $this->firstString($order, ['packiyo_order_id', 'id', 'order_id', 'orderId'])
            ?? $this->firstString($data, ['id', 'order_id', 'orderId']);
    }

    /** @param array<string, mixed> $order */
    public function packiyoOrderNumber(array $order): ?string
    {
        $data = $this->firstArray($order, ['data', 'order']);
        $attributes = $this->firstArray($data, ['attributes']);

        return $this->firstString($order, ['packiyo_order_number', 'order_number', 'orderNumber', 'number'])
            ?? $this->firstString($data, ['order_number', 'orderNumber', 'number'])
            ?? $this->firstString($attributes, ['number']);
    }

    /** @param array<string, mixed> $data */
    private function normalizeCustomer(array $data): array
    {
        $customer = $this->firstArray($data, ['customer', 'customer_data', 'client']);
        $billing = $this->firstArray($data, ['billing_address', 'billingAddress', 'BillingAddress', 'invoiceAddress', 'InvoiceAddress']);
        $shipping = $this->firstArray($data, ['shipping_address', 'shippingAddress', 'ShippingAddress', 'deliveryAddress', 'DeliveryAddress', 'Shipmentaddress', 'ShipmentAddress', 'shipmentAddress']);

        return [
            'name' => $this->firstString($customer, ['name', 'full_name', 'fullName'])
                ?? $this->fullName($customer)
                ?? $this->fullName($billing)
                ?? $this->fullName($shipping),
            'email' => $this->firstString($customer, ['email', 'Email', 'mail', 'Mail', 'EmailAddress'])
                ?? $this->firstString($billing, ['email', 'Email', 'mail', 'Mail', 'EmailAddress'])
                ?? $this->firstString($shipping, ['email', 'Email', 'mail', 'Mail', 'EmailAddress']),
            'phone' => $this->firstString($customer, ['phone', 'Phone', 'telephone', 'Telephone', 'mobile', 'Mobile', 'PhoneNumber', 'MobilePhoneNumber'])
                ?? $this->firstString($billing, ['phone', 'Phone', 'telephone', 'Telephone', 'mobile', 'Mobile', 'PhoneNumber', 'MobilePhoneNumber'])
                ?? $this->firstString($shipping, ['phone', 'Phone', 'telephone', 'Telephone', 'mobile', 'Mobile', 'PhoneNumber', 'MobilePhoneNumber']),
        ];
    }

    /** @param array<string, mixed> $address */
    private function normalizeAddress(array $address): array
    {
        return [
            'first_name' => $this->firstString($address, ['first_name', 'firstName', 'firstname', 'FirstName']),
            'last_name' => $this->firstString($address, ['last_name', 'lastName', 'lastname', 'LastName']),
            'company' => $this->firstString($address, ['company', 'Company', 'companyName', 'CompanyName']),
            'address1' => $this->firstString($address, ['address1', 'Address1', 'street', 'Street', 'street1', 'Street1']),
            'address2' => $this->firstString($address, ['address2', 'Address2', 'street2', 'Street2']),
            'postal_code' => $this->firstString($address, ['postal_code', 'zip', 'Zip', 'zipcode', 'postalCode', 'PostalCode']),
            'state' => $this->firstString($address, ['state', 'State', 'province', 'Province', 'region', 'Region']),
            'city' => $this->firstString($address, ['city', 'City']),
            'country' => $this->firstString($address, ['country', 'Country', 'country_code', 'countryCode', 'CountryIso', 'CountryISO']),
            'email' => $this->firstString($address, ['email', 'Email', 'mail', 'Mail', 'EmailAddress']),
            'phone' => $this->firstString($address, ['phone', 'Phone', 'telephone', 'Telephone', 'PhoneNumber', 'MobilePhoneNumber']),
        ];
    }

    /** @param array<string, mixed> $item */
    private function normalizeLineItem(array $item): array
    {
        $name = $this->firstString($item, ['name', 'Name', 'title', 'Title', 'description', 'Description', 'cName', 'CName']);
        $sku = $this->firstString($item, ['sku', 'SKU', 'articleNumber', 'ArticleNumber', 'itemNumber', 'ItemNumber', 'cArtNr', 'CArtNr'])
            ?? ('JTL-LINE-' . (string) ($this->firstValue($item, ['id', 'Id']) ?? uniqid('', false)));

        return [
            'sku' => $sku,
            'name' => $name,
            'quantity' => (float) ($this->firstValue($item, ['quantity', 'Quantity', 'qty', 'Qty', 'amount', 'Amount', 'nAnzahl', 'NAnzahl']) ?? 1),
            'external_id' => $this->firstString($item, ['external_id', 'externalId', 'ExternalId', 'id', 'Id', 'positionId', 'PositionId'])
                ?? $this->firstString($item, ['sku', 'SKU', 'articleNumber', 'ArticleNumber', 'itemNumber', 'ItemNumber', 'cArtNr', 'CArtNr'])
                ?? uniqid('jtl-item-', true),
            'price' => (float) ($this->firstValue($item, [
                'price',
                'Price',
                'unit_price',
                'unitPrice',
                'UnitPrice',
                'SalesPriceGross',
                'salesPriceGross',
                'SalesPriceNet',
                'salesPriceNet',
                'fVKBrutto',
                'FVKBrutto',
                'fVKNetto',
                'FVKNetto',
            ]) ?? 0),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLineItems(array $items): array
    {
        $lineItems = [];

        foreach ($items as $item) {
            if ($this->isShippingLineItem($item)) {
                continue;
            }

            $lineItems[] = $this->normalizeLineItem($item);
        }

        if ($lineItems === [] && $items !== []) {
            $lineItems[] = $this->normalizeLineItem($items[0]);
        }

        if ($lineItems === []) {
            throw new RuntimeException('JTL order has no line items. Packiyo requires order_item_data.');
        }

        return $lineItems;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $items
     */
    private function shippingAmount(array $order, array $items): float
    {
        $configured = $this->firstValue($order, ['shipping', 'Shipping', 'shippingCost', 'ShippingCost', 'fVersand', 'FVersand']);

        if ($configured !== null && $configured !== '') {
            return (float) $configured;
        }

        $shipping = 0.0;

        foreach ($items as $item) {
            if (!$this->isShippingLineItem($item)) {
                continue;
            }

            $shipping += (float) ($this->firstValue($item, [
                'SalesPriceGross',
                'salesPriceGross',
                'SalesPriceNet',
                'salesPriceNet',
                'price',
                'Price',
            ]) ?? 0);
        }

        return $shipping;
    }

    /** @param array<string, mixed> $item */
    private function isShippingLineItem(array $item): bool
    {
        $type = $this->firstValue($item, ['Type', 'type']);

        if ((string) $type === '2') {
            return true;
        }

        $name = strtolower((string) ($this->firstValue($item, ['Name', 'name', 'title', 'Title']) ?? ''));

        return str_contains($name, 'versand') || str_contains($name, 'shipping');
    }

    /** @param array<string, mixed> $customer */
    private function packiyoContact(array $address, array $customer): array
    {
        $normalized = $this->normalizeAddress($address);
        $name = trim((string) (($customer['name'] ?? '') ?: $this->fullName($normalized)));

        return [
            'name' => $name !== '' ? $name : 'Unknown',
            'company_name' => (string) ($normalized['company'] ?? ''),
            'address' => (string) ($normalized['address1'] ?? ''),
            'address2' => (string) ($normalized['address2'] ?? ''),
            'city' => (string) ($normalized['city'] ?? ''),
            'state' => (string) ($normalized['state'] ?? ''),
            'zip' => (string) ($normalized['postal_code'] ?? ''),
            'country' => (string) ($normalized['country'] ?? ''),
            'email' => (string) (($normalized['email'] ?? '') ?: ($customer['email'] ?? '')),
            'phone' => (string) (($normalized['phone'] ?? '') ?: ($customer['phone'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $data */
    private function fullName(array $data): ?string
    {
        $fullName = $this->firstString($data, ['name', 'Name', 'full_name', 'fullName', 'FullName']);

        if ($fullName !== null) {
            return $fullName;
        }

        $firstName = $this->firstString($data, ['first_name', 'firstName', 'firstname', 'FirstName']);
        $lastName = $this->firstString($data, ['last_name', 'lastName', 'lastname', 'LastName']);
        $name = trim((string) ($firstName . ' ' . $lastName));

        return $name !== '' ? $name : null;
    }

    private function packiyoDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return $value;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /** @param array<string, mixed> $data */
    private function firstString(array $data, array $keys): ?string
    {
        $value = $this->firstValue($data, $keys);

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
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

    /** @param array<string, mixed> $data */
    private function findMarketplaceOrderNumber(array $data, string $path = '', int $depth = 0): ?string
    {
        if ($depth > 8 || $this->pathLooksLikeOrderItems($path)) {
            return null;
        }

        foreach ($data as $key => $value) {
            $nextPath = $path === '' ? (string) $key : $path . '.' . (string) $key;

            if (is_array($value)) {
                $nested = $this->findMarketplaceOrderNumber($value, $nextPath, $depth + 1);

                if ($nested !== null) {
                    return $nested;
                }

                continue;
            }

            if (!is_scalar($value) || $value === null) {
                continue;
            }

            $string = trim((string) $value);

            if ($string === '' || !$this->pathLooksLikeMarketplaceOrderReference($nextPath)) {
                continue;
            }

            if (preg_match('/\bPO[A-Z0-9-]{5,}\b/i', $string, $matches) === 1) {
                return $matches[0];
            }
        }

        return null;
    }

    private function pathLooksLikeMarketplaceOrderReference(string $path): bool
    {
        return preg_match('/external|market|platform|channel|source|origin|order|reference|number|transaction/i', $path) === 1;
    }

    private function pathLooksLikeOrderItems(string $path): bool
    {
        return preg_match('/(^|\.)(items|line_items|lineItems|positions|salesOrderItems|salesOrderPositions|orderItems|orderPositions)(\.|$)/i', $path) === 1;
    }

    private function mappingModel(): OrderMapping
    {
        return $this->mappings ?? new OrderMapping();
    }

    private function customerResolver(): PackiyoCustomerResolver
    {
        return $this->customerResolver ?? new PackiyoCustomerResolver();
    }
}

final class InactivePackiyoCustomerException extends RuntimeException
{
}
