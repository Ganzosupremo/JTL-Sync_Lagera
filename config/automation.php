<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'token' => Env::get('AUTOMATION_TOKEN', ''),
    'sync_customers' => Env::get('AUTOMATION_SYNC_CUSTOMERS', false),
    'fulfillment_limit' => (int) Env::get('AUTOMATION_FULFILLMENT_LIMIT', 200),
];
