<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\OrderPreparationService;
use App\Support\Database;
use Throwable;

final class PackiyoProductCatalogController extends Controller
{
    public function refresh(): void
    {
        if (!$this->isPost()) {
            $this->methodNotAllowed();
            return;
        }

        Database::migrate();
        $customerId = trim((string) ($_POST['packiyo_customer_id'] ?? ''));
        $orderReference = trim((string) ($_POST['order_reference'] ?? ''));
        try {
            if ($customerId === '') {
                throw new \RuntimeException('Cliente Packiyo requerido.');
            }
            $products = (new OrderPreparationService())->catalog($customerId, true, true);
            $message = 'Catalogo Packiyo actualizado: ' . count($products) . ' productos.';
        } catch (Throwable $exception) {
            $message = 'No se pudo actualizar el catalogo: ' . $exception->getMessage();
        }

        $params = $orderReference !== ''
            ? ['tab' => 'jtl-orders', 'order_reference' => $orderReference, 'notice' => $message]
            : ['tab' => 'customer-mappings', 'name_customer_id' => $customerId, 'notice' => $message];
        header('Location: ' . $this->url('/') . '?' . http_build_query($params), true, 303);
    }
}
