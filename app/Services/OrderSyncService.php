<?php

declare(strict_types=1);

namespace App\Services;

use App\Clients\JtlClient;
use App\Clients\PackiyoClient;
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

    /** @return array{total: int, created: int, skipped: int, failed: int} */
    public function sync(): array
    {
        $summary = [
            'total' => 0,
            'created' => 0,
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

        $summary['total'] = count($orders);

        foreach ($orders as $order) {
            try {
                $this->syncOrder($order);
                $summary['created']++;
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
                'Order sync finished. total=%d created=%d skipped=%d failed=%d',
                $summary['total'],
                $summary['created'],
                $summary['skipped'],
                $summary['failed']
            )
        );

        return $summary;
    }

    /** @return array{total: int, created: int, skipped: int, failed: int, message: string} */
    public function syncOne(string $reference, bool $force = false, bool $resendArchived = false): array
    {
        $reference = trim($reference);
        $summary = [
            'total' => 1,
            'created' => 0,
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
            $this->syncOrder($order, $force, $resendArchived);
            $summary['created'] = 1;
            $summary['message'] = 'Orden JTL ' . $this->orderLabel($order) . ' enviada a Packiyo.';
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
    private function syncOrder(array $order, bool $force = false, bool $resendArchived = false): void
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
        $packiyoOrder = $this->packiyoClient()->createOrder($payload);
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

        $this->log()->info('order_sync', 'Synced JTL order ' . $jtlOrderId . ' to Packiyo order ' . $packiyoOrderId . '.');
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
