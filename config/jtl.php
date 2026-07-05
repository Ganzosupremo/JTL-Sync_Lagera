<?php

declare(strict_types=1);

use App\Support\Setting;
use App\Support\JtlScopeList;

$query = [];
$rawQuery = Setting::get('JTL_NEW_ORDERS_QUERY', '');

if (is_string($rawQuery) && $rawQuery !== '') {
    parse_str($rawQuery, $query);
}

$authenticationCandidates = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) Setting::get(
        'JTL_AUTHENTICATION_ENDPOINT_CANDIDATES',
        '/authentication,/api/authentication,/api/eazybusiness/authentication,/api/v1/authentication,/api/v2/authentication'
    ))
)));
$apiVersionCandidates = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) Setting::get('JTL_API_VERSION_CANDIDATES', '1.0,2.0'))
)));
$mandatoryScopes = JtlScopeList::mandatoryFromConfigured((string) Setting::get(
    'JTL_MANDATORY_API_SCOPES',
    JtlScopeList::defaultMandatoryString()
));

return [
    'base_url' => Setting::get('JTL_BASE_URL', 'https://127.0.0.1:5883'),
    'auth_type' => Setting::get('JTL_AUTH_TYPE', 'wawi'),
    'api_key' => Setting::get('JTL_API_KEY', ''),
    'api_key_header' => Setting::get('JTL_API_KEY_HEADER', 'Authorization'),
    'username' => Setting::get('JTL_USERNAME', ''),
    'password' => Setting::get('JTL_PASSWORD', ''),
    'orders_endpoint' => Setting::get('JTL_ORDERS_ENDPOINT', '/api/eazybusiness/salesOrders'),
    'order_endpoint' => Setting::get('JTL_ORDER_ENDPOINT', '/api/eazybusiness/salesOrders/{id}'),
    'order_items_endpoint' => Setting::get('JTL_ORDER_ITEMS_ENDPOINT', '/api/eazybusiness/salesOrders/{id}/lineItems'),
    'delivery_notes_endpoint' => Setting::get('JTL_DELIVERY_NOTES_ENDPOINT', '/api/eazybusiness/deliveryNotes'),
    'delivery_note_packages_endpoint' => Setting::get('JTL_DELIVERY_NOTE_PACKAGES_ENDPOINT', '/api/eazybusiness/deliveryNotes/{id}/packages'),
    'sales_channels_endpoint' => Setting::get('JTL_SALES_CHANNELS_ENDPOINT', '/api/eazybusiness/salesChannels'),
    'workers_endpoint' => Setting::get('JTL_WORKERS_ENDPOINT', '/api/eazybusiness/v1/workers'),
    'worker_endpoint' => Setting::get('JTL_WORKER_ENDPOINT', '/api/eazybusiness/v1/workers/{syncId}'),
    'worker_status_endpoint' => Setting::get('JTL_WORKER_STATUS_ENDPOINT', '/api/eazybusiness/v1/workers/status'),
    'worker_discovery_enabled' => Setting::get('JTL_WORKER_DISCOVERY_ENABLED', false),
    'worker_sync_method' => Setting::get('JTL_WORKER_SYNC_METHOD', 'PUT'),
    'worker_sync_body_template' => Setting::get('JTL_WORKER_SYNC_BODY_TEMPLATE', '{"Action":0}'),
    'items_endpoint' => Setting::get('JTL_ITEMS_ENDPOINT', '/api/eazybusiness/items'),
    'item_endpoint' => Setting::get('JTL_ITEM_ENDPOINT', '/api/eazybusiness/items/{id}'),
    'stocks_endpoint' => Setting::get('JTL_STOCKS_ENDPOINT', '/api/eazybusiness/stocks'),
    'product_import_category_id' => Setting::get('JTL_PRODUCT_IMPORT_CATEGORY_ID', ''),
    'product_import_warehouse_id' => Setting::get('JTL_PRODUCT_IMPORT_WAREHOUSE_ID', ''),
    'new_orders_query' => $query,
    'timeout' => (int) Setting::get('JTL_TIMEOUT', 30),
    'ssl_verify' => Setting::get('JTL_SSL_VERIFY', false),
    'api_version' => Setting::get('JTL_API_VERSION', '1.0'),
    'api_version_candidates' => $apiVersionCandidates,
    'authentication_endpoint' => Setting::get('JTL_AUTHENTICATION_ENDPOINT', '/authentication'),
    'authentication_endpoint_candidates' => $authenticationCandidates,
    'app_id' => Setting::get('JTL_APP_ID', 'lagera-jtlsync'),
    'app_version' => Setting::get('JTL_APP_VERSION', '1.0.0'),
    'app_icon' => Setting::get('JTL_APP_ICON', 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='),
    'challenge_code' => Setting::get('JTL_CHALLENGE_CODE', 'lagera2026'),
    'display_name' => Setting::get('JTL_DISPLAY_NAME', 'Lagera JTL Sync'),
    'description' => Setting::get('JTL_DESCRIPTION', 'Synchronization between JTL and Packiyo'),
    'provider_name' => Setting::get('JTL_PROVIDER_NAME', 'Lagera 3PL Germany GmbH'),
    'provider_website' => Setting::get('JTL_PROVIDER_WEBSITE', 'https://3plgermany.com'),
    'cloudflare_access_client_id' => Setting::get('JTL_CF_ACCESS_CLIENT_ID', ''),
    'cloudflare_access_client_secret' => Setting::get('JTL_CF_ACCESS_CLIENT_SECRET', ''),
    'mandatory_scopes' => $mandatoryScopes,
    'optional_scopes' => array_values(array_filter(array_map('trim', explode(',', (string) Setting::get('JTL_OPTIONAL_API_SCOPES', ''))))),
];
