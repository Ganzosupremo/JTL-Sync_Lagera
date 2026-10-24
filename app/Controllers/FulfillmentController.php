<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\FulfillmentSyncService;
use App\Support\Database;

final class FulfillmentController extends Controller
{
    public function sync(): void
    {
        if (!$this->isPost()) {
            $this->methodNotAllowed();
            return;
        }

        Database::migrate();

        // FulfillmentSyncService caps itself at FULFILLMENT_SYNC_TIME_BUDGET_SECONDS (20s default) so it never
        // runs long enough to hit a browser/proxy timeout, but that budget check only helps if PHP itself is
        // allowed to run that long: bump the script's own execution limit here as a safety net in case the
        // host's default (php.ini max_execution_time) is lower than the sync budget.
        if (function_exists('set_time_limit')) {
            @set_time_limit(45);
        }

        $packiyoCustomerId = $this->postedString('packiyo_customer_id');
        $summary = (new FulfillmentSyncService())->sync(packiyoCustomerId: $packiyoCustomerId);

        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($summary, JSON_THROW_ON_ERROR);
            return;
        }

        $params = [
            'tab' => 'fulfillment',
            'notice' => $summary['message'],
        ];

        if ($packiyoCustomerId !== '') {
            $params['fulfillment_customer_id'] = $packiyoCustomerId;
        }

        header(
            'Location: ' . $this->url('/') . '?' . http_build_query($params),
            true,
            303
        );
    }
}
