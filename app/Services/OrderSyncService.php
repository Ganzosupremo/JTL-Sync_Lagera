<?php

declare(strict_types=1);

namespace App\Services;

use App\Clients\JtlClient;
use App\Clients\PackiyoClient;
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

    /** @param array<string, mixed> $order */
    private function syncOrder(array $order): void
    {
        $jtlOrderId = $this->mapper()->jtlOrderId($order);

        if ($jtlOrderId === null) {
            throw new RuntimeException('JTL order without id was skipped.');
        }

        if ($this->mapper()->hasJtlOrder($jtlOrderId)) {
            throw new OrderAlreadySyncedException();
        }

        $items = $this->itemsFromOrder($order);
        $payload = $this->mapper()->toPackiyoPayload($order, $items);
        $packiyoOrder = $this->packiyoClient()->createOrder($payload);
        $packiyoOrderId = $this->mapper()->packiyoOrderId($packiyoOrder);

        if ($packiyoOrderId === null) {
            throw new RuntimeException('Packiyo response did not include an order id for JTL order ' . $jtlOrderId . '.');
        }

        $this->mapper()->save([
            'jtl_order_id' => $jtlOrderId,
            'jtl_order_number' => $this->mapper()->jtlOrderNumber($order),
            'packiyo_order_id' => $packiyoOrderId,
            'packiyo_order_number' => $this->mapper()->packiyoOrderNumber($packiyoOrder),
            'synced_at' => date('Y-m-d H:i:s'),
        ]);

        $this->log()->info('order_sync', 'Synced JTL order ' . $jtlOrderId . ' to Packiyo order ' . $packiyoOrderId . '.');
    }

    /**
     * @param array<string, mixed> $order
     * @return array<int, array<string, mixed>>
     */
    private function itemsFromOrder(array $order): array
    {
        foreach (['items', 'line_items', 'positions'] as $key) {
            if (isset($order[$key]) && is_array($order[$key])) {
                return array_values(array_filter($order[$key], 'is_array'));
            }
        }

        $jtlOrderId = $this->mapper()->jtlOrderId($order);

        if ($jtlOrderId === null) {
            return [];
        }

        return $this->jtlClient()->getOrderItems($jtlOrderId);
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
