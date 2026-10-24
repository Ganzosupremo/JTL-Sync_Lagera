<?php

declare(strict_types=1);

namespace App\Views\Dashboard;

use App\Views\View;

final class CustomerMappingsView extends View
{
    /**
     * @param array<int, array<string, mixed>> $orderSources
     * @param array<int, array<string, mixed>> $customerMappings
     * @param array<int, array<string, mixed>> $activeCustomers
     * @param array<int, array<string, mixed>> $productSkuAliasRows
     * @param array<int, array<string, mixed>> $productSkuAliasProducts
     */
    public function render(
        array $orderSources,
        array $customerMappings,
        array $activeCustomers,
        array $productSkuAliasRows,
        array $productSkuAliasProducts,
        ?string $productSkuAliasError,
        string $selectedSkuAliasCustomerId,
        string $selectedNameCustomerId,
        array $productNameMappings,
        array $productNameCatalog,
        ?string $productNameCatalogError
    ): string {
        ob_start();
        ?>
            <section>
                <div class="section-head">
                    <h2>Tiendas/canales JTL detectados</h2>
                    <form action="<?= $this->e($this->url('/jtl/order-sources/detect')) ?>" method="post">
                        <button class="button" type="submit">Detectar tiendas desde JTL</button>
                    </form>
                </div>
                <div class="section-body">
                    <form class="manual-order-form" action="<?= $this->e($this->url('/sync/order')) ?>" method="post">
                        <input name="order_reference" placeholder="JTL order ID o numero, ej. AU-202606-10041" required>
                        <button class="button" type="submit">Enviar orden a Packiyo</button>
                    </form>

                    <?php if ($orderSources === []): ?>
                        <div class="empty">Pulsa detectar para leer las ordenes actuales de JTL y ver tiendas o canales como Temu EsSo.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Tipo</th>
                                    <th>Valor JTL</th>
                                    <th>Campo JTL</th>
                                    <th>Ordenes</th>
                                    <th>Muestra</th>
                                    <th>Ultima deteccion</th>
                                    <th>Prueba</th>
                                    <th>Mapear a Packiyo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orderSources as $source): ?>
                                    <?php $suggestion = $this->suggestCustomerForSource((string) $source['source_value'], $activeCustomers); ?>
                                    <tr>
                                        <td><?= $this->e($source['source_type']) ?></td>
                                        <td><strong><?= $this->e($source['source_value']) ?></strong></td>
                                        <td><?= $this->e($source['source_path'] ?? '-') ?></td>
                                        <td><?= $this->e($source['order_count']) ?></td>
                                        <td><?= $this->e(($source['sample_order_number'] ?? '') ?: ($source['sample_order_id'] ?? '-')) ?></td>
                                        <td><?= $this->e($source['last_seen_at']) ?></td>
                                        <td>
                                            <?php if (($source['sample_order_id'] ?? '') !== ''): ?>
                                                <form class="inline-form" action="<?= $this->e($this->url('/sync/order')) ?>" method="post">
                                                    <input type="hidden" name="order_reference" value="<?= $this->e($source['sample_order_id']) ?>">
                                                    <button class="button secondary small" type="submit">Enviar muestra</button>
                                                </form>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form class="mapping-form inline-form" action="<?= $this->e($this->url('/packiyo/customer-mappings')) ?>" method="post">
                                                <input type="hidden" name="match_type" value="<?= $this->e($source['source_type']) ?>">
                                                <input type="hidden" name="match_value" value="<?= $this->e($source['source_value']) ?>">
                                                <input list="packiyo-customer-options" name="packiyo_customer_id" placeholder="Packiyo customer ID" value="<?= $this->e($suggestion['packiyo_customer_id'] ?? '') ?>" required>
                                                <input name="packiyo_customer_name" placeholder="Nombre" value="<?= $this->e($suggestion !== null ? $this->customerDisplayName($suggestion) : '') ?>">
                                                <input name="priority" type="number" value="50" min="1" step="1" aria-label="Prioridad">
                                                <button class="button small" type="submit">Mapear</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>

            <section>
                <div class="section-head"><h2>Equivalencias de nombres BOL → Packiyo</h2></div>
                <div class="section-body">
                    <form class="sku-alias-filter-form" action="<?= $this->e($this->url('/')) ?>" method="get">
                        <input type="hidden" name="tab" value="customer-mappings">
                        <select name="name_customer_id" required>
                            <option value="">Cliente Packiyo</option>
                            <?php foreach ($activeCustomers as $customer): ?>
                                <?php $customerId = (string) ($customer['packiyo_customer_id'] ?? ''); ?>
                                <option value="<?= $this->e($customerId) ?>" <?= $customerId === $selectedNameCustomerId ? 'selected' : '' ?>><?= $this->e($this->customerDisplayName($customer) . ' #' . $customerId) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="button" type="submit">Cargar catalogo</button>
                    </form>
                    <?php if ($selectedNameCustomerId === ''): ?>
                        <div class="empty">Selecciona un cliente para administrar nombres BOL y sus productos Packiyo.</div>
                    <?php elseif ($productNameCatalogError !== null): ?>
                        <div class="empty">No se pudo cargar el catalogo: <?= $this->e($productNameCatalogError) ?></div>
                    <?php else: ?>
                        <form class="inline-form" action="<?= $this->e($this->url('/packiyo/product-catalog/refresh')) ?>" method="post" style="margin-bottom:12px">
                            <input type="hidden" name="packiyo_customer_id" value="<?= $this->e($selectedNameCustomerId) ?>">
                            <button class="button secondary" type="submit"><?= $productNameCatalog === [] ? 'Cargar productos desde Packiyo' : 'Actualizar productos desde Packiyo' ?></button>
                        </form>
                        <form class="mapping-form" action="<?= $this->e($this->url('/packiyo/product-name-mappings')) ?>" method="post">
                            <input type="hidden" name="packiyo_customer_id" value="<?= $this->e($selectedNameCustomerId) ?>">
                            <input name="source_name" placeholder="Nombre original BOL" required style="grid-column:span 2">
                            <input list="name-mapping-product-options" name="packiyo_sku" placeholder="SKU Packiyo" required style="grid-column:span 2">
                            <button class="button" type="submit">Guardar equivalencia</button>
                        </form>
                        <datalist id="name-mapping-product-options">
                            <?php foreach ($productNameCatalog as $product): ?>
                                <option value="<?= $this->e($product['sku']) ?>" label="<?= $this->e($product['name']) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <?php if ($productNameMappings === []): ?>
                            <div class="empty">Todavia no hay equivalencias guardadas.</div>
                        <?php else: ?>
                            <table>
                                <thead><tr><th>Nombre BOL</th><th>Producto Packiyo</th><th>SKU</th><th></th></tr></thead>
                                <tbody>
                                    <?php foreach ($productNameMappings as $nameMapping): ?>
                                        <tr>
                                            <td><?= $this->e($nameMapping['source_name']) ?></td>
                                            <td><?= $this->e(($nameMapping['packiyo_product_name'] ?? '') ?: '-') ?></td>
                                            <td><strong><?= $this->e($nameMapping['packiyo_sku']) ?></strong></td>
                                            <td><form class="inline-form" action="<?= $this->e($this->url('/packiyo/product-name-mappings/delete')) ?>" method="post"><input type="hidden" name="id" value="<?= $this->e($nameMapping['id']) ?>"><input type="hidden" name="packiyo_customer_id" value="<?= $this->e($selectedNameCustomerId) ?>"><button class="button secondary small" type="submit">Eliminar</button></form></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section>
                <div class="section-head">
                    <h2>Mapeos de clientes</h2>
                </div>
                <div class="section-body">
                    <form class="mapping-form" action="<?= $this->e($this->url('/packiyo/customer-mappings')) ?>" method="post">
                        <select name="match_type" aria-label="Tipo" required>
                            <option value="marketplace">Marketplace</option>
                            <option value="sales_channel">Sales channel</option>
                            <option value="shop">Shop</option>
                            <option value="customer_number">Customer number</option>
                            <option value="customer_id">Customer ID</option>
                            <option value="email">Email</option>
                            <option value="company">Company</option>
                            <option value="default">Default</option>
                        </select>
                        <input name="match_value" placeholder="Valor JTL" required>
                        <input list="packiyo-customer-options" name="packiyo_customer_id" placeholder="Packiyo customer ID" required>
                        <input name="packiyo_customer_name" placeholder="Nombre">
                        <input name="priority" type="number" value="100" min="1" step="1" aria-label="Prioridad">
                        <button class="button" type="submit">Guardar</button>
                    </form>

                    <datalist id="packiyo-customer-options">
                        <?php foreach ($activeCustomers as $customer): ?>
                            <option value="<?= $this->e($customer['packiyo_customer_id'] ?? '') ?>" label="<?= $this->e($this->customerDisplayName($customer)) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>

                    <?php if ($customerMappings === []): ?>
                        <div class="empty">Sin mapeos de clientes.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Tipo</th>
                                    <th>Valor JTL</th>
                                    <th>Packiyo Customer</th>
                                    <th>Prioridad</th>
                                    <th>Estado</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customerMappings as $customerMapping): ?>
                                    <tr>
                                        <td><?= $this->e($customerMapping['match_type']) ?></td>
                                        <td><?= $this->e($customerMapping['match_value']) ?></td>
                                        <td><?= $this->e(($customerMapping['packiyo_customer_name'] ?: '-') . ' #' . $customerMapping['packiyo_customer_id']) ?></td>
                                        <td><?= $this->e($customerMapping['priority']) ?></td>
                                        <td><span class="status <?= ((int) $customerMapping['active'] === 1) ? 'active' : 'inactive' ?>"><?= ((int) $customerMapping['active'] === 1) ? 'active' : 'inactive' ?></span></td>
                                        <td>
                                            <form class="inline-form" action="<?= $this->e($this->url('/packiyo/customer-mappings/delete')) ?>" method="post">
                                                <input type="hidden" name="id" value="<?= $this->e($customerMapping['id']) ?>">
                                                <button class="button secondary small" type="submit">Eliminar</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>

            <section>
                <div class="section-head">
                    <h2>Mapeo de SKUs por cliente</h2>
                </div>
                <div class="section-body">
                    <form class="sku-alias-filter-form" action="<?= $this->e($this->url('/')) ?>" method="get">
                        <input type="hidden" name="tab" value="customer-mappings">
                        <select name="sku_customer_id" required>
                            <option value="">Cliente Packiyo</option>
                            <?php foreach ($activeCustomers as $customer): ?>
                                <?php $customerId = (string) ($customer['packiyo_customer_id'] ?? ''); ?>
                                <option value="<?= $this->e($customerId) ?>" <?= $customerId === $selectedSkuAliasCustomerId ? 'selected' : '' ?>>
                                    <?= $this->e($this->customerDisplayName($customer) . ' #' . $customerId) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="button" type="submit">Jalar productos Packiyo</button>
                    </form>

                    <?php if ($selectedSkuAliasCustomerId === ''): ?>
                        <div class="empty">Selecciona un cliente para crear aliases como 769382487860 -> 0769382487860.</div>
                    <?php elseif ($productSkuAliasError !== null): ?>
                        <div class="empty">No se pudieron leer productos de Packiyo: <?= $this->e($productSkuAliasError) ?></div>
                    <?php else: ?>
                        <form class="sku-alias-form" action="<?= $this->e($this->url('/packiyo/sku-aliases')) ?>" method="post">
                            <input type="hidden" name="packiyo_customer_id" value="<?= $this->e($selectedSkuAliasCustomerId) ?>">
                            <input name="alias_sku" placeholder="SKU marketplace, ej. 769382487860" required>
                            <input list="packiyo-sku-options" name="original_sku" placeholder="SKU original Packiyo" required>
                            <input name="packiyo_product_id" placeholder="Product ID opcional">
                            <input name="product_name" placeholder="Nombre opcional">
                            <button class="button" type="submit">Guardar alias</button>
                        </form>

                        <datalist id="packiyo-sku-options">
                            <?php foreach ($productSkuAliasProducts as $product): ?>
                                <option value="<?= $this->e($product['sku'] ?? '') ?>" label="<?= $this->e(($product['name'] ?? '') . ' #' . ($product['packiyo_product_id'] ?? '')) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>

                        <?php if ($productSkuAliasProducts === []): ?>
                            <div class="empty">No hay productos Packiyo para este cliente.</div>
                        <?php else: ?>
                            <form class="inline-form" action="<?= $this->e($this->url('/packiyo/sku-aliases/generate-bulk')) ?>" method="post" style="margin-bottom: 12px;">
                                <input type="hidden" name="packiyo_customer_id" value="<?= $this->e($selectedSkuAliasCustomerId) ?>">
                                <button class="button secondary" type="submit">Agregar comunes a todos</button>
                            </form>
                            <div class="scroll-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Producto Packiyo</th>
                                            <th>SKU original</th>
                                            <th>Aliases activos</th>
                                            <th>Aliases comunes</th>
                                            <th>Alias manual</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($productSkuAliasProducts as $product): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= $this->e(($product['name'] ?? '') ?: '-') ?></strong>
                                                    <div class="muted">Packiyo #<?= $this->e($product['packiyo_product_id'] ?? '-') ?></div>
                                                </td>
                                                <td><?= $this->e($product['sku'] ?? '-') ?></td>
                                                <td>
                                                    <?php if (($product['aliases'] ?? []) === []): ?>
                                                        <span class="muted">Sin aliases</span>
                                                    <?php else: ?>
                                                        <div class="alias-list">
                                                            <?php foreach ($product['aliases'] as $alias): ?>
                                                                <span class="alias-chip"><?= $this->e($alias['alias_sku'] ?? '') ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (($product['suggested_aliases'] ?? []) === []): ?>
                                                        <span class="muted">Sin sugerencias nuevas</span>
                                                    <?php else: ?>
                                                        <div class="alias-list">
                                                            <?php foreach ($product['suggested_aliases'] as $aliasSku): ?>
                                                                <span class="alias-chip"><?= $this->e($aliasSku) ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                        <form class="inline-form" action="<?= $this->e($this->url('/packiyo/sku-aliases/generate')) ?>" method="post">
                                                            <input type="hidden" name="packiyo_customer_id" value="<?= $this->e($selectedSkuAliasCustomerId) ?>">
                                                            <input type="hidden" name="packiyo_product_id" value="<?= $this->e($product['packiyo_product_id'] ?? '') ?>">
                                                            <input type="hidden" name="original_sku" value="<?= $this->e($product['sku'] ?? '') ?>">
                                                            <input type="hidden" name="product_name" value="<?= $this->e($product['name'] ?? '') ?>">
                                                            <button class="button secondary small" type="submit">Agregar comunes</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <form class="sku-alias-row-form" action="<?= $this->e($this->url('/packiyo/sku-aliases')) ?>" method="post">
                                                        <input type="hidden" name="packiyo_customer_id" value="<?= $this->e($selectedSkuAliasCustomerId) ?>">
                                                        <input type="hidden" name="packiyo_product_id" value="<?= $this->e($product['packiyo_product_id'] ?? '') ?>">
                                                        <input type="hidden" name="original_sku" value="<?= $this->e($product['sku'] ?? '') ?>">
                                                        <input type="hidden" name="product_name" value="<?= $this->e($product['name'] ?? '') ?>">
                                                        <input name="alias_sku" placeholder="SKU Temu" required>
                                                        <button class="button small" type="submit">Guardar</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <h3>Aliases guardados</h3>
                        <?php if ($productSkuAliasRows === []): ?>
                            <div class="empty">Sin aliases guardados para este cliente.</div>
                        <?php else: ?>
                            <div class="scroll-table sku-alias-saved-scroll">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Alias marketplace</th>
                                            <th>SKU Packiyo</th>
                                            <th>Producto</th>
                                            <th>Estado</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($productSkuAliasRows as $alias): ?>
                                            <tr>
                                                <td><?= $this->e($alias['alias_sku'] ?? '-') ?></td>
                                                <td><strong><?= $this->e($alias['original_sku'] ?? '-') ?></strong></td>
                                                <td>
                                                    <?= $this->e(($alias['product_name'] ?? '') ?: '-') ?>
                                                    <?php if (($alias['packiyo_product_id'] ?? '') !== ''): ?>
                                                        <div class="muted">Packiyo #<?= $this->e($alias['packiyo_product_id']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><span class="status <?= ((int) ($alias['active'] ?? 0) === 1) ? 'active' : 'inactive' ?>"><?= ((int) ($alias['active'] ?? 0) === 1) ? 'active' : 'inactive' ?></span></td>
                                                <td>
                                                    <form class="inline-form" action="<?= $this->e($this->url('/packiyo/sku-aliases/delete')) ?>" method="post">
                                                        <input type="hidden" name="id" value="<?= $this->e($alias['id'] ?? '') ?>">
                                                        <input type="hidden" name="packiyo_customer_id" value="<?= $this->e($selectedSkuAliasCustomerId) ?>">
                                                        <button class="button secondary small" type="submit">Eliminar</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param array<int, array<string, mixed>> $customers
     * @return array<string, mixed>|null
     */
    private function suggestCustomerForSource(string $sourceValue, array $customers): ?array
    {
        $normalizedSource = $this->normalizeMatch($sourceValue);

        if ($normalizedSource === '') {
            return null;
        }

        foreach ($customers as $customer) {
            foreach (['name', 'company_name', 'email'] as $key) {
                $value = $customer[$key] ?? null;

                if (!is_scalar($value)) {
                    continue;
                }

                $normalizedCustomer = $this->normalizeMatch((string) $value);

                if ($normalizedCustomer !== '' && str_contains($normalizedSource, $normalizedCustomer)) {
                    return $customer;
                }
            }
        }

        return null;
    }

    private function normalizeMatch(string $value): string
    {
        return strtolower(trim($value));
    }
}
