<?php

declare(strict_types=1);

namespace App\Services;

use App\Clients\JtlClient;
use App\Models\OrderDraft;
use App\Models\OrderMapping;
use App\Models\PackiyoCustomerMapping;
use RuntimeException;
use Throwable;

final class OrderDetailService
{
    public function __construct(
        private readonly ?JtlClient $jtl = null,
        private readonly ?OrderDraft $drafts = null,
        private readonly ?OrderPreparationService $preparation = null
    ) {
    }

    /** @return array<string, mixed> */
    /** @param array<int, array<string, mixed>>|null $knownOrders */
    public function load(string $reference, bool $ignoreDraft = false, ?array $knownOrders = null): array
    {
        $reference = trim($reference);
        $storedDraft = $this->draftModel()->find($reference);
        $storedSource = is_array($storedDraft['source'] ?? null) ? $storedDraft['source'] : [];
        $order = is_array($storedSource['order'] ?? null)
            ? $storedSource['order']
            : $this->findOrder($reference, $knownOrders);
        $mapper = new MappingService();
        $id = $mapper->jtlOrderId($order);
        if ($id === null) {
            throw new RuntimeException('La orden JTL no tiene un ID utilizable.');
        }
        $detailLoaded = false;
        try {
            $fullOrder = $this->unwrap($this->jtlClient()->getOrder($id));
            if ($fullOrder !== []) {
                $order = array_replace($order, $fullOrder);
                $detailLoaded = true;
            }
        } catch (Throwable) {
        }
        $items = is_array($storedSource['items'] ?? null)
            ? array_values(array_filter($storedSource['items'], 'is_array'))
            : $this->itemsFromOrder($order, $detailLoaded);
        $mapping = (new PackiyoCustomerMapping())->findForCandidates((new PackiyoCustomerResolver())->candidates($order));
        $customerId = trim((string) ($mapping['packiyo_customer_id'] ?? ''));
        $draft = $ignoreDraft ? null : ($storedDraft ?? $this->draftModel()->find($id));
        $data = is_array($draft['data'] ?? null) ? $draft['data'] : [
            'shipping_address' => $this->firstArray($order, ['shipping_address', 'shippingAddress', 'ShippingAddress', 'deliveryAddress', 'DeliveryAddress', 'Shipmentaddress', 'ShipmentAddress', 'shipmentAddress']),
            'billing_address' => $this->firstArray($order, ['billing_address', 'billingAddress', 'BillingAddress', 'invoiceAddress', 'InvoiceAddress']),
            'items' => $this->preparationService()->prepareItems($items, $customerId !== '' ? $customerId : null, false),
            'shipping' => $this->preparationService()->shippingAmount($items),
        ];
        if (!array_key_exists('shipping', $data)) {
            $data['shipping'] = $this->preparationService()->shippingAmount($items);
        }
        $catalog = [];
        $catalogError = null;
        if ($customerId !== '') {
            try {
                $catalog = $this->preparationService()->catalog($customerId, false);
            } catch (Throwable $exception) {
                $catalogError = $exception->getMessage();
            }
        }
        $sentMapping = (new OrderMapping())->findByJtlOrderId($id);

        return [
            'id' => $id,
            'number' => $mapper->jtlOrderNumber($order) ?? '',
            'order' => $order,
            'source_items' => $items,
            'source' => ['order' => $order, 'items' => $items],
            'data' => $data,
            'draft' => $draft,
            'customer_id' => $customerId,
            'customer_name' => (string) ($mapping['packiyo_customer_name'] ?? ''),
            'catalog' => $catalog,
            'catalog_error' => $catalogError,
            'errors' => $this->preparationService()->validationErrors(is_array($data['items'] ?? null) ? $data['items'] : []),
            'readonly' => $sentMapping !== null || (string) ($storedDraft['status'] ?? '') === 'sent',
            'order_mapping' => $sentMapping,
        ];
    }

    /** @return array<string, mixed> */
    private function findOrder(string $reference, ?array $knownOrders = null): array
    {
        $mapper = new MappingService();
        $orders = $knownOrders ?? $this->jtlClient()->getOrders();
        foreach ($orders as $order) {
            if ($reference === (string) $mapper->jtlOrderId($order)
                || $reference === (string) $mapper->jtlOrderNumber($order)
                || $reference === (string) $mapper->marketplaceOrderNumber($order)) {
                return $order;
            }
        }
        if ($reference !== '') {
            $detail = $this->unwrap($this->jtlClient()->getOrder($reference));
            if ($detail !== []) {
                return $detail;
            }
        }
        throw new RuntimeException('No se encontro la orden JTL solicitada.');
    }

    /** @param array<string, mixed> $order @return array<int, array<string, mixed>> */
    private function itemsFromOrder(array $order, bool $detailLoaded = false): array
    {
        $items = $this->extractItems($order);
        if ($items !== []) {
            return $items;
        }
        $id = (new MappingService())->jtlOrderId($order);
        if ($id === null) {
            return [];
        }
        if (!$detailLoaded) {
            try {
            $detailItems = $this->extractItems($this->jtlClient()->getOrder($id));
            if ($detailItems !== []) {
                return $detailItems;
            }
            } catch (Throwable) {
            }
        }
        return $this->jtlClient()->getOrderItems($id);
    }

    /** @param array<string, mixed> $data @return array<int, array<string, mixed>> */
    private function extractItems(array $data): array
    {
        foreach (['items', 'Items', 'line_items', 'lineItems', 'LineItems', 'positions', 'Positions', 'salesOrderItems', 'SalesOrderItems', 'salesOrderPositions', 'SalesOrderPositions', 'orderItems', 'OrderItems', 'orderPositions', 'OrderPositions'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_values(array_filter($data[$key], 'is_array'));
            }
        }
        foreach (['data', 'Data', 'order', 'Order', 'salesOrder', 'SalesOrder'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $items = $this->extractItems($data[$key]);
                if ($items !== []) {
                    return $items;
                }
            }
        }
        return [];
    }

    /** @param array<string, mixed> $response @return array<string, mixed> */
    private function unwrap(array $response): array
    {
        foreach (['data', 'Data', 'order', 'Order', 'salesOrder', 'SalesOrder'] as $key) {
            if (isset($response[$key]) && is_array($response[$key]) && !array_is_list($response[$key])) {
                return $response[$key];
            }
        }
        return $response;
    }

    /** @param array<string, mixed> $data @param array<int, string> $keys @return array<string, mixed> */
    private function firstArray(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }
        return [];
    }

    private function jtlClient(): JtlClient
    {
        return $this->jtl ?? new JtlClient();
    }

    private function draftModel(): OrderDraft
    {
        return $this->drafts ?? new OrderDraft();
    }

    private function preparationService(): OrderPreparationService
    {
        return $this->preparation ?? new OrderPreparationService();
    }
}
