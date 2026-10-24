<?php

declare(strict_types=1);

namespace App\Views\Dashboard;

use App\Views\View;

final class ProductsView extends View
{
    /**
     * @param array<int, array<string, mixed>> $activeCustomers
     * @param array<int, array<string, mixed>> $products
     */
    public function render(
        array $activeCustomers,
        array $products,
        ?string $error,
        string $selectedCustomerId,
        string $categoryId,
        string $warehouseId
    ): string {
        ob_start();
        ?>
            <section>
                <div class="section-head">
                    <h2>Productos Packiyo -> JTL</h2>
                </div>
                <div class="section-body">
                    <form class="product-filter-form" action="<?= $this->e($this->url('/')) ?>" method="get">
                        <input type="hidden" name="tab" value="products">
                        <select name="customer_id" required>
                            <option value="">Cliente Packiyo</option>
                            <?php foreach ($activeCustomers as $customer): ?>
                                <option value="<?= $this->e($customer['packiyo_customer_id'] ?? '') ?>" <?= $selectedCustomerId === (string) ($customer['packiyo_customer_id'] ?? '') ? 'selected' : '' ?>>
                                    <?= $this->e($this->customerDisplayName($customer) . ' #' . ($customer['packiyo_customer_id'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input name="category_id" value="<?= $this->e($categoryId) ?>" placeholder="JTL category ID" required>
                        <input name="warehouse_id" value="<?= $this->e($warehouseId) ?>" placeholder="JTL warehouse ID">
                        <button class="button" type="submit">Cargar productos</button>
                    </form>
                    <p class="muted">El warehouse ID es necesario para importar stock. Si queda vacio, solo se crean o relacionan articulos.</p>

                    <?php if ($error !== null): ?>
                        <div class="empty">No se pudieron leer productos de Packiyo: <?= $this->e($error) ?></div>
                    <?php elseif ($selectedCustomerId === ''): ?>
                        <div class="empty">Selecciona un cliente Packiyo para ver sus productos. Para EsSo/Temu usa el customer ID 46 si sigue activo.</div>
                    <?php elseif ($products === []): ?>
                        <div class="empty">No hay productos para este cliente.</div>
                    <?php else: ?>
                        <form action="<?= $this->e($this->url('/products/import')) ?>" method="post">
                            <input type="hidden" name="customer_id" value="<?= $this->e($selectedCustomerId) ?>">
                            <input type="hidden" name="category_id" value="<?= $this->e($categoryId) ?>">
                            <input type="hidden" name="warehouse_id" value="<?= $this->e($warehouseId) ?>">
                            <div class="actions" style="margin-bottom: 12px;">
                                <button class="button" type="submit">Importar / actualizar seleccionados</button>
                            </div>
                            <table>
                                <thead>
                                    <tr>
                                        <th></th>
                                        <th>SKU</th>
                                        <th>Nombre</th>
                                        <th>Barcode</th>
                                        <th>Stock Packiyo</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products as $product): ?>
                                        <?php
                                            $selectable = ($product['status'] ?? '') === 'listo'
                                                || (
                                                    ($product['status'] ?? '') === 'importado'
                                                    && ($product['jtl_item_id'] ?? '') !== ''
                                                    && ($product['quantity_on_hand'] ?? null) !== null
                                                );
                                        ?>
                                        <tr>
                                            <td>
                                                <?php if ($selectable): ?>
                                                    <input type="checkbox" name="product_ids[]" value="<?= $this->e($product['packiyo_product_id'] ?? '') ?>">
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?= $this->e(($product['sku'] ?? '') ?: '-') ?></strong>
                                                <div class="muted">Packiyo #<?= $this->e($product['packiyo_product_id'] ?? '-') ?></div>
                                            </td>
                                            <td><?= $this->e(($product['name'] ?? '') ?: '-') ?></td>
                                            <td><?= $this->e(($product['barcode'] ?? '') ?: '-') ?></td>
                                            <td>
                                                On hand: <?= $this->e($product['quantity_on_hand'] ?? '-') ?>
                                                <div class="muted">Available: <?= $this->e($product['quantity_available'] ?? '-') ?></div>
                                            </td>
                                            <td>
                                                <span class="status <?= $this->productStatusClass((string) ($product['status'] ?? '')) ?>"><?= $this->e($product['status'] ?? '-') ?></span>
                                                <?php if (($product['jtl_item_id'] ?? '') !== ''): ?>
                                                    <div class="muted">JTL #<?= $this->e($product['jtl_item_id']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php

        return (string) ob_get_clean();
    }

    private function productStatusClass(string $status): string
    {
        return match ($status) {
            'listo' => 'ready',
            'importado' => 'synced',
            'archivado' => 'archived',
            default => 'missing_config',
        };
    }
}
