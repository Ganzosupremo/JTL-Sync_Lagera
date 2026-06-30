<?php

declare(strict_types=1);

use App\Support\Setting;

return [
    'base_url' => Setting::get('PACKIYO_BASE_URL', ''),
    'auth_type' => Setting::get('PACKIYO_AUTH_TYPE', 'bearer'),
    'api_key' => Setting::get('PACKIYO_API_KEY', ''),
    'api_key_header' => Setting::get('PACKIYO_API_KEY_HEADER', 'Authorization'),
    'media_type' => Setting::get('PACKIYO_MEDIA_TYPE', 'application/vnd.api+json'),
    'order_channel_name' => Setting::get('PACKIYO_ORDER_CHANNEL_NAME', 'JTL-Wawi'),
    'customer_id' => Setting::get('PACKIYO_CUSTOMER_ID', ''),
    'require_customer_mapping' => Setting::get('PACKIYO_REQUIRE_CUSTOMER_MAPPING', true),
    'orders_endpoint' => Setting::get('PACKIYO_ORDERS_ENDPOINT', '/orders'),
    'order_endpoint' => Setting::get('PACKIYO_ORDER_ENDPOINT', '/orders/{id}'),
    'find_order_endpoint' => Setting::get('PACKIYO_FIND_ORDER_ENDPOINT', '/orders'),
    'customers_endpoint' => Setting::get('PACKIYO_CUSTOMERS_ENDPOINT', '/customers'),
    'products_endpoint' => Setting::get('PACKIYO_PRODUCTS_ENDPOINT', '/products'),
    'timeout' => (int) Setting::get('PACKIYO_TIMEOUT', 30),
];
