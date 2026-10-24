<?php

declare(strict_types=1);

namespace App\Views\Dashboard;

use App\Views\View;

final class OrderDetailView extends View
{
    /** @param array<string, mixed>|null $detail */
    public function render(?array $detail, ?string $error): string
    {
        if ($detail === null && $error === null) {
            return '';
        }
        ob_start();
        ?>
            <section id="order-detail">
                <div class="section-head">
                    <h2>Detalle de orden <?= $this->e($detail['number'] ?? $detail['id'] ?? '') ?></h2>
                    <a class="button secondary button-link" href="<?= $this->e($this->tabUrl('jtl-orders')) ?>">Cerrar detalle</a>
                </div>
                <div class="section-body">
                    <?php if ($error !== null): ?>
                        <div class="empty">No se pudo cargar el detalle: <?= $this->e($error) ?></div>
                    <?php else: ?>
                        <?php
                            $sent = !empty($detail['readonly']);
                            // A sent order remains editable locally for remapping/testing.
                            // Saving here only changes the local draft, not the Packiyo order.
                            $readonly = false;
                            $data = is_array($detail['data'] ?? null) ? $detail['data'] : [];
                            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
                            $catalog = is_array($detail['catalog'] ?? null) ? $detail['catalog'] : [];
                        ?>
                        <div class="details">
                            <div class="detail"><span>ID JTL</span><strong class="mono"><?= $this->e($detail['id'] ?? '-') ?></strong></div>
                            <div class="detail"><span>Cliente Packiyo</span><strong><?= $this->e(($detail['customer_name'] ?? '-') . ' #' . ($detail['customer_id'] ?? '-')) ?></strong></div>
                            <div class="detail"><span>Estado</span><strong><span class="status <?= $sent ? 'synced' : (($detail['errors'] ?? []) === [] ? 'ready' : 'missing_config') ?>"><?= $sent ? 'enviada' : (($detail['errors'] ?? []) === [] ? 'lista' : 'requiere_revision') ?></span></strong></div>
                            <div class="detail"><span>Borrador</span><strong class="mono"><?= $this->e($detail['draft']['updated_at'] ?? 'sin guardar') ?></strong></div>
                        </div>
                        <?php if ($sent): ?>
                            <div class="notice">Esta orden ya fue enviada a Packiyo, pero puedes editar su borrador local para probar o corregir el mapeo. Estos cambios no modifican la orden que ya existe en Packiyo.</div>
                        <?php elseif (($detail['customer_id'] ?? '') === ''): ?>
                            <div class="notice">Esta orden todavía no tiene un cliente Packiyo asignado. Configura primero el mapeo del cliente para poder cargar su catálogo y seleccionar un producto.</div>
                        <?php else: ?>
                            <div class="notice">Esta orden se puede editar. Selecciona un producto Packiyo en cada línea pendiente y guarda el borrador antes de enviarla.</div>
                        <?php endif; ?>
                        <?php if (($detail['catalog_error'] ?? null) !== null): ?>
                            <div class="notice">No se pudo cargar el catalogo Packiyo: <?= $this->e($detail['catalog_error']) ?></div>
                        <?php endif; ?>
                        <?php if (!$readonly && ($detail['customer_id'] ?? '') !== ''): ?>
                            <form class="inline-form" action="<?= $this->e($this->url('/packiyo/product-catalog/refresh')) ?>" method="post" style="margin:10px 0">
                                <input type="hidden" name="packiyo_customer_id" value="<?= $this->e($detail['customer_id']) ?>">
                                <input type="hidden" name="order_reference" value="<?= $this->e($detail['id']) ?>">
                                <button class="button secondary" type="submit"><?= $catalog === [] ? 'Cargar catalogo Packiyo' : 'Actualizar catalogo Packiyo' ?></button>
                                <?php if ($catalog === []): ?><span class="muted">Necesario para buscar y sugerir productos por nombre.</span><?php endif; ?>
                            </form>
                        <?php endif; ?>
                        <?php if (($detail['errors'] ?? []) !== []): ?>
                            <div class="review-errors"><?= $this->e(implode(' ', $detail['errors'])) ?></div>
                        <?php endif; ?>

                        <form id="order-draft-form" action="<?= $this->e($this->url('/jtl/orders/draft')) ?>" method="post">
                            <input type="hidden" name="order_reference" value="<?= $this->e($detail['id']) ?>">
                            <div class="order-edit-grid">
                                <?= $this->renderAddressEditor('Direccion de envio', 'shipping_address', is_array($data['shipping_address'] ?? null) ? $data['shipping_address'] : [], $readonly) ?>
                                <?= $this->renderAddressEditor('Direccion de facturacion', 'billing_address', is_array($data['billing_address'] ?? null) ? $data['billing_address'] : [], $readonly) ?>
                            </div>

                            <h3>Articulos</h3>
                            <div class="order-lines" id="order-lines">
                                <?php foreach ($items as $index => $item): ?>
                                    <?= $this->renderOrderLine((int) $index, $item, $catalog, $readonly) ?>
                                <?php endforeach; ?>
                            </div>

                            <?php if (!$readonly): ?>
                                <div class="actions">
                                    <button class="button secondary" type="button" id="add-order-line">Agregar articulo</button>
                                    <button class="button secondary" type="submit" formnovalidate>Guardar</button>
                                    <button class="button secondary" type="submit" name="save_and_close" value="1" formnovalidate>Guardar y cerrar</button>
                                    <button class="button" type="submit" name="send_after_save" value="1">Guardar y enviar a Packiyo</button>
                                </div>
                            <?php endif; ?>
                        </form>

                        <?php if (!$readonly && ($detail['draft'] ?? null) !== null): ?>
                            <form class="inline-form" action="<?= $this->e($this->url('/jtl/orders/draft/reset')) ?>" method="post" style="margin-top:10px">
                                <input type="hidden" name="order_reference" value="<?= $this->e($detail['id']) ?>">
                                <button class="button secondary" type="submit">Descartar cambios locales</button>
                            </form>
                        <?php endif; ?>

                        <?php if (!$readonly): ?>
                            <template id="order-line-template"><?= $this->renderOrderLine('__INDEX__', [
                                'external_id' => 'manual-__INDEX__', 'source_name' => '', 'name' => '', 'sku' => '',
                                'quantity' => 1, 'price' => 0, 'resolution' => 'manual', 'suggestions' => [],
                            ], $catalog, false) ?></template>
                            <script>
                                (() => {
                                    const lines = document.getElementById('order-lines');
                                    const template = document.getElementById('order-line-template');
                                    document.getElementById('add-order-line')?.addEventListener('click', () => {
                                        const index = lines.querySelectorAll('.order-line').length;
                                        lines.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(index)));
                                        const added = lines.lastElementChild;
                                        const externalId = added?.querySelector('input[name$="[external_id]"]');
                                        if (externalId) externalId.value = `manual-${Date.now()}-${index}`;
                                    });
                                    document.getElementById('order-draft-form')?.addEventListener('change', (event) => {
                                        if (event.target.matches('input[name$="[remove]"]')) {
                                            const line = event.target.closest('.order-line');
                                            line?.querySelectorAll('input:not([name$="[remove]"]), select').forEach((control) => control.disabled = event.target.checked);
                                            return;
                                        }
                                        if (!event.target.matches('.product-picker')) return;
                                        const option = event.target.selectedOptions[0];
                                        const line = event.target.closest('.order-line');
                                        if (!option || !line || !option.value) return;
                                        line.querySelector('.line-sku').value = option.value;
                                        line.querySelector('.line-name').value = option.dataset.name || option.value;
                                        line.querySelector('.line-product-id').value = option.dataset.id || '';
                                        line.querySelector('.line-resolution').value = 'manual';
                                    });
                                })();
                            </script>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php
        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $address */
    private function renderAddressEditor(string $title, string $prefix, array $address, bool $readonly): string
    {
        $fields = ['name' => 'Nombre completo', 'company' => 'Empresa', 'address1' => 'Direccion', 'address2' => 'Direccion 2', 'postal_code' => 'Codigo postal', 'city' => 'Ciudad', 'state' => 'Estado', 'country' => 'Pais', 'email' => 'Email', 'phone' => 'Telefono'];
        ob_start(); ?>
            <div class="address-editor">
                <h3><?= $this->e($title) ?></h3>
                <div class="address-fields">
                    <?php foreach ($fields as $key => $label): ?>
                        <label><?= $this->e($label) ?><input name="<?= $this->e($prefix) ?>[<?= $this->e($key) ?>]" value="<?= $this->e($this->addressValue($address, $key)) ?>" <?= $readonly ? 'readonly' : '' ?>></label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $item @param array<int, array<string, string>> $catalog */
    private function renderOrderLine(int|string $index, array $item, array $catalog, bool $readonly): string
    {
        $suggestions = is_array($item['suggestions'] ?? null) ? $item['suggestions'] : [];
        ob_start(); ?>
            <div class="order-line">
                <input type="hidden" name="items[<?= $this->e($index) ?>][external_id]" value="<?= $this->e($item['external_id'] ?? '') ?>">
                <input type="hidden" name="items[<?= $this->e($index) ?>][source_name]" value="<?= $this->e($item['source_name'] ?? '') ?>">
                <input class="line-product-id" type="hidden" name="items[<?= $this->e($index) ?>][packiyo_product_id]" value="<?= $this->e($item['packiyo_product_id'] ?? '') ?>">
                <input class="line-resolution" type="hidden" name="items[<?= $this->e($index) ?>][resolution]" value="<?= $this->e($item['resolution'] ?? 'manual') ?>">
                <div class="muted">Original JTL: <?= $this->e(($item['source_name'] ?? '') ?: 'sin nombre') ?> · <?= $this->e($item['resolution'] ?? 'sin resolver') ?><?= isset($item['score']) && $item['score'] !== null ? ' (' . $this->e((string) round((float) $item['score'] * 100)) . '%)' : '' ?></div>
                <label>Producto Packiyo
                    <select class="product-picker" <?= $readonly ? 'disabled' : '' ?>>
                        <option value="">Seleccionar producto</option>
                        <?php foreach ($catalog as $product): ?>
                            <option value="<?= $this->e($product['sku']) ?>" data-id="<?= $this->e($product['id']) ?>" data-name="<?= $this->e($product['name']) ?>" <?= (string) ($item['sku'] ?? '') === (string) $product['sku'] ? 'selected' : '' ?>><?= $this->e($product['name'] . ' · ' . $product['sku']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="order-line-fields">
                    <label>Nombre<input class="line-name" name="items[<?= $this->e($index) ?>][name]" value="<?= $this->e($item['name'] ?? '') ?>" <?= $readonly ? 'readonly' : '' ?> required></label>
                    <label>SKU<input class="line-sku" name="items[<?= $this->e($index) ?>][sku]" value="<?= $this->e($item['sku'] ?? '') ?>" <?= $readonly ? 'readonly' : '' ?> required></label>
                    <label>Cantidad<input type="number" min="0.0001" step="any" name="items[<?= $this->e($index) ?>][quantity]" value="<?= $this->e($item['quantity'] ?? 1) ?>" <?= $readonly ? 'readonly' : '' ?> required></label>
                    <label>Precio<input type="number" min="0" step="any" name="items[<?= $this->e($index) ?>][price]" value="<?= $this->e($item['price'] ?? 0) ?>" <?= $readonly ? 'readonly' : '' ?> required></label>
                </div>
                <?php if (!$readonly): ?>
                    <label><input type="checkbox" name="items[<?= $this->e($index) ?>][remember]" value="1" <?= ($item['source_name'] ?? '') !== '' ? 'checked' : '' ?>> Recordar nombre BOL → SKU para este cliente</label>
                    <label><input type="checkbox" name="items[<?= $this->e($index) ?>][remove]" value="1"> Quitar esta linea</label>
                <?php endif; ?>
                <?php if ($suggestions !== []): ?>
                    <div class="suggestions">Sugerencias: <?php foreach ($suggestions as $suggestion): ?><?= $this->e($suggestion['name'] . ' (' . round((float) $suggestion['score'] * 100) . '%)') ?> · <?php endforeach; ?></div>
                <?php endif; ?>
            </div>
        <?php return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $address */
    private function addressValue(array $address, string $key): string
    {
        $aliases = [
            'name' => ['name', 'Name', 'full_name', 'fullName'], 'company' => ['company', 'Company', 'companyName', 'CompanyName'],
            'address1' => ['address1', 'Address1', 'street', 'Street', 'street1', 'Street1'], 'address2' => ['address2', 'Address2', 'street2', 'Street2'],
            'postal_code' => ['postal_code', 'zip', 'Zip', 'zipcode', 'postalCode', 'PostalCode'], 'city' => ['city', 'City'],
            'state' => ['state', 'State', 'province', 'Province', 'region', 'Region'], 'country' => ['country', 'Country', 'country_code', 'countryCode', 'CountryIso', 'CountryISO'],
            'email' => ['email', 'Email', 'mail', 'Mail', 'EmailAddress'], 'phone' => ['phone', 'Phone', 'telephone', 'Telephone', 'PhoneNumber', 'MobilePhoneNumber'],
        ];
        foreach ($aliases[$key] ?? [$key] as $alias) {
            if (is_scalar($address[$alias] ?? null) && trim((string) $address[$alias]) !== '') return trim((string) $address[$alias]);
        }
        if ($key === 'name') {
            $first = $address['first_name'] ?? $address['firstName'] ?? $address['FirstName'] ?? '';
            $last = $address['last_name'] ?? $address['lastName'] ?? $address['LastName'] ?? '';
            return trim((string) $first . ' ' . (string) $last);
        }
        return '';
    }
}
