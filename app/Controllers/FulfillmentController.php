<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\FulfillmentSyncService;
use App\Support\Database;

final class FulfillmentController
{
    public function sync(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        Database::migrate();
        $summary = (new FulfillmentSyncService())->sync();

        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($summary, JSON_THROW_ON_ERROR);
            return;
        }

        header(
            'Location: ' . $this->url('/') . '?tab=fulfillment&notice=' . rawurlencode($summary['message']),
            true,
            303
        );
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
