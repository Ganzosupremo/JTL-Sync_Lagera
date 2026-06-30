<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ProductSkuAlias;
use App\Services\ProductSkuAliasService;
use App\Support\Database;
use RuntimeException;
use Throwable;

final class ProductSkuAliasController
{
    public function store(): void
    {
        if (!$this->isPost()) {
            $this->methodNotAllowed();
            return;
        }

        Database::migrate();
        $customerId = trim((string) ($_POST['packiyo_customer_id'] ?? ''));

        try {
            $originalSku = trim((string) ($_POST['original_sku'] ?? ''));
            $aliasSku = trim((string) ($_POST['alias_sku'] ?? ''));

            if ($customerId === '' || $originalSku === '' || $aliasSku === '') {
                throw new RuntimeException('Cliente Packiyo, SKU original y alias son requeridos.');
            }

            if (strcasecmp($originalSku, $aliasSku) === 0) {
                throw new RuntimeException('El alias debe ser diferente al SKU original.');
            }

            (new ProductSkuAlias())->upsert([
                'packiyo_customer_id' => $customerId,
                'packiyo_product_id' => $_POST['packiyo_product_id'] ?? '',
                'original_sku' => $originalSku,
                'alias_sku' => $aliasSku,
                'product_name' => $_POST['product_name'] ?? '',
                'active' => true,
            ]);

            $this->redirect($customerId, 'Mapeo de SKU guardado.');
        } catch (Throwable $exception) {
            $this->redirect($customerId, $exception->getMessage());
        }
    }

    public function generate(): void
    {
        if (!$this->isPost()) {
            $this->methodNotAllowed();
            return;
        }

        Database::migrate();
        $customerId = trim((string) ($_POST['packiyo_customer_id'] ?? ''));

        try {
            $created = (new ProductSkuAliasService())->generateCommonAliases(
                $customerId,
                trim((string) ($_POST['packiyo_product_id'] ?? '')),
                trim((string) ($_POST['original_sku'] ?? '')),
                trim((string) ($_POST['product_name'] ?? ''))
            );

            $this->redirect($customerId, 'Aliases comunes guardados: ' . $created . '.');
        } catch (Throwable $exception) {
            $this->redirect($customerId, $exception->getMessage());
        }
    }

    public function generateBulk(): void
    {
        if (!$this->isPost()) {
            $this->methodNotAllowed();
            return;
        }

        Database::migrate();
        $customerId = trim((string) ($_POST['packiyo_customer_id'] ?? ''));

        try {
            $summary = (new ProductSkuAliasService())->generateCommonAliasesForCustomer($customerId);
            $message = sprintf(
                'Aliases comunes procesados: %d aliases en %d productos. Omitidos: %d.',
                $summary['aliases'],
                $summary['products'],
                $summary['skipped']
            );

            $this->redirect($customerId, $message);
        } catch (Throwable $exception) {
            $this->redirect($customerId, $exception->getMessage());
        }
    }

    public function delete(): void
    {
        if (!$this->isPost()) {
            $this->methodNotAllowed();
            return;
        }

        Database::migrate();
        $customerId = trim((string) ($_POST['packiyo_customer_id'] ?? ''));

        try {
            (new ProductSkuAlias())->delete((int) ($_POST['id'] ?? 0));
            $this->redirect($customerId, 'Mapeo de SKU eliminado.');
        } catch (Throwable $exception) {
            $this->redirect($customerId, $exception->getMessage());
        }
    }

    private function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    private function methodNotAllowed(): void
    {
        http_response_code(405);
        echo 'Method Not Allowed';
    }

    private function redirect(string $customerId, string $message): void
    {
        header(
            'Location: ' . $this->url('/')
            . '?tab=customer-mappings&sku_customer_id=' . rawurlencode($customerId)
            . '&notice=' . rawurlencode($message),
            true,
            303
        );
    }

    private function url(string $path): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

        return $base . '/' . ltrim($path, '/');
    }
}
