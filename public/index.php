<?php

declare(strict_types=1);

use App\Controllers\DashboardController;
use App\Controllers\JtlOrderSourceController;
use App\Controllers\JtlRegistrationController;
use App\Controllers\PackiyoCustomerController;
use App\Controllers\PackiyoCustomerMappingController;
use App\Controllers\SyncController;

require dirname(__DIR__) . '/app/bootstrap.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));

if ($scriptDir !== '/' && str_starts_with($requestPath, $scriptDir)) {
    $requestPath = substr($requestPath, strlen($scriptDir)) ?: '/';
}

$path = rtrim($requestPath, '/') ?: '/';

try {
    match ($path) {
        '/' => (new DashboardController())->index(),
        '/sync' => (new SyncController())->run(),
        '/sync/order' => (new SyncController())->runOne(),
        '/jtl/order-sources/detect' => (new JtlOrderSourceController())->detect(),
        '/jtl/register' => (new JtlRegistrationController())->start(),
        '/jtl/register/complete' => (new JtlRegistrationController())->complete(),
        '/packiyo/customers/sync' => (new PackiyoCustomerController())->sync(),
        '/packiyo/customers/activate' => (new PackiyoCustomerController())->activate(),
        '/packiyo/customers/deactivate' => (new PackiyoCustomerController())->deactivate(),
        '/packiyo/customer-mappings' => (new PackiyoCustomerMappingController())->store(),
        '/packiyo/customer-mappings/delete' => (new PackiyoCustomerMappingController())->delete(),
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
