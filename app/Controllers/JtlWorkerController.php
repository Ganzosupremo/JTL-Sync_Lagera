<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Clients\JtlClient;
use App\Support\Database;
use App\Support\Logger;

final class JtlWorkerController
{
    public function start(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        Database::migrate();

        $salesChannelId = $this->postedString('sales_channel_id');
        $salesChannelName = $this->postedString('sales_channel_name');

        try {
            $response = (new JtlClient())->startWorkerSync($salesChannelId, $salesChannelName);
            $message = 'JTL Worker abgleich iniciado.';

            if ($salesChannelId !== '') {
                $message .= ' Sales channel #' . $salesChannelId . '.';
            }

            (new Logger())->info('jtl_worker', $message . ' Response: ' . $this->shortJson($response));
        } catch (\Throwable $exception) {
            $message = 'No se pudo iniciar el JTL Worker abgleich: ' . $exception->getMessage();
            (new Logger())->error('jtl_worker', $message);
        }

        header(
            'Location: ' . $this->url('/') . '?tab=jtl-orders&notice=' . rawurlencode($message),
            true,
            303
        );
    }

    private function postedString(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<string, mixed> $data */
    private function shortJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($json) || $json === '') {
            return '{}';
        }

        return strlen($json) > 500 ? substr($json, 0, 497) . '...' : $json;
    }

    private function url(string $path): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

        return $base . '/' . ltrim($path, '/');
    }
}
