<?php

declare(strict_types=1);

namespace App\Services;

use App\Clients\PackiyoClient;
use App\Models\AppSyncState;
use App\Models\PackiyoCustomer;
use App\Support\Logger;

final class PackiyoCustomerSyncService
{
    private const SYNC_KEY = 'packiyo_customers';

    public function __construct(
        private readonly ?PackiyoClient $packiyo = null,
        private readonly ?PackiyoCustomer $customers = null,
        private readonly ?AppSyncState $states = null,
        private readonly ?Logger $logger = null
    ) {
    }

    /** @return array{fetched: int, saved: int, last_synced_at: string|null} */
    public function sync(): array
    {
        $lastSyncedAt = $this->stateModel()->lastSyncedAt(self::SYNC_KEY);
        $resources = $this->packiyoClient()->listCustomers($lastSyncedAt);
        $saved = 0;
        $maxUpdatedAt = $lastSyncedAt;

        foreach ($resources as $resource) {
            $remoteUpdatedAt = $this->customerModel()->upsertFromApi($resource);

            if ($remoteUpdatedAt === null) {
                continue;
            }

            $saved++;

            if ($maxUpdatedAt === null || strtotime($remoteUpdatedAt) > strtotime($maxUpdatedAt)) {
                $maxUpdatedAt = $remoteUpdatedAt;
            }
        }

        if ($maxUpdatedAt === null) {
            $maxUpdatedAt = date('Y-m-d H:i:s');
        }

        $message = sprintf('Packiyo customer sync finished. fetched=%d saved=%d', count($resources), $saved);
        $this->stateModel()->markSuccess(self::SYNC_KEY, $maxUpdatedAt, $message);
        $this->log()->info('packiyo_customers', $message);

        return [
            'fetched' => count($resources),
            'saved' => $saved,
            'last_synced_at' => $maxUpdatedAt,
        ];
    }

    private function packiyoClient(): PackiyoClient
    {
        return $this->packiyo ?? new PackiyoClient();
    }

    private function customerModel(): PackiyoCustomer
    {
        return $this->customers ?? new PackiyoCustomer();
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
