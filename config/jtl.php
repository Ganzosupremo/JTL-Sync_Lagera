<?php

declare(strict_types=1);

use App\Support\Env;

$query = [];
$rawQuery = Env::get('JTL_NEW_ORDERS_QUERY', '');

if (is_string($rawQuery) && $rawQuery !== '') {
    parse_str($rawQuery, $query);
}

$authenticationCandidates = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) Env::get(
        'JTL_AUTHENTICATION_ENDPOINT_CANDIDATES',
        '/authentication,/api/authentication,/api/eazybusiness/authentication,/api/v1/authentication,/api/v2/authentication'
    ))
)));
$apiVersionCandidates = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) Env::get('JTL_API_VERSION_CANDIDATES', '1.0,2.0'))
)));

return [
    'base_url' => Env::get('JTL_BASE_URL', 'https://127.0.0.1:5883'),
    'auth_type' => Env::get('JTL_AUTH_TYPE', 'wawi'),
    'api_key' => Env::get('JTL_API_KEY', ''),
    'api_key_header' => Env::get('JTL_API_KEY_HEADER', 'Authorization'),
    'username' => Env::get('JTL_USERNAME', ''),
    'password' => Env::get('JTL_PASSWORD', ''),
    'orders_endpoint' => Env::get('JTL_ORDERS_ENDPOINT', '/api/eazybusiness/salesOrders'),
    'order_endpoint' => Env::get('JTL_ORDER_ENDPOINT', '/api/eazybusiness/salesOrders/{id}'),
    'order_items_endpoint' => Env::get('JTL_ORDER_ITEMS_ENDPOINT', '/api/eazybusiness/salesOrders/{id}/lineItems'),
    'delivery_notes_endpoint' => Env::get('JTL_DELIVERY_NOTES_ENDPOINT', '/api/eazybusiness/deliveryNotes'),
    'delivery_note_packages_endpoint' => Env::get('JTL_DELIVERY_NOTE_PACKAGES_ENDPOINT', '/api/eazybusiness/deliveryNotes/{id}/packages'),
    'items_endpoint' => Env::get('JTL_ITEMS_ENDPOINT', '/api/eazybusiness/items'),
    'item_endpoint' => Env::get('JTL_ITEM_ENDPOINT', '/api/eazybusiness/items/{id}'),
    'stocks_endpoint' => Env::get('JTL_STOCKS_ENDPOINT', '/api/eazybusiness/stocks'),
    'product_import_category_id' => Env::get('JTL_PRODUCT_IMPORT_CATEGORY_ID', ''),
    'product_import_warehouse_id' => Env::get('JTL_PRODUCT_IMPORT_WAREHOUSE_ID', ''),
    'new_orders_query' => $query,
    'timeout' => (int) Env::get('JTL_TIMEOUT', 30),
    'ssl_verify' => Env::get('JTL_SSL_VERIFY', false),
    'api_version' => Env::get('JTL_API_VERSION', '1.0'),
    'api_version_candidates' => $apiVersionCandidates,
    'authentication_endpoint' => Env::get('JTL_AUTHENTICATION_ENDPOINT', '/authentication'),
    'authentication_endpoint_candidates' => $authenticationCandidates,
    'app_id' => Env::get('JTL_APP_ID', 'lagera-jtlsync'),
    'app_version' => Env::get('JTL_APP_VERSION', '1.0.0'),
    'app_icon' => Env::get('JTL_APP_ICON', 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='),
    'challenge_code' => Env::get('JTL_CHALLENGE_CODE', 'lagera2026'),
    'display_name' => Env::get('JTL_DISPLAY_NAME', 'Lagera JTL Sync'),
    'description' => Env::get('JTL_DESCRIPTION', 'Synchronization between JTL and Packiyo'),
    'provider_name' => Env::get('JTL_PROVIDER_NAME', 'Lagera 3PL Germany GmbH'),
    'provider_website' => Env::get('JTL_PROVIDER_WEBSITE', 'https://3plgermany.com'),
    'mandatory_scopes' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('JTL_MANDATORY_API_SCOPES', 'salesorders.read,salesorders.write,items.read,deliverynotes.read,deliverynotes.write'))))),
    'optional_scopes' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('JTL_OPTIONAL_API_SCOPES', ''))))),
];
