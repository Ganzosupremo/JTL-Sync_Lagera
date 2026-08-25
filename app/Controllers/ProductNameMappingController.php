<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AutomationOrderSkip;
use App\Models\ProductNameMapping;
use App\Services\OrderPreparationService;
use App\Support\Database;
use Throwable;

final class ProductNameMappingController
{
    public function store(): void
    {
        if (!$this->isPost()) { $this->methodNotAllowed(); return; }
        Database::migrate();
        $customerId = trim((string) ($_POST['packiyo_customer_id'] ?? ''));
        try {
            $source = trim((string) ($_POST['source_name'] ?? ''));
            $sku = trim((string) ($_POST['packiyo_sku'] ?? ''));
            if ($customerId === '' || $source === '' || $sku === '') {
                throw new \RuntimeException('Cliente, nombre BOL y SKU Packiyo son requeridos.');
            }
            $product = null;
            foreach ((new OrderPreparationService())->catalog($customerId) as $candidate) {
                if ($candidate['sku'] === $sku) { $product = $candidate; break; }
            }
            (new ProductNameMapping())->upsert([
                'packiyo_customer_id' => $customerId,
                'source_name' => $source,
                'normalized_source_name' => OrderPreparationService::normalizeName($source),
                'packiyo_product_id' => $product['id'] ?? '',
                'packiyo_sku' => $sku,
                'packiyo_product_name' => $product['name'] ?? '',
            ]);
            (new AutomationOrderSkip())->deleteByReason('requires_review');
            $message = 'Equivalencia de nombre guardada.';
        } catch (Throwable $exception) { $message = $exception->getMessage(); }
        $this->redirect($customerId, $message);
    }

    public function delete(): void
    {
        if (!$this->isPost()) { $this->methodNotAllowed(); return; }
        Database::migrate();
        $customerId = trim((string) ($_POST['packiyo_customer_id'] ?? ''));
        try { (new ProductNameMapping())->delete((int) ($_POST['id'] ?? 0)); $message = 'Equivalencia eliminada.'; }
        catch (Throwable $exception) { $message = $exception->getMessage(); }
        $this->redirect($customerId, $message);
    }

    private function redirect(string $customerId, string $message): void
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
        header('Location: ' . $base . '/?' . http_build_query(['tab' => 'customer-mappings', 'name_customer_id' => $customerId, 'notice' => $message]), true, 303);
    }
    private function isPost(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
    private function methodNotAllowed(): void { http_response_code(405); echo 'Method Not Allowed'; }
}
