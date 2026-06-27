<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\Logger;
use RuntimeException;
use Throwable;

final class AutomationService
{
    public function __construct(
        private readonly ?OrderSyncService $orders = null,
        private readonly ?FulfillmentSyncService $fulfillment = null,
        private readonly ?PackiyoCustomerSyncService $customers = null,
        private readonly ?Logger $logger = null
    ) {
    }

    /**
     * @return array{
     *     started_at: string,
     *     finished_at: string|null,
     *     locked: bool,
     *     customers: array<string, mixed>|null,
     *     orders: array<string, mixed>|null,
     *     fulfillment: array<string, mixed>|null,
     *     failed: int,
     *     message: string
     * }
     */
    public function run(): array
    {
        $startedAt = date('Y-m-d H:i:s');
        $lock = $this->acquireLock();

        if ($lock === null) {
            $message = 'Automation already running.';
            $this->log()->warning('automation', $message);

            return [
                'started_at' => $startedAt,
                'finished_at' => date('Y-m-d H:i:s'),
                'locked' => true,
                'customers' => null,
                'orders' => null,
                'fulfillment' => null,
                'failed' => 1,
                'message' => $message,
            ];
        }

        $summary = [
            'started_at' => $startedAt,
            'finished_at' => null,
            'locked' => false,
            'customers' => null,
            'orders' => null,
            'fulfillment' => null,
            'failed' => 0,
            'message' => '',
        ];

        $this->log()->info('automation', 'Automation run started.');

        try {
            if ((bool) Config::get('automation.sync_customers', false)) {
                $summary['customers'] = $this->runStep('customers', fn (): array => $this->customerService()->sync());
            }

            $summary['orders'] = $this->runStep('orders', fn (): array => $this->orderService()->sync());
            $summary['fulfillment'] = $this->runStep(
                'fulfillment',
                fn (): array => $this->fulfillmentService()->sync((int) Config::get('automation.fulfillment_limit', 200))
            );
        } finally {
            $this->releaseLock($lock);
        }

        $summary['failed'] = $this->failureCount($summary);
        $summary['finished_at'] = date('Y-m-d H:i:s');
        $summary['message'] = sprintf(
            'Automation finished. order_failed=%d fulfillment_failed=%d.',
            (int) ($summary['orders']['failed'] ?? 0),
            (int) ($summary['fulfillment']['failed'] ?? 0)
        );

        $this->log()->info('automation', $summary['message']);

        return $summary;
    }

    /**
     * @param callable(): array<string, mixed> $step
     * @return array<string, mixed>
     */
    private function runStep(string $name, callable $step): array
    {
        try {
            return $step();
        } catch (Throwable $exception) {
            $this->log()->error('automation', $name . ' failed: ' . $exception->getMessage());

            return [
                'failed' => 1,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /** @param array<string, mixed> $summary */
    private function failureCount(array $summary): int
    {
        return (int) ($summary['customers']['failed'] ?? 0)
            + (int) ($summary['orders']['failed'] ?? 0)
            + (int) ($summary['fulfillment']['failed'] ?? 0);
    }

    /** @return resource|null */
    private function acquireLock(): mixed
    {
        $path = BASE_PATH . '/storage/automation.lock';
        $handle = fopen($path, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open automation lock file.');
        }

        $locked = flock($handle, LOCK_EX | LOCK_NB);

        if (!$locked) {
            fclose($handle);
            return null;
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) time());

        return $handle;
    }

    /** @param resource $handle */
    private function releaseLock(mixed $handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function orderService(): OrderSyncService
    {
        return $this->orders ?? new OrderSyncService();
    }

    private function fulfillmentService(): FulfillmentSyncService
    {
        return $this->fulfillment ?? new FulfillmentSyncService();
    }

    private function customerService(): PackiyoCustomerSyncService
    {
        return $this->customers ?? new PackiyoCustomerSyncService();
    }

    private function log(): Logger
    {
        return $this->logger ?? new Logger();
    }
}
