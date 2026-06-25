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
    public function toPackiyoPayload(array $order, array $items): array
    {
        $jtlOrderId = $this->jtlOrderId($order);

        if ($jtlOrderId === null) {
            throw new RuntimeException('JTL order has no usable id.');
        }

        $jtlOrderNumber = $this->jtlOrderNumber($order);
        $lineItems = array_map(fn (array $item): array => $this->normalizeLineItem($item), $items);

        $attributes = [
            'number' => $jtlOrderNumber ?? $jtlOrderId,
            'order_channel_name' => (string) Config::get('packiyo.order_channel_name', 'JTL-Wawi'),
            'ordered_at' => $this->packiyoDate($this->firstString($order, ['ordered_at', 'created_at', 'date', 'Date', 'orderDate', 'OrderDate', 'creationDate', 'CreationDate'])),
            'external_id' => $jtlOrderId,
            'shipping' => (float) ($this->firstValue($order, ['shipping', 'Shipping', 'shippingCost', 'ShippingCost', 'fVersand', 'FVersand']) ?? 0),
            'tax' => (float) ($this->firstValue($order, ['tax', 'Tax', 'taxAmount', 'TaxAmount']) ?? 0),
            'discount' => (float) ($this->firstValue($order, ['discount', 'Discount', 'discountAmount', 'DiscountAmount']) ?? 0),
            'shipping_contact_information_data' => $this->packiyoContact(
                $this->firstArray($order, ['shipping_address', 'shippingAddress', 'ShippingAddress', 'deliveryAddress', 'DeliveryAddress']),
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
                'relationships' => [
                    'order_items' => [
                        'data' => array_map(
                            fn (array $item): array => [
                                'type' => 'order-items',
                                'attributes' => $item,
                            ],
                            $lineItems
                        ),
                    ],
                ],
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

        return [
            'name' => $this->firstString($customer, ['name', 'full_name', 'fullName'])
                ?? trim((string) ($this->firstString($customer, ['first_name', 'firstName']) . ' ' . $this->firstString($customer, ['last_name', 'lastName']))),
            'email' => $this->firstString($customer, ['email', 'mail']),
            'phone' => $this->firstString($customer, ['phone', 'telephone', 'mobile']),
        ];
    }

    /** @param array<string, mixed> $address */
    private function normalizeAddress(array $address): array
    {
        return [
            'first_name' => $this->firstString($address, ['first_name', 'firstName', 'firstname']),
            'last_name' => $this->firstString($address, ['last_name', 'lastName', 'lastname']),
            'company' => $this->firstString($address, ['company', 'companyName']),
            'address1' => $this->firstString($address, ['address1', 'street', 'street1']),
            'address2' => $this->firstString($address, ['address2', 'street2']),
            'postal_code' => $this->firstString($address, ['postal_code', 'zip', 'zipcode', 'postalCode']),
            'state' => $this->firstString($address, ['state', 'State', 'province', 'Province', 'region', 'Region']),
            'city' => $this->firstString($address, ['city']),
            'country' => $this->firstString($address, ['country', 'country_code', 'countryCode']),
            'email' => $this->firstString($address, ['email']),
            'phone' => $this->firstString($address, ['phone', 'telephone']),
        ];
    }

    /** @param array<string, mixed> $item */
    private function normalizeLineItem(array $item): array
    {
        return [
            'sku' => $this->firstString($item, ['sku', 'SKU', 'articleNumber', 'ArticleNumber', 'itemNumber', 'ItemNumber', 'cArtNr', 'CArtNr']),
            'quantity' => (float) ($this->firstValue($item, ['quantity', 'Quantity', 'qty', 'Qty', 'amount', 'Amount', 'nAnzahl', 'NAnzahl']) ?? 1),
            'external_id' => $this->firstString($item, ['external_id', 'externalId', 'ExternalId', 'id', 'Id', 'positionId', 'PositionId'])
                ?? $this->firstString($item, ['sku', 'SKU', 'articleNumber', 'ArticleNumber', 'itemNumber', 'ItemNumber', 'cArtNr', 'CArtNr'])
                ?? uniqid('jtl-item-', true),
            'price' => (float) ($this->firstValue($item, ['price', 'Price', 'unit_price', 'unitPrice', 'UnitPrice', 'fVKNetto', 'FVKNetto']) ?? 0),
        ];
    }

    /** @param array<string, mixed> $customer */
    private function packiyoContact(array $address, array $customer): array
    {
        $normalized = $this->normalizeAddress($address);
        $name = trim((string) (($customer['name'] ?? '') ?: trim(($normalized['first_name'] ?? '') . ' ' . ($normalized['last_name'] ?? ''))));

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
