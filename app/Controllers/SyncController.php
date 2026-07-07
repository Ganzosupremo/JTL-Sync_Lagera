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

        $returnTab = $this->returnTab($_POST['return_tab'] ?? 'overview');
        $customerFilter = $this->postedString('jtl_customer');
        $mappedCustomerFilter = $this->postedString('jtl_mapped_customer');
        $summary = (new OrderSyncService())->sync($customerFilter, $mappedCustomerFilter);

        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($summary, JSON_THROW_ON_ERROR);
            return;
        }

        $message = sprintf(
            'Sync terminado: %d creados, %d ya existentes vinculados, %d omitidos, %d errores.',
            $summary['created'],
            $summary['linked'] ?? 0,
            $summary['skipped'],
            $summary['failed']
        );

        $params = [
            'tab' => $returnTab,
            'notice' => $message,
        ];

        if ($returnTab === 'jtl-orders') {
            if ($customerFilter !== '') {
                $params['jtl_customer'] = $customerFilter;
            }

            if ($mappedCustomerFilter !== '') {
                $params['jtl_mapped_customer'] = $mappedCustomerFilter;
            }
        }

        header('Location: ' . $this->url('/') . '?' . http_build_query($params), true, 303);
    }

    public function runOne(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        Database::migrate();

        $reference = (string) ($_POST['order_reference'] ?? '');
        $returnTab = $this->returnTab($_POST['return_tab'] ?? 'customer-mappings');
        $force = ($_POST['force_resync'] ?? '') === '1';
        $resendArchived = ($_POST['resend_archived'] ?? '') === '1';
        $summary = (new OrderSyncService())->syncOne($reference, $force, $resendArchived);

        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($summary, JSON_THROW_ON_ERROR);
            return;
        }

        header(
            'Location: ' . $this->url('/') . '?tab=' . rawurlencode($returnTab) . '&notice=' . rawurlencode($summary['message']),
            true,
            303
        );
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

    private function returnTab(mixed $tab): string
    {
        $tab = is_string($tab) ? $tab : 'overview';
        $allowed = ['overview', 'jtl-orders', 'customer-mappings'];

        return in_array($tab, $allowed, true) ? $tab : 'overview';
    }

    private function postedString(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function url(string $path): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

        return $base . '/' . ltrim($path, '/');
    }
}
