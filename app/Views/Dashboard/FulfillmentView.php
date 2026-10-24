<?php

declare(strict_types=1);

namespace App\Views\Dashboard;

use App\Views\View;

final class FulfillmentView extends View
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>|null $state
     * @param array<int, array<string, mixed>> $activeCustomers
     */
    public function render(array $rows, ?array $state, array $activeCustomers, string $selectedCustomerId): string
    {
        ob_start();
        ?>
            <section>
                <div class="section-head">
                    <h2>Fulfillment Packiyo -> JTL</h2>
                </div>
                <div class="section-body">
                    <div class="fulfillment-toolbar">
                        <form class="fulfillment-filter-form" action="<?= $this->e($this->url('/')) ?>" method="get">
                            <input type="hidden" name="tab" value="fulfillment">
                            <select name="fulfillment_customer_id" aria-label="Cliente Packiyo">
                                <option value="">Todos los clientes</option>
                                <?php foreach ($activeCustomers as $customer): ?>
                                    <?php $customerId = (string) ($customer['packiyo_customer_id'] ?? ''); ?>
                                    <option value="<?= $this->e($customerId) ?>" <?= $customerId === $selectedCustomerId ? 'selected' : '' ?>>
                                        <?= $this->e($this->customerDisplayName($customer) . ' #' . $customerId) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="button" type="submit">Filtrar</button>
                            <a class="button secondary" href="<?= $this->e($this->tabUrl('fulfillment')) ?>">Limpiar</a>
                        </form>

                        <form class="fulfillment-sync-form" action="<?= $this->e($this->url('/fulfillment/sync')) ?>" method="post">
                            <input type="hidden" name="packiyo_customer_id" value="<?= $this->e($selectedCustomerId) ?>">
                            <button class="button" type="submit">
                                <?= $selectedCustomerId !== '' ? 'Buscar tracking nuevo (cliente filtrado)' : 'Buscar tracking nuevo ahora' ?>
                            </button>
                            <span class="muted fulfillment-sync-hint">
                                Revisa Packiyo por tracking nuevo y lo envia a JTL al instante, sin esperar al cron. El resultado aparece arriba de la pagina.
                            </span>
                        </form>
                    </div>

                    <div class="details">
                        <div class="detail">
                            <span>Ultima corrida</span>
                            <strong><?= $this->e($state['last_success_at'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Ultimo checkpoint</span>
                            <strong><?= $this->e($state['last_synced_at'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Estado</span>
                            <strong><?= $this->e($state['last_message'] ?? '-') ?></strong>
                        </div>
                    </div>

                    <?php if ($rows === []): ?>
                        <div class="empty">Todavia no hay ordenes mapeadas para revisar.</div>
                    <?php else: ?>
                        <div class="scroll-table order-table-scroll">
                        <table class="sortable-table" data-sort-table="fulfillment">
                            <thead>
                                <tr>
                                    <th scope="col" aria-sort="none"><button class="table-sort" type="button" data-sort-key="jtl-order" data-sort-type="text" aria-label="Ordenar por orden JTL">Orden JTL <span class="sort-indicator" aria-hidden="true">↕</span></button></th>
                                    <th scope="col" aria-sort="none"><button class="table-sort" type="button" data-sort-key="packiyo-order" data-sort-type="text" aria-label="Ordenar por orden Packiyo">Packiyo <span class="sort-indicator" aria-hidden="true">↕</span></button></th>
                                    <th scope="col" aria-sort="none"><button class="table-sort" type="button" data-sort-key="tracking" data-sort-type="text" aria-label="Ordenar por tracking">Tracking <span class="sort-indicator" aria-hidden="true">↕</span></button></th>
                                    <th scope="col" aria-sort="none"><button class="table-sort" type="button" data-sort-key="carrier" data-sort-type="text" aria-label="Ordenar por transportista">Carrier <span class="sort-indicator" aria-hidden="true">↕</span></button></th>
                                    <th scope="col" aria-sort="none"><button class="table-sort" type="button" data-sort-key="delivery-note" data-sort-type="text" aria-label="Ordenar por delivery note">Delivery note <span class="sort-indicator" aria-hidden="true">↕</span></button></th>
                                    <th scope="col" aria-sort="none"><button class="table-sort" type="button" data-sort-key="status" data-sort-type="text" aria-label="Ordenar por estado">Estado <span class="sort-indicator" aria-hidden="true">↕</span></button></th>
                                    <th scope="col" aria-sort="none"><button class="table-sort" type="button" data-sort-key="date" data-sort-type="date" aria-label="Ordenar por fecha">Fecha <span class="sort-indicator" aria-hidden="true">↕</span></button></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td class="mono" data-sort-value="<?= $this->e(($row['jtl_order_number'] ?? '') ?: ($row['jtl_order_id'] ?? '')) ?>">
                                            <strong><?= $this->e(($row['jtl_order_number'] ?? '') ?: ($row['jtl_order_id'] ?? '-')) ?></strong>
                                            <div class="muted">ID <?= $this->e($row['jtl_order_id'] ?? '-') ?></div>
                                        </td>
                                        <td class="mono" data-sort-value="<?= $this->e(($row['packiyo_order_number'] ?? '') ?: ($row['packiyo_order_id'] ?? '')) ?>">
                                            <strong><?= $this->e(($row['packiyo_order_number'] ?? '') ?: ($row['packiyo_order_id'] ?? '-')) ?></strong>
                                            <?php if (($row['packiyo_order_number'] ?? '') !== '' && ($row['packiyo_order_id'] ?? '') !== ''): ?>
                                                <div class="muted">ID <?= $this->e($row['packiyo_order_id']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-sort-value="<?= $this->e($row['tracking_number'] ?? '') ?>">
                                            <strong><?= $this->e($row['tracking_number'] ?? '-') ?></strong>
                                            <?php if (($row['tracking_url'] ?? '') !== ''): ?>
                                                <div class="muted"><?= $this->e($row['tracking_url']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-sort-value="<?= $this->e($row['carrier'] ?? '') ?>"><?= $this->e($row['carrier'] ?? '-') ?></td>
                                        <td data-sort-value="<?= $this->e($row['jtl_delivery_note_id'] ?? '') ?>">
                                            <?= $this->e($row['jtl_delivery_note_id'] ?? '-') ?>
                                            <?php if (($row['jtl_package_id'] ?? '') !== ''): ?>
                                                <div class="muted">Package <?= $this->e($row['jtl_package_id']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-sort-value="<?= $this->e($row['status'] ?? '') ?>">
                                            <?php
                                                $fulfillmentStatus = (string) ($row['status'] ?? '');
                                                $fulfillmentStatusClass = match (true) {
                                                    $fulfillmentStatus === 'synced' || $fulfillmentStatus === 'already_present' => 'synced',
                                                    $fulfillmentStatus === 'failed' || $fulfillmentStatus === 'lookup_failed' => 'failed',
                                                    default => 'missing_config',
                                                };
                                                $fulfillmentStatusLabel = match ($fulfillmentStatus) {
                                                    'pending' => 'Pendiente de revision',
                                                    'waiting_tracking' => 'Esperando tracking',
                                                    'lookup_failed' => 'Error leyendo Packiyo',
                                                    'synced' => 'Enviado a JTL',
                                                    'already_present' => 'Ya presente en JTL',
                                                    'shipped_pending' => 'Tracking enviado; Shipped pendiente',
                                                    'failed' => 'Error enviando a JTL',
                                                    'jtl_cancelled' => 'Cancelada en JTL (ignorada)',
                                                    default => $fulfillmentStatus ?: '-',
                                                };
                                            ?>
                                            <span class="status <?= $this->e($fulfillmentStatusClass) ?>"><?= $this->e($fulfillmentStatusLabel) ?></span>
                                            <?php if (($row['last_error'] ?? '') !== ''): ?>
                                                <div class="muted"><?= $this->e($row['last_error']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="mono" data-sort-value="<?= $this->e($row['synced_at'] ?? '') ?>">
                                            <?= $this->e($row['synced_at'] ?? '-') ?>
                                            <?php if (($row['fulfillment_last_checked_at'] ?? '') !== ''): ?>
                                                <div class="muted">Revisado <?= $this->e($row['fulfillment_last_checked_at']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php

        return (string) ob_get_clean();
    }
}
