<?php

declare(strict_types=1);

use App\Support\Env;

$query = [];
$rawQuery = Env::get('JTL_NEW_ORDERS_QUERY', '');

if (is_string($rawQuery) && $rawQuery !== '') {
    parse_str($rawQuery, $query);
}

return [
    'base_url' => Env::get('JTL_BASE_URL', ''),
    'auth_type' => Env::get('JTL_AUTH_TYPE', 'bearer'),
    'api_key' => Env::get('JTL_API_KEY', ''),
    'api_key_header' => Env::get('JTL_API_KEY_HEADER', 'Authorization'),
    'username' => Env::get('JTL_USERNAME', ''),
    'password' => Env::get('JTL_PASSWORD', ''),
    'orders_endpoint' => Env::get('JTL_ORDERS_ENDPOINT', '/orders'),
    'order_endpoint' => Env::get('JTL_ORDER_ENDPOINT', '/orders/{id}'),
    'order_items_endpoint' => Env::get('JTL_ORDER_ITEMS_ENDPOINT', '/orders/{id}/items'),
    'new_orders_query' => $query,
    'timeout' => (int) Env::get('JTL_TIMEOUT', 30),
];
