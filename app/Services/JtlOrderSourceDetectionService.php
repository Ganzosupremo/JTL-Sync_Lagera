<?php

declare(strict_types=1);

namespace App\Services;

use App\Clients\JtlClient;
use App\Models\JtlOrderSource;
use App\Support\Logger;

final class JtlOrderSourceDetectionService
{
    public function __construct(
        private readonly ?JtlClient $jtl = null,
        private readonly ?JtlOrderSource $sources = null,
        private readonly ?PackiyoCustomerResolver $resolver = null,
        private readonly ?MappingService $mapping = null,
        private readonly ?Logger $logger = null
    ) {
    }

    /** @return array{orders: int, sources: int} */
    public function detect(): array
    {
        $orders = $this->jtlClient()->getOrders();
        $detected = [];

        foreach ($orders as $order) {
            $orderId = $this->mapping()->jtlOrderId($order);
            $orderNumber = $this->mapping()->jtlOrderNumber($order);

            foreach ($this->resolver()->sourceCandidateDetails($order) as $source) {
                $key = $source['source_type'] . ':' . $this->normalize($source['source_value']);

                if (!isset($detected[$key])) {
                    $detected[$key] = [
                        'source_type' => $source['source_type'],
                        'source_value' => $source['source_value'],
                        'source_path' => $source['source_path'],
                        'order_count' => 0,
                        'sample_order_id' => $orderId,
                        'sample_order_number' => $orderNumber,
                    ];
                }

                $detected[$key]['order_count']++;
            }
        }

        $this->sourceModel()->upsertDetected($detected);

        $message = sprintf(
            'JTL order source detection finished. orders=%d sources=%d',
            count($orders),
            count($detected)
        );
        $this->log()->info('jtl_sources', $message);

        return [
            'orders' => count($orders),
            'sources' => count($detected),
        ];
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }

    private function jtlClient(): JtlClient
    {
        return $this->jtl ?? new JtlClient();
    }

    private function sourceModel(): JtlOrderSource
    {
        return $this->sources ?? new JtlOrderSource();
    }

    private function resolver(): PackiyoCustomerResolver
    {
        return $this->resolver ?? new PackiyoCustomerResolver();
    }

    private function mapping(): MappingService
    {
        return $this->mapping ?? new MappingService();
    }

    private function log(): Logger
    {
        return $this->logger ?? new Logger();
    }
}
