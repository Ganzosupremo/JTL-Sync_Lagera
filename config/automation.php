<?php

declare(strict_types=1);

use App\Support\Setting;

return [
    'token' => Setting::get('AUTOMATION_TOKEN', ''),
    'sync_customers' => Setting::get('AUTOMATION_SYNC_CUSTOMERS', false),
    'fulfillment_limit' => (int) Setting::get('AUTOMATION_FULFILLMENT_LIMIT', 200),
];
