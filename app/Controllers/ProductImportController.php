<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ProductImportService;
use Throwable;

final class ProductImportController
{
    public function import(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        $customerId = is_scalar($_POST['customer_id'] ?? null) ? trim((string) $_POST['customer_id']) : '';
        $categoryId = is_scalar($_POST['category_id'] ?? null) ? trim((string) $_POST['category_id']) : '';
        $warehouseId = is_scalar($_POST['warehouse_id'] ?? null) ? trim((string) $_POST['warehouse_id']) : '';
        $productIds = $_POST['product_ids'] ?? [];
        $productIds = is_array($productIds) ? array_values(array_filter(array_map('strval', $productIds))) : [];

        try {
            $summary = (new ProductImportService())->importSelected($customerId, $productIds, $categoryId, $warehouseId);
            $message = $summary['message'];
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
        }

        $this->redirect(
            '/?tab=products&customer_id=' . rawurlencode($customerId)
            . '&category_id=' . rawurlencode($categoryId)
            . '&warehouse_id=' . rawurlencode($warehouseId)
            . '&notice=' . rawurlencode($message)
        );
    }

    private function redirect(string $path): void
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

        header('Location: ' . $base . '/' . ltrim($path, '/'), true, 303);
        exit;
    }
}
