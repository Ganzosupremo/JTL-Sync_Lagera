<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\JtlOrderSourceDetectionService;
use App\Support\Database;
use Throwable;

final class JtlOrderSourceController
{
    public function detect(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        Database::migrate();

        try {
            $summary = (new JtlOrderSourceDetectionService())->detect();
            $this->redirect(
                sprintf(
                    'Tiendas JTL detectadas: %d ordenes leidas, %d valores encontrados.',
                    $summary['orders'],
                    $summary['sources']
                )
            );
        } catch (Throwable $exception) {
            $this->redirect($exception->getMessage());
        }
    }

    private function redirect(string $message): void
    {
        header('Location: ' . $this->url('/') . '?tab=customer-mappings&notice=' . rawurlencode($message), true, 303);
    }

    private function url(string $path): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

        return $base . '/' . ltrim($path, '/');
    }
}
