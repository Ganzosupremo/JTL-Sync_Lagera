<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Clients\JtlClient;
use App\Clients\PackiyoClient;
use App\Services\OrderSyncService;
use App\Support\Database;

final class SyncController
{
    public function run(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        Database::migrate();

        $summary = (new OrderSyncService())->sync();

        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($summary, JSON_THROW_ON_ERROR);
            return;
        }

        $message = sprintf(
            'Sync terminado: %d creados, %d omitidos, %d errores.',
            $summary['created'],
            $summary['skipped'],
            $summary['failed']
        );

        header('Location: ' . $this->url('/') . '?sync=' . rawurlencode($message), true, 303);
    }

    public function health(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'jtl' => (new JtlClient())->status(),
            'packiyo' => (new PackiyoClient())->status(),
        ], JSON_THROW_ON_ERROR);
    }

    private function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        return is_string($accept) && str_contains($accept, 'application/json');
    }

    private function url(string $path): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

        return $base . '/' . ltrim($path, '/');
    }
}
