<?php

declare(strict_types=1);

use App\Controllers\AutomationController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\FulfillmentController;
use App\Controllers\JtlOrderSourceController;
use App\Controllers\JtlRegistrationController;
use App\Controllers\JtlWorkerController;
use App\Controllers\PackiyoCustomerController;
use App\Controllers\PackiyoCustomerMappingController;
use App\Controllers\ProductImportController;
use App\Controllers\ProductSkuAliasController;
use App\Controllers\SettingsController;
use App\Controllers\SyncController;
use App\Controllers\UserInvitationController;
use App\Support\Database;

require dirname(__DIR__) . '/app/bootstrap.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));

if ($scriptDir !== '/' && str_starts_with($requestPath, $scriptDir)) {
    $requestPath = substr($requestPath, strlen($scriptDir)) ?: '/';
}

$path = rtrim($requestPath, '/') ?: '/';
$publicPaths = ['/login', '/logout', '/invite', '/automation/run', '/automation/tick'];
$loginTarget = $path;
$queryString = $_SERVER['QUERY_STRING'] ?? '';

Database::migrate();

if (is_string($queryString) && $queryString !== '') {
    $loginTarget .= '?' . $queryString;
}

if (!in_array($path, $publicPaths, true)) {
    (new AuthController())->requireLogin($loginTarget);
}

try {
    match ($path) {
        '/' => (new DashboardController())->index(),
        '/login' => (new AuthController())->login(),
        '/logout' => (new AuthController())->logout(),
        '/invite' => (new UserInvitationController())->accept(),
        '/automation/run' => (new AutomationController())->run(),
        '/automation/tick' => (new AutomationController())->tick(),
        '/automation/manual' => (new AutomationController())->manual(),
        '/sync' => (new SyncController())->run(),
        '/sync/order' => (new SyncController())->runOne(),
        '/fulfillment/sync' => (new FulfillmentController())->sync(),
        '/jtl/order-sources/detect' => (new JtlOrderSourceController())->detect(),
        '/jtl/register' => (new JtlRegistrationController())->start(),
        '/jtl/register/complete' => (new JtlRegistrationController())->complete(),
        '/jtl/register/reset' => (new JtlRegistrationController())->reset(),
        '/jtl/workers/discover' => (new JtlWorkerController())->discover(),
        '/jtl/workers/start' => (new JtlWorkerController())->start(),
        '/packiyo/customers/sync' => (new PackiyoCustomerController())->sync(),
        '/packiyo/customers/activate' => (new PackiyoCustomerController())->activate(),
        '/packiyo/customers/deactivate' => (new PackiyoCustomerController())->deactivate(),
        '/packiyo/customer-mappings' => (new PackiyoCustomerMappingController())->store(),
        '/packiyo/customer-mappings/delete' => (new PackiyoCustomerMappingController())->delete(),
        '/packiyo/sku-aliases' => (new ProductSkuAliasController())->store(),
        '/packiyo/sku-aliases/generate' => (new ProductSkuAliasController())->generate(),
        '/packiyo/sku-aliases/generate-bulk' => (new ProductSkuAliasController())->generateBulk(),
        '/packiyo/sku-aliases/delete' => (new ProductSkuAliasController())->delete(),
        '/products/import' => (new ProductImportController())->import(),
        '/settings' => (new SettingsController())->save(),
        '/users/invite' => (new UserInvitationController())->create(),
        '/users/invite/revoke' => (new UserInvitationController())->revoke(),
        '/health' => (new SyncController())->health(),
        default => notFound(),
    };
} catch (Throwable $exception) {
    http_response_code(500);
    echo 'Application error: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
}

function notFound(): void
{
    http_response_code(404);
    echo 'Not Found';
}
