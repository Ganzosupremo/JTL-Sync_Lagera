<?php

declare(strict_types=1);

use App\Controllers\AutomationController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\FulfillmentController;
use App\Controllers\JtlOrderSourceController;
use App\Controllers\JtlRegistrationController;
use App\Controllers\JtlWorkerController;
use App\Controllers\JtlWorkflowController;
use App\Controllers\McpController;
use App\Controllers\OAuthController;
use App\Controllers\PackiyoCustomerController;
use App\Controllers\PackiyoProductCatalogController;
use App\Controllers\PackiyoCustomerMappingController;
use App\Controllers\OrderDraftController;
use App\Controllers\OrderCorrectionController;
use App\Controllers\ProductNameMappingController;
use App\Controllers\ProductImportController;
use App\Controllers\ProductSkuAliasController;
use App\Controllers\SettingsController;
use App\Controllers\SyncController;
use App\Controllers\UserInvitationController;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Logger;

require dirname(__DIR__) . '/app/bootstrap.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));

if ($scriptDir !== '/' && str_starts_with($requestPath, $scriptDir)) {
    $requestPath = substr($requestPath, strlen($scriptDir)) ?: '/';
}

$path = rtrim($requestPath, '/') ?: '/';
// /mcp and the OAuth endpoints authenticate with their own tokens. /oauth/authorize is not here on
// purpose: approving a connection needs an admin logged into the panel.
$apiPaths = [
    '/mcp',
    '/oauth/register',
    '/oauth/token',
    // Default endpoints older MCP clients try when they find no discovery document.
    '/register',
    '/token',
    '/.well-known/oauth-protected-resource',
    '/.well-known/oauth-protected-resource/mcp',
    '/.well-known/oauth-authorization-server',
    '/.well-known/openid-configuration',
];
$publicPaths = ['/login', '/logout', '/invite', '/automation/run', '/automation/tick', ...$apiPaths];
$loginTarget = $path;
$queryString = $_SERVER['QUERY_STRING'] ?? '';

Database::migrate();

if (is_string($queryString) && $queryString !== '') {
    $loginTarget .= '?' . $queryString;
}

if (!in_array($path, $publicPaths, true)) {
    (new AuthController())->requireLogin($loginTarget);
}

// The cron and MCP endpoints authenticate with a token instead of a browser session.
$csrfExemptPaths = ['/automation/run', '/automation/tick', ...$apiPaths];
$auth = new Auth(); // Also starts the session that holds the CSRF token.

// Operators can sync and fix orders; only admins change settings, users and the JTL registration.
$adminPaths = [
    '/settings',
    '/users/invite',
    '/users/invite/revoke',
    '/jtl/register',
    '/jtl/register/complete',
    '/jtl/register/reset',
    '/order-corrections/execute',
];

if (in_array($path, $adminPaths, true) && !$auth->isAdmin()) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo 'Solo un administrador puede hacer esto. <a href="javascript:history.back()">Volver</a>';
    exit;
}

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && !in_array($path, $csrfExemptPaths, true)
    && !Csrf::valid(Csrf::fromRequest())
) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo 'La sesion expiro o el formulario no es valido. <a href="javascript:history.back()">Vuelve</a>, recarga la pagina e intentalo de nuevo.';
    exit;
}

ob_start(static fn (string $output): string => Csrf::isHtmlResponse(headers_list()) ? Csrf::injectIntoForms($output) : $output);

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
        '/jtl/orders/draft' => (new OrderDraftController())->save(),
        '/jtl/orders/draft/reset' => (new OrderDraftController())->reset(),
        '/order-corrections/start' => (new OrderCorrectionController())->start(),
        '/order-corrections/continue' => (new OrderCorrectionController())->continue(),
        '/order-corrections/assign' => (new OrderCorrectionController())->assign(),
        '/order-corrections/preview' => (new OrderCorrectionController())->preview(),
        '/order-corrections/execute' => (new OrderCorrectionController())->execute(),
        '/order-corrections/export' => (new OrderCorrectionController())->export(),
        '/fulfillment/sync' => (new FulfillmentController())->sync(),
        '/jtl/order-sources/detect' => (new JtlOrderSourceController())->detect(),
        '/jtl/register' => (new JtlRegistrationController())->start(),
        '/jtl/register/complete' => (new JtlRegistrationController())->complete(),
        '/jtl/register/reset' => (new JtlRegistrationController())->reset(),
        '/jtl/workers/discover' => (new JtlWorkerController())->discover(),
        '/jtl/workers/start' => (new JtlWorkerController())->start(),
        '/jtl/workflows/sales-order-events' => (new JtlWorkflowController())->salesOrderEvents(),
        '/packiyo/customers/sync' => (new PackiyoCustomerController())->sync(),
        '/packiyo/customers/activate' => (new PackiyoCustomerController())->activate(),
        '/packiyo/customers/deactivate' => (new PackiyoCustomerController())->deactivate(),
        '/packiyo/product-catalog/refresh' => (new PackiyoProductCatalogController())->refresh(),
        '/packiyo/customer-mappings' => (new PackiyoCustomerMappingController())->store(),
        '/packiyo/customer-mappings/delete' => (new PackiyoCustomerMappingController())->delete(),
        '/packiyo/sku-aliases' => (new ProductSkuAliasController())->store(),
        '/packiyo/sku-aliases/generate' => (new ProductSkuAliasController())->generate(),
        '/packiyo/sku-aliases/generate-bulk' => (new ProductSkuAliasController())->generateBulk(),
        '/packiyo/sku-aliases/delete' => (new ProductSkuAliasController())->delete(),
        '/packiyo/product-name-mappings' => (new ProductNameMappingController())->store(),
        '/packiyo/product-name-mappings/delete' => (new ProductNameMappingController())->delete(),
        '/products/import' => (new ProductImportController())->import(),
        '/settings' => (new SettingsController())->save(),
        '/users/invite' => (new UserInvitationController())->create(),
        '/users/invite/revoke' => (new UserInvitationController())->revoke(),
        '/health' => (new SyncController())->health(),
        '/mcp' => (new McpController())->handle(),
        '/oauth/register', '/register' => (new OAuthController())->register(),
        '/oauth/token', '/token' => (new OAuthController())->token(),
        '/oauth/authorize', '/authorize' => (new OAuthController())->authorize(),
        '/.well-known/oauth-protected-resource',
        '/.well-known/oauth-protected-resource/mcp' => (new OAuthController())->protectedResource(),
        '/.well-known/oauth-authorization-server',
        '/.well-known/openid-configuration' => (new OAuthController())->authorizationServer(),
        default => notFound(),
    };
} catch (Throwable $exception) {
    // The details (paths, SQL, internal URLs) go to the log; the browser only gets a reference.
    $reference = bin2hex(random_bytes(4));

    try {
        (new Logger())->error('app', '[' . $reference . '] ' . $exception::class . ': ' . $exception->getMessage() . ' at ' . $exception->getFile() . ':' . $exception->getLine());
    } catch (Throwable) {
        error_log('[' . $reference . '] ' . $exception->getMessage());
    }

    http_response_code(500);
    echo (bool) Config::get('app.debug', false)
        ? 'Application error: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8')
        : 'Error interno. Busca la referencia ' . $reference . ' en la pestana Logs.';
}

function notFound(): void
{
    http_response_code(404);
    echo 'Not Found';
}
