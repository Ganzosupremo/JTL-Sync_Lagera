<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OrderMapping;
use RuntimeException;

final class MappingService
{
    public function __construct(private readonly ?OrderMapping $mappings = null)
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

        return [
            'external_id' => $jtlOrderId,
            'order_number' => $jtlOrderNumber ?? $jtlOrderId,
            'ordered_at' => $this->firstString($order, ['ordered_at', 'created_at', 'date', 'orderDate']),
            'customer' => $this->normalizeCustomer($order),
            'shipping_address' => $this->normalizeAddress($this->firstArray($order, ['shipping_address', 'shippingAddress', 'deliveryAddress'])),
            'billing_address' => $this->normalizeAddress($this->firstArray($order, ['billing_address', 'billingAddress', 'invoiceAddress'])),
            'line_items' => array_map(fn (array $item): array => $this->normalizeLineItem($item), $items),
            'metadata' => [
                'source' => 'jtl',
                'jtl_order_id' => $jtlOrderId,
                'jtl_order_number' => $jtlOrderNumber,
            ],
        ];
    }

    /** @param array<string, mixed> $order */
    public function jtlOrderId(array $order): ?string
    {
        return $this->firstString($order, ['jtl_order_id', 'id', 'order_id', 'orderId', 'OrderId', 'kBestellung']);
    }

    /** @param array<string, mixed> $order */
    public function jtlOrderNumber(array $order): ?string
    {
        return $this->firstString($order, ['jtl_order_number', 'order_number', 'orderNumber', 'number', 'cBestellNr']);
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

        return $this->firstString($order, ['packiyo_order_number', 'order_number', 'orderNumber', 'number'])
            ?? $this->firstString($data, ['order_number', 'orderNumber', 'number']);
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
            'sku' => $this->firstString($item, ['sku', 'SKU', 'articleNumber', 'itemNumber', 'cArtNr']),
            'name' => $this->firstString($item, ['name', 'title', 'description', 'cName']),
            'quantity' => (float) ($this->firstValue($item, ['quantity', 'qty', 'amount', 'nAnzahl']) ?? 1),
            'price' => (float) ($this->firstValue($item, ['price', 'unit_price', 'unitPrice', 'fVKNetto']) ?? 0),
        ];
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
}
