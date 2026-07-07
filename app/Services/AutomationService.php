<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSyncState;
use App\Support\Config;
use App\Support\Logger;
use RuntimeException;
use Throwable;

final class AutomationService
{
    private const STATE_KEY = 'automation';

    public function __construct(
        private readonly ?OrderSyncService $orders = null,
        private readonly ?FulfillmentSyncService $fulfillment = null,
        private readonly ?PackiyoCustomerSyncService $customers = null,
        private readonly ?AppSyncState $states = null,
        private readonly ?Logger $logger = null
    ) {
    }

    /** @return array<string, mixed> */
    public function runIfDue(bool $force = false): array
    {
        $enabled = (bool) Config::get('automation.enabled', true);
        $intervalMinutes = $this->intervalMinutes();
        $state = $this->stateModel()->get(self::STATE_KEY);
        $lastRunAt = $this->lastRunTimestamp($state);
        $nextRunAt = $lastRunAt === null ? null : $lastRunAt + ($intervalMinutes * 60);
        $now = time();

        if (!$force && !$enabled) {
            return $this->skippedSummary('Automation disabled in settings.', $intervalMinutes, $lastRunAt, $nextRunAt);
        }

        if (!$force && $nextRunAt !== null && $now < $nextRunAt) {
            return $this->skippedSummary(
                'Automation skipped. Next run at ' . date('Y-m-d H:i:s', $nextRunAt) . '.',
                $intervalMinutes,
                $lastRunAt,
                $nextRunAt
            );
        }

        $summary = $this->run();
        $summary['skipped'] = false;
        $summary['interval_minutes'] = $intervalMinutes;
        $summary['last_run_at'] = $lastRunAt !== null ? date('Y-m-d H:i:s', $lastRunAt) : null;
        $summary['next_run_at'] = date('Y-m-d H:i:s', time() + ($intervalMinutes * 60));

        return $summary;
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
            if ((bool) ($summary['orders']['jtl_unreachable'] ?? false)) {
                $summary['fulfillment'] = [
                    'checked' => 0,
                    'fulfilled' => 0,
                    'synced' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'message' => 'Fulfillment sync omitido porque JTL no esta reachable.',
                ];
                $this->log()->warning('automation', $summary['fulfillment']['message']);
            } else {
                $summary['fulfillment'] = $this->runStep(
                    'fulfillment',
                    fn (): array => $this->fulfillmentService()->sync((int) Config::get('automation.fulfillment_limit', 200))
                );
            }
        } finally {
            $this->releaseLock($lock);
        }

        $summary['failed'] = $this->failureCount($summary);
        $summary['finished_at'] = date('Y-m-d H:i:s');
        $summary['message'] = sprintf(
            'Automation finished. order_created=%d order_linked=%d order_skipped=%d already_synced=%d unmapped=%d jtl_unreachable=%d order_failed=%d fulfillment_failed=%d.',
            (int) ($summary['orders']['created'] ?? 0),
            (int) ($summary['orders']['linked'] ?? 0),
            (int) ($summary['orders']['skipped'] ?? 0),
            (int) ($summary['orders']['already_synced'] ?? 0),
            (int) ($summary['orders']['unmapped'] ?? 0),
            (bool) ($summary['orders']['jtl_unreachable'] ?? false) ? 1 : 0,
            (int) ($summary['orders']['failed'] ?? 0),
            (int) ($summary['fulfillment']['failed'] ?? 0)
        );

        $this->log()->info('automation', $summary['message']);
        $this->recordState($summary);

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

    /** @param array<string, mixed> $summary */
    private function recordState(array $summary): void
    {
        $finishedAt = is_string($summary['finished_at'] ?? null) ? $summary['finished_at'] : date('Y-m-d H:i:s');
        $message = is_string($summary['message'] ?? null) ? $summary['message'] : 'Automation finished.';

        $this->stateModel()->markSuccess(self::STATE_KEY, $finishedAt, $message);
    }

    private function intervalMinutes(): int
    {
        return max(1, (int) Config::get('automation.interval_minutes', 360));
    }

    /** @param array<string, mixed>|null $state */
    private function lastRunTimestamp(?array $state): ?int
    {
        $value = $state['last_success_at'] ?? $state['updated_at'] ?? null;

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    /** @return array<string, mixed> */
    private function skippedSummary(string $message, int $intervalMinutes, ?int $lastRunAt, ?int $nextRunAt): array
    {
        $this->log()->info('automation', $message);

        return [
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => date('Y-m-d H:i:s'),
            'locked' => false,
            'skipped' => true,
            'customers' => null,
            'orders' => null,
            'fulfillment' => null,
            'failed' => 0,
            'message' => $message,
            'interval_minutes' => $intervalMinutes,
            'last_run_at' => $lastRunAt !== null ? date('Y-m-d H:i:s', $lastRunAt) : null,
            'next_run_at' => $nextRunAt !== null ? date('Y-m-d H:i:s', $nextRunAt) : null,
        ];
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

    private function stateModel(): AppSyncState
    {
        return $this->states ?? new AppSyncState();
    }

    private function log(): Logger
    {
        return $this->logger ?? new Logger();
    }
}
