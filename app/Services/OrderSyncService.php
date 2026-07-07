<?php

declare(strict_types=1);

namespace App\Services;

use App\Clients\JtlClient;
use App\Clients\PackiyoClient;
use App\Models\PackiyoCustomerMapping;
use App\Support\HttpException;
use App\Support\Logger;
use RuntimeException;
use Throwable;

final class OrderSyncService
{
    public function __construct(
        private readonly ?JtlClient $jtl = null,
        private readonly ?PackiyoClient $packiyo = null,
        private readonly ?MappingService $mapping = null,
        private readonly ?Logger $logger = null
    ) {
    }

    /** @return array{total: int, created: int, linked: int, skipped: int, failed: int} */
    public function sync(?string $customerFilter = null, ?string $mappedCustomerFilter = null): array
    {
        $summary = [
            'total' => 0,
            'created' => 0,
            'linked' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $this->log()->info('order_sync', 'Order sync started.');

        try {
            $orders = $this->jtlClient()->getOrders();
        } catch (Throwable $exception) {
            $summary['failed']++;
            $this->log()->error('jtl', 'Unable to read JTL orders: ' . $exception->getMessage());
            return $summary;
        }

        $orders = $this->filterOrders($orders, $customerFilter, $mappedCustomerFilter);
        $summary['total'] = count($orders);

        foreach ($orders as $order) {
            try {
                $result = $this->syncOrder($order);
                $summary[$result]++;
            } catch (OrderAlreadySyncedException) {
                $summary['skipped']++;
            } catch (InactivePackiyoCustomerException $exception) {
                $summary['skipped']++;
                $this->log()->info('order_sync', $exception->getMessage());
            } catch (Throwable $exception) {
                $summary['failed']++;
                $this->log()->error('order_sync', $exception->getMessage());
            }
        }

        $this->log()->info(
            'order_sync',
            sprintf(
                'Order sync finished. total=%d created=%d linked=%d skipped=%d failed=%d',
                $summary['total'],
                $summary['created'],
                $summary['linked'],
                $summary['skipped'],
                $summary['failed']
            )
        );

        return $summary;
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private function filterOrders(array $orders, ?string $customerFilter, ?string $mappedCustomerFilter): array
    {
        $customerFilter = strtolower(trim((string) $customerFilter));
        $mappedCustomerFilter = trim((string) $mappedCustomerFilter);

        if ($customerFilter === '' && $mappedCustomerFilter === '') {
            return $orders;
        }

        $resolver = new PackiyoCustomerResolver();
        $customerMappings = new PackiyoCustomerMapping();

        return array_values(array_filter(
            $orders,
            function (array $order) use ($customerFilter, $mappedCustomerFilter, $resolver, $customerMappings): bool {
                if ($customerFilter !== '') {
                    $contact = strtolower((string) ($this->orderContact($order) ?? ''));

                    if (!str_contains($contact, $customerFilter)) {
                        return false;
                    }
                }

                if ($mappedCustomerFilter === '') {
                    return true;
                }

                $mapping = $customerMappings->findForCandidates($resolver->candidates($order));

                if ($mappedCustomerFilter === '__unmapped__') {
                    return $mapping === null;
                }

                return $mapping !== null
                    && (string) ($mapping['packiyo_customer_id'] ?? '') === $mappedCustomerFilter;
            }
        ));
    }

    /** @return array{total: int, created: int, linked: int, skipped: int, failed: int, message: string} */
    public function syncOne(string $reference, bool $force = false, bool $resendArchived = false): array
    {
        $reference = trim($reference);
        $summary = [
            'total' => 1,
            'created' => 0,
            'linked' => 0,
            'skipped' => 0,
            'failed' => 0,
            'message' => '',
        ];

        if ($reference === '') {
            $summary['failed'] = 1;
            $summary['message'] = 'Referencia de orden JTL requerida.';
            return $summary;
        }

        $this->log()->info('order_sync', 'Manual order sync requested for ' . $reference . '.');

        try {
            $order = $this->findOrder($reference);
            $result = $this->syncOrder($order, $force, $resendArchived);
            $summary[$result] = 1;
            $summary['message'] = $result === 'linked'
                ? 'Orden JTL ' . $this->orderLabel($order) . ' ya existia en Packiyo y quedo marcada como enviada.'
                : 'Orden JTL ' . $this->orderLabel($order) . ' enviada a Packiyo.';
        } catch (OrderAlreadySyncedException) {
            $summary['skipped'] = 1;
            $summary['message'] = 'Orden JTL ' . $reference . ' ya estaba sincronizada.';
        } catch (InactivePackiyoCustomerException $exception) {
            $summary['skipped'] = 1;
            $summary['message'] = $exception->getMessage();
            $this->log()->info('order_sync', $exception->getMessage());
        } catch (Throwable $exception) {
            $summary['failed'] = 1;
            $summary['message'] = 'No se pudo sincronizar la orden JTL ' . $reference . ': ' . $exception->getMessage();
            $this->log()->error('order_sync', $summary['message']);
        }

        return $summary;
    }

    /** @param array<string, mixed> $order */
    private function syncOrder(array $order, bool $force = false, bool $resendArchived = false): string
    {
        $jtlOrderId = $this->mapper()->jtlOrderId($order);

        if ($jtlOrderId === null) {
            throw new RuntimeException('JTL order without id was skipped. Received keys: ' . implode(', ', array_keys($order)));
        }

        $replaceExistingMapping = false;

        if ($this->mapper()->hasJtlOrder($jtlOrderId)) {
            if (!$force) {
                throw new OrderAlreadySyncedException();
            }

            $replaceExistingMapping = true;
            $this->log()->warning('order_sync', 'Forced resync requested for JTL order ' . $jtlOrderId . '. Existing local mapping will be replaced after Packiyo accepts the order.');
        }

        $items = $this->itemsFromOrder($order);
        $externalIdOverride = null;
        $numberOverride = null;
        $lineItemExternalIdSuffix = null;

        if ($resendArchived) {
            $resendSuffix = 'R' . date('YmdHis');
            $basePackiyoNumber = (string) (
                $this->mapper()->marketplaceOrderNumber($order)
                ?? $this->mapper()->jtlOrderNumber($order)
                ?? $jtlOrderId
            );
            $externalIdOverride = $basePackiyoNumber . '-resend-' . $resendSuffix;
            $numberOverride = $basePackiyoNumber . '-' . $resendSuffix;
            $lineItemExternalIdSuffix = $resendSuffix;
            $this->log()->warning(
                'order_sync',
                'Resending archived Packiyo order for JTL order ' . $jtlOrderId
                . ' with new Packiyo number ' . $numberOverride
                . ' and external_id ' . $externalIdOverride . '.'
            );
        }

        $payload = $this->mapper()->toPackiyoPayload(
            $order,
            $items,
            $externalIdOverride,
            $numberOverride,
            $lineItemExternalIdSuffix
        );
        $packiyoOrder = $resendArchived ? null : $this->findExistingPackiyoOrder($payload, $order);
        $linkedExistingOrder = $packiyoOrder !== null;

        if ($packiyoOrder === null) {
            try {
                $packiyoOrder = $this->packiyoClient()->createOrder($payload);
            } catch (HttpException $exception) {
                if (!$this->looksLikeExistingPackiyoOrderError($exception)) {
                    throw $exception;
                }

                $packiyoOrder = $this->findExistingPackiyoOrder($payload, $order);

                if ($packiyoOrder === null) {
                    throw $exception;
                }

                $linkedExistingOrder = true;
            }
        }

        $packiyoOrderId = $this->mapper()->packiyoOrderId($packiyoOrder);

        if ($packiyoOrderId === null) {
            throw new RuntimeException('Packiyo response did not include an order id for JTL order ' . $jtlOrderId . '.');
        }

        if ($replaceExistingMapping) {
            $this->mapper()->deleteJtlOrder($jtlOrderId);
        }

        $this->mapper()->save([
            'jtl_order_id' => $jtlOrderId,
            'jtl_order_number' => $this->mapper()->jtlOrderNumber($order),
            'packiyo_order_id' => $packiyoOrderId,
            'packiyo_order_number' => $this->mapper()->packiyoOrderNumber($packiyoOrder)
                ?? $numberOverride
                ?? $this->mapper()->marketplaceOrderNumber($order)
                ?? $this->mapper()->jtlOrderNumber($order),
            'synced_at' => date('Y-m-d H:i:s'),
        ]);

        if ($linkedExistingOrder) {
            $this->log()->info('order_sync', 'Linked existing Packiyo order ' . $packiyoOrderId . ' to JTL order ' . $jtlOrderId . '.');
            return 'linked';
        }

        $this->log()->info('order_sync', 'Synced JTL order ' . $jtlOrderId . ' to Packiyo order ' . $packiyoOrderId . '.');

        return 'created';
    }

    /** @return array<string, mixed> */
    private function findOrder(string $reference): array
    {
        $orders = $this->jtlClient()->getOrders();

        foreach ($orders as $order) {
            if ($this->orderMatches($order, $reference)) {
                return $order;
            }
        }

        if (preg_match('/^\d+$/', $reference) === 1) {
            try {
                $detail = $this->unwrapOrder($this->jtlClient()->getOrder($reference));

                if ($detail !== [] && $this->mapper()->jtlOrderId($detail) !== null) {
                    return $detail;
                }
            } catch (Throwable $exception) {
                $this->log()->warning('jtl', 'Unable to read JTL order ' . $reference . ' directly: ' . $exception->getMessage());
            }
        }

        throw new RuntimeException('JTL order not found in current order list.');
    }

    /** @param array<string, mixed> $order */
    private function orderMatches(array $order, string $reference): bool
    {
        $id = $this->mapper()->jtlOrderId($order);
        $number = $this->mapper()->jtlOrderNumber($order);
        $marketplaceNumber = $this->mapper()->marketplaceOrderNumber($order);

        return $reference === (string) $id
            || $reference === (string) $number
            || $reference === (string) $marketplaceNumber;
    }

    /** @param array<string, mixed> $order */
    private function orderLabel(array $order): string
    {
        return $this->mapper()->jtlOrderNumber($order)
            ?? $this->mapper()->jtlOrderId($order)
            ?? 'desconocida';
    }

    /** @param array<string, mixed> $order */
    private function orderContact(array $order): ?string
    {
        $customer = $this->firstArray($order, ['customer', 'Customer', 'customer_data', 'CustomerData', 'client', 'Client']);
        $billing = $this->firstArray($order, ['billing_address', 'billingAddress', 'BillingAddress', 'invoiceAddress', 'InvoiceAddress']);
        $shipping = $this->firstArray($order, ['shipping_address', 'shippingAddress', 'ShippingAddress', 'deliveryAddress', 'DeliveryAddress', 'Shipmentaddress', 'ShipmentAddress', 'shipmentAddress']);

        return $this->fullName($shipping)
            ?? $this->fullName($billing)
            ?? $this->fullName($customer)
            ?? $this->firstString($shipping, ['email', 'Email', 'mail', 'Mail', 'EmailAddress'])
            ?? $this->firstString($billing, ['email', 'Email', 'mail', 'Mail', 'EmailAddress']);
    }

    /** @param array<string, mixed> $data */
    private function firstArray(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        return [];
    }

    /** @param array<string, mixed> $data */
    private function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || !is_scalar($data[$key])) {
                continue;
            }

            $value = trim((string) $data[$key]);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
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

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $order
     * @return array<string, mixed>|null
     */
    private function findExistingPackiyoOrder(array $payload, array $order): ?array
    {
        foreach ($this->packiyoOrderLookupCandidates($payload, $order) as $candidate) {
            try {
                $response = $candidate['type'] === 'number'
                    ? $this->packiyoClient()->findOrderByNumber($candidate['value'])
                    : $this->packiyoClient()->findOrder($candidate['value']);
                $found = $this->firstPackiyoOrder($response);

                if ($found !== null) {
                    $this->log()->info(
                        'order_sync',
                        'Found existing Packiyo order by ' . $candidate['type'] . ' ' . $candidate['value'] . '.'
                    );

                    return $found;
                }
            } catch (HttpException $exception) {
                if (in_array($exception->statusCode(), [400, 404], true)) {
                    continue;
                }

                $this->log()->warning(
                    'order_sync',
                    'Unable to lookup existing Packiyo order by ' . $candidate['type'] . ' '
                    . $candidate['value'] . ': ' . $exception->getMessage()
                );
            } catch (Throwable $exception) {
                $this->log()->warning(
                    'order_sync',
                    'Unable to lookup existing Packiyo order by ' . $candidate['type'] . ' '
                    . $candidate['value'] . ': ' . $exception->getMessage()
                );
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $order
     * @return array<int, array{type: string, value: string}>
     */
    private function packiyoOrderLookupCandidates(array $payload, array $order): array
    {
        $attributes = $payload['data']['attributes'] ?? [];
        $attributes = is_array($attributes) ? $attributes : [];
        $candidateGroups = [
            'external_id' => [
                $attributes['external_id'] ?? null,
                $this->mapper()->marketplaceOrderNumber($order),
                $this->mapper()->jtlOrderId($order),
                $this->mapper()->jtlOrderNumber($order),
            ],
            'number' => [
                $attributes['number'] ?? null,
                $this->mapper()->marketplaceOrderNumber($order),
                $this->mapper()->jtlOrderNumber($order),
                $this->mapper()->jtlOrderId($order),
            ],
        ];
        $seen = [];
        $candidates = [];

        foreach ($candidateGroups as $type => $values) {
            foreach ($values as $value) {
                if (!is_scalar($value)) {
                    continue;
                }

                $value = trim((string) $value);
                $key = $type . ':' . strtolower($value);

                if ($value === '' || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $candidates[] = [
                    'type' => $type,
                    'value' => $value,
                ];
            }
        }

        return $candidates;
    }

    private function looksLikeExistingPackiyoOrderError(HttpException $exception): bool
    {
        if (in_array($exception->statusCode(), [409, 422], true)) {
            return true;
        }

        if ($exception->statusCode() !== 500) {
            return false;
        }

        return preg_match('/already|duplicate|exists|unique|taken|external_id|number/i', $exception->getMessage()) === 1;
    }

    /** @param array<string, mixed> $response */
    private function firstPackiyoOrder(array $response): ?array
    {
        $data = $response['data'] ?? $response['Data'] ?? null;

        if (!is_array($data) || $data === []) {
            return null;
        }

        if (array_is_list($data)) {
            foreach ($data as $item) {
                if (is_array($item)) {
                    return $item;
                }
            }

            return null;
        }

        return $data;
    }

    /** @param array<string, mixed> $response */
    private function unwrapOrder(array $response): array
    {
        foreach (['data', 'Data', 'order', 'Order', 'salesOrder', 'SalesOrder'] as $key) {
            if (isset($response[$key]) && is_array($response[$key]) && !array_is_list($response[$key])) {
                return $response[$key];
            }
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<int, array<string, mixed>>
     */
    private function itemsFromOrder(array $order): array
    {
        $embeddedItems = $this->extractItems($order);

        if ($embeddedItems !== []) {
            return $embeddedItems;
        }

        $jtlOrderId = $this->mapper()->jtlOrderId($order);

        if ($jtlOrderId === null) {
            return [];
        }

        try {
            $detail = $this->jtlClient()->getOrder($jtlOrderId);
            $detailItems = $this->extractItems($detail);

            if ($detailItems !== []) {
                return $detailItems;
            }
        } catch (Throwable $exception) {
            $this->log()->warning('jtl', 'Unable to read JTL order detail for ' . $jtlOrderId . ': ' . $exception->getMessage());
        }

        try {
            return $this->jtlClient()->getOrderItems($jtlOrderId);
        } catch (HttpException $exception) {
            if ($exception->statusCode() !== 404) {
                throw $exception;
            }

            $this->log()->warning(
                'jtl',
                'JTL items endpoint not available for order ' . $jtlOrderId . '. Continuing without line items.'
            );

            return [];
        }
    }

    /**
     * @param array<string, mixed> $order
     * @return array<int, array<string, mixed>>
     */
    private function extractItems(array $order): array
    {
        foreach ([
            'items',
            'Items',
            'line_items',
            'lineItems',
            'LineItems',
            'positions',
            'Positions',
            'salesOrderItems',
            'SalesOrderItems',
            'salesOrderPositions',
            'SalesOrderPositions',
            'orderItems',
            'OrderItems',
            'orderPositions',
            'OrderPositions',
        ] as $key) {
            if (isset($order[$key]) && is_array($order[$key])) {
                return array_values(array_filter($order[$key], 'is_array'));
            }
        }

        foreach (['data', 'Data', 'order', 'Order', 'salesOrder', 'SalesOrder'] as $key) {
            if (isset($order[$key]) && is_array($order[$key])) {
                $items = $this->extractItems($order[$key]);

                if ($items !== []) {
                    return $items;
                }
            }
        }

        return [];
    }

    private function jtlClient(): JtlClient
    {
        return $this->jtl ?? new JtlClient();
    }

    private function packiyoClient(): PackiyoClient
    {
        return $this->packiyo ?? new PackiyoClient();
    }

    private function mapper(): MappingService
    {
        return $this->mapping ?? new MappingService();
    }

    private function log(): Logger
    {
        return $this->logger ?? new Logger();
    }
}

final class OrderAlreadySyncedException extends RuntimeException
{
}
