<?php

declare(strict_types=1);

use App\Controllers\DashboardController;
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
