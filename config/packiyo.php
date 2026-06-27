<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'base_url' => Env::get('PACKIYO_BASE_URL', ''),
    'auth_type' => Env::get('PACKIYO_AUTH_TYPE', 'bearer'),
    'api_key' => Env::get('PACKIYO_API_KEY', ''),
    'api_key_header' => Env::get('PACKIYO_API_KEY_HEADER', 'Authorization'),
    'media_type' => Env::get('PACKIYO_MEDIA_TYPE', 'application/vnd.api+json'),
    'order_channel_name' => Env::get('PACKIYO_ORDER_CHANNEL_NAME', 'JTL-Wawi'),
    'customer_id' => Env::get('PACKIYO_CUSTOMER_ID', ''),
    'require_customer_mapping' => Env::get('PACKIYO_REQUIRE_CUSTOMER_MAPPING', true),
    'orders_endpoint' => Env::get('PACKIYO_ORDERS_ENDPOINT', '/orders'),
    'order_endpoint' => Env::get('PACKIYO_ORDER_ENDPOINT', '/orders/{id}'),
    'find_order_endpoint' => Env::get('PACKIYO_FIND_ORDER_ENDPOINT', '/orders'),
    'customers_endpoint' => Env::get('PACKIYO_CUSTOMERS_ENDPOINT', '/customers'),
    'products_endpoint' => Env::get('PACKIYO_PRODUCTS_ENDPOINT', '/products'),
    'timeout' => (int) Env::get('PACKIYO_TIMEOUT', 30),
];
