<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'base_url' => Env::get('PACKIYO_BASE_URL', ''),
    'auth_type' => Env::get('PACKIYO_AUTH_TYPE', 'bearer'),
    'api_key' => Env::get('PACKIYO_API_KEY', ''),
    'api_key_header' => Env::get('PACKIYO_API_KEY_HEADER', 'Authorization'),
    'orders_endpoint' => Env::get('PACKIYO_ORDERS_ENDPOINT', '/orders'),
    'order_endpoint' => Env::get('PACKIYO_ORDER_ENDPOINT', '/orders/{id}'),
    'find_order_endpoint' => Env::get('PACKIYO_FIND_ORDER_ENDPOINT', '/orders'),
    'timeout' => (int) Env::get('PACKIYO_TIMEOUT', 30),
];
