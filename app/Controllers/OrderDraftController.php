<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AutomationOrderSkip;
use App\Models\OrderDraft;
use App\Models\ProductNameMapping;
use App\Services\OrderDetailService;
use App\Services\OrderPreparationService;
use App\Services\OrderSyncService;
use App\Support\Database;
use Throwable;

final class OrderDraftController
{
    public function save(): void
    {
        if (!$this->isPost()) {
            $this->methodNotAllowed();
            return;
        }
        Database::migrate();
        $reference = trim((string) ($_POST['order_reference'] ?? ''));
        try {
            $detail = (new OrderDetailService())->load($reference, true);
            $data = [
                'shipping_address' => $this->address($_POST['shipping_address'] ?? []),
                'billing_address' => $this->address($_POST['billing_address'] ?? []),
                'items' => $this->items($_POST['items'] ?? []),
                'shipping' => $detail['data']['shipping'] ?? null,
            ];
            $errors = (new OrderPreparationService())->validationErrors($data['items']);
            (new OrderDraft())->save(
                (string) $detail['id'],
                (string) $detail['number'],
                (string) $detail['customer_id'],
                (array) $detail['source'],
                $data
            );
            $this->rememberMappings($data['items'], (string) $detail['customer_id'], (array) $detail['catalog']);
            (new AutomationOrderSkip())->deleteByOrderId((string) $detail['id']);
            $message = $errors === [] ? 'Borrador guardado y listo para enviar.' : 'Borrador guardado. Aun requiere revision: ' . implode(' ', $errors);
            if ($errors === [] && ($_POST['send_after_save'] ?? '') === '1') {
                $summary = (new OrderSyncService())->syncOne((string) $detail['id']);
                $message = (string) $summary['message'];
            }
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
        }
        $this->redirect($reference, $message, ($_POST['save_and_close'] ?? '') === '1');
    }

    public function reset(): void
    {
        if (!$this->isPost()) {
            $this->methodNotAllowed();
            return;
        }
        Database::migrate();
        $reference = trim((string) ($_POST['order_reference'] ?? ''));
        try {
            $detail = (new OrderDetailService())->load($reference);
            (new OrderDraft())->delete((string) $detail['id']);
            (new AutomationOrderSkip())->deleteByOrderId((string) $detail['id']);
            $message = 'Cambios locales descartados.';
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
        }
        $this->redirect($reference, $message);
    }

    /** @param mixed $value @return array<string, string> */
    private function address(mixed $value): array
    {
        $input = is_array($value) ? $value : [];
        $result = [];
        foreach (['name', 'first_name', 'last_name', 'company', 'address1', 'address2', 'postal_code', 'state', 'city', 'country', 'email', 'phone'] as $key) {
            $result[$key] = is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        }
        return $result;
    }

    /** @param mixed $value @return array<int, array<string, mixed>> */
    private function items(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $index => $item) {
            if (!is_array($item) || !empty($item['remove'])) {
                continue;
            }
            $rows[] = [
                'external_id' => trim((string) ($item['external_id'] ?? ('manual-' . ((int) $index + 1)))),
                'source_name' => trim((string) ($item['source_name'] ?? '')),
                'name' => trim((string) ($item['name'] ?? '')),
                'sku' => trim((string) ($item['sku'] ?? '')),
                'quantity' => is_numeric($item['quantity'] ?? null) ? (float) $item['quantity'] : 0,
                'price' => is_numeric($item['price'] ?? null) ? (float) $item['price'] : -1,
                'packiyo_product_id' => trim((string) ($item['packiyo_product_id'] ?? '')),
                'resolution' => trim((string) ($item['resolution'] ?? 'manual')) ?: 'manual',
                'score' => null,
                'suggestions' => [],
                'remember' => !empty($item['remember']),
            ];
        }
        return $rows;
    }

    /** @param array<int, array<string, mixed>> $items @param array<int, array<string, string>> $catalog */
    private function rememberMappings(array $items, string $customerId, array $catalog): void
    {
        if ($customerId === '') {
            return;
        }
        $preparation = new OrderPreparationService();
        foreach ($items as $item) {
            if (empty($item['remember'])
                || trim((string) ($item['source_name'] ?? '')) === ''
                || $preparation->isProvisionalSku((string) ($item['sku'] ?? ''))) {
                continue;
            }
            $product = null;
            foreach ($catalog as $candidate) {
                if ((string) ($candidate['sku'] ?? '') === (string) $item['sku']) {
                    $product = $candidate;
                    break;
                }
            }
            (new ProductNameMapping())->upsert([
                'packiyo_customer_id' => $customerId,
                'source_name' => $item['source_name'],
                'normalized_source_name' => OrderPreparationService::normalizeName((string) $item['source_name']),
                'packiyo_product_id' => $product['id'] ?? $item['packiyo_product_id'],
                'packiyo_sku' => $item['sku'],
                'packiyo_product_name' => $product['name'] ?? $item['name'],
            ]);
        }
    }

    private function redirect(string $reference, string $message, bool $close = false): void
    {
        $query = ['tab' => 'jtl-orders', 'notice' => $message];
        if (!$close) {
            $query['order_reference'] = $reference;
        }
        header('Location: ' . $this->url('/') . '?' . http_build_query($query), true, 303);
    }

    private function isPost(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
    private function methodNotAllowed(): void { http_response_code(405); echo 'Method Not Allowed'; }
    private function url(string $path): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        return ($scriptDir === '/' ? '' : rtrim($scriptDir, '/')) . '/' . ltrim($path, '/');
    }
}
