<?php

declare(strict_types=1);

namespace App\Views\Dashboard;

use App\Support\Arr;
use App\Support\Config;
use App\Views\View;

final class JtlOrdersView extends View
{
    /**
     * @param array<int, array<string, mixed>> $jtlOrders
     * @param array<int, array<string, mixed>> $jtlWorkerSyncs
     * @param array<int, array<string, mixed>> $activeCustomers
     */
    public function render(
        array $jtlOrders,
        ?string $error,
        array $jtlWorkerSyncs,
        ?array $jtlWorkerStatus,
        ?string $jtlWorkerError,
        array $activeCustomers,
        string $customerFilter,
        string $mappedCustomerFilter
    ): string
    {
        $filtersActive = $customerFilter !== '' || $mappedCustomerFilter !== '';
        $bulkDisabled = $mappedCustomerFilter === '__unmapped__';
        ob_start();
        ?>
            <section>
                <div class="section-head">
                    <h2>Ordenes nuevas de JTL</h2>
                    <div class="actions">
                        <form action="<?= $this->e($this->url('/sync')) ?>" method="post" data-jtl-bulk-form>
                            <input type="hidden" name="return_tab" value="jtl-orders">
                            <input type="hidden" name="jtl_customer" value="<?= $this->e($customerFilter) ?>">
                            <input type="hidden" name="jtl_mapped_customer" value="<?= $this->e($mappedCustomerFilter) ?>">
                            <button class="button" type="submit" <?= $bulkDisabled ? 'disabled' : '' ?>><?= $bulkDisabled ? 'Mapear antes de enviar' : ($filtersActive ? 'Enviar filtradas a Packiyo' : 'Enviar todas a Packiyo') ?></button>
                        </form>
                        <form action="<?= $this->e($this->url('/')) ?>" method="get">
                            <input type="hidden" name="tab" value="jtl-orders">
                            <button class="button secondary" type="submit">Recargar desde JTL</button>
                        </form>
                    </div>
                </div>
                <div class="section-body">
                    <?= $this->renderJtlWorkerPanel($jtlWorkerSyncs, $jtlWorkerStatus, $jtlWorkerError) ?>

                    <form class="jtl-order-filter-form" action="<?= $this->e($this->url('/')) ?>" method="get" data-client-filter-table="jtl-orders">
                        <input type="hidden" name="tab" value="jtl-orders">
                        <input name="jtl_customer" value="<?= $this->e($customerFilter) ?>" placeholder="Filtrar por cliente orden">
                        <select name="jtl_mapped_customer">
                            <option value="">Cliente Packiyo mapeado</option>
                            <option value="__unmapped__" <?= $mappedCustomerFilter === '__unmapped__' ? 'selected' : '' ?>>Sin mapeo</option>
                            <?php foreach ($activeCustomers as $customer): ?>
                                <?php $customerId = (string) ($customer['packiyo_customer_id'] ?? ''); ?>
                                <option value="<?= $this->e($customerId) ?>" <?= $mappedCustomerFilter === $customerId ? 'selected' : '' ?>>
                                    <?= $this->e($this->customerDisplayName($customer) . ' #' . $customerId) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="button" type="submit">Filtrar</button>
                        <button class="button secondary" type="button" data-clear-jtl-filters data-clear-url="<?= $this->e($this->tabUrl('jtl-orders')) ?>">Limpiar</button>
                    </form>

                    <?php if ($error !== null): ?>
                        <div class="empty">No se pudieron leer las ordenes nuevas de JTL: <?= $this->e($error) ?></div>
                    <?php elseif ($jtlOrders === []): ?>
                        <div class="empty"><?= $filtersActive ? 'Sin ordenes nuevas de JTL para estos filtros.' : 'Sin ordenes nuevas de JTL.' ?></div>
                    <?php else: ?>
                        <div class="scroll-table order-table-scroll">
                        <table class="sortable-table" data-sort-table="jtl-orders">
                            <thead>
                                <tr>
                                    <th scope="col" aria-sort="none"><button class="table-sort" type="button" data-sort-key="order" data-sort-type="text" aria-label="Ordenar por orden JTL">Orden JTL <span class="sort-indicator" aria-hidden="true">↕</span></button></th>
                                    <th scope="col" aria-sort="none"><button class="table-sort" type="button" data-sort-key="date" data-sort-type="date" aria-label="Ordenar por fecha">Fecha <span class="sort-indicator" aria-hidden="true">↕</span></button></th>
                                    <th scope="col" aria-sort="none"><button class="table-sort" type="button" data-sort-key="customer" data-sort-type="text" aria-label="Ordenar por cliente de la orden">Cliente orden <span class="sort-indicator" aria-hidden="true">↕</span></button></th>
                                    <th scope="col" aria-sort="none"><button class="table-sort" type="button" data-sort-key="channel" data-sort-type="text" aria-label="Ordenar por canal JTL">Canal JTL <span class="sort-indicator" aria-hidden="true">↕</span></button></th>
                                    <th scope="col" aria-sort="none"><button class="table-sort" type="button" data-sort-key="packiyo-customer" data-sort-type="text" aria-label="Ordenar por cliente Packiyo">Cliente Packiyo <span class="sort-indicator" aria-hidden="true">↕</span></button></th>
                                    <th scope="col" aria-sort="none"><button class="table-sort" type="button" data-sort-key="status" data-sort-type="text" aria-label="Ordenar por estado">Estado <span class="sort-indicator" aria-hidden="true">↕</span></button></th>
                                    <th scope="col">Accion</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($jtlOrders as $order): ?>
                                    <tr
                                        data-jtl-contact="<?= $this->e($order['contact'] ?? '') ?>"
                                        data-jtl-mapped-customer="<?= $this->e($order['packiyo_customer_id'] ?? '') ?>"
                                        data-jtl-mapped="<?= !empty($order['mapped']) ? '1' : '0' ?>"
                                    >
                                        <td class="mono" data-sort-value="<?= $this->e(($order['number'] ?? '') ?: ($order['id'] ?? '')) ?>">
                                            <strong><?= $this->e(($order['number'] ?? '') ?: ($order['id'] ?? '-')) ?></strong>
                                            <div class="muted">ID <?= $this->e(($order['id'] ?? '') ?: '-') ?></div>
                                            <?php if (($order['marketplace_number'] ?? '') !== ''): ?>
                                                <div class="muted">Marketplace <?= $this->e($order['marketplace_number']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="mono" data-sort-value="<?= $this->e($order['ordered_at'] ?? '') ?>"><?= $this->e($order['ordered_at'] ?? '-') ?></td>
                                        <td data-sort-value="<?= $this->e($order['contact'] ?? '') ?>"><?= $this->e($order['contact'] ?? '-') ?></td>
                                        <td data-sort-value="<?= $this->e($order['source'] ?? '') ?>">
                                            <strong><?= $this->e($order['source'] ?? '-') ?></strong>
                                            <?php if (($order['source_type'] ?? '') !== ''): ?>
                                                <div class="muted"><?= $this->e($order['source_type']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-sort-value="<?= $this->e(!empty($order['mapped']) ? ($order['packiyo_customer'] ?? '') : 'sin_mapeo') ?>">
                                            <?php if (!empty($order['mapped'])): ?>
                                                <?= $this->e($order['packiyo_customer'] ?? '-') ?>
                                            <?php else: ?>
                                                <span class="status missing_config">sin_mapeo</span>
                                                <?php if (($order['candidate_summary'] ?? '') !== ''): ?>
                                                    <div class="muted"><?= $this->e($order['candidate_summary']) ?></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td data-sort-value="<?= $this->e($order['sync_state'] ?? '') ?>">
                                            <?php if (($order['reference'] ?? '') !== ''): ?>
                                                <a class="button secondary small button-link" href="<?= $this->e($this->url('/?') . http_build_query(['tab' => 'jtl-orders', 'order_reference' => $order['reference'], 'jtl_customer' => $customerFilter, 'jtl_mapped_customer' => $mappedCustomerFilter])) ?>">Editar y mapear</a>
                                            <?php endif; ?>
                                            <?php if (($order['sync_state'] ?? '') === 'confirmed'): ?>
                                                <span class="status synced">confirmada</span>
                                                <div class="muted">Packiyo #<?= $this->e($order['packiyo_order_id'] ?? '-') ?></div>
                                            <?php elseif (($order['sync_state'] ?? '') === 'archived'): ?>
                                                <span class="status archived">archivada</span>
                                                <div class="muted"><?= $this->e($order['sync_message'] ?? 'Archivada en Packiyo') ?></div>
                                            <?php elseif (($order['sync_state'] ?? '') === 'local_only'): ?>
                                                <span class="status missing_config">solo_local</span>
                                                <div class="muted">Packiyo #<?= $this->e($order['packiyo_order_id'] ?? '-') ?> no existe</div>
                                            <?php elseif (($order['sync_state'] ?? '') === 'local_mapping'): ?>
                                                <span class="status ready">enviada_local</span>
                                                <div class="muted">Packiyo #<?= $this->e($order['packiyo_order_id'] ?? '-') ?> pendiente de verificacion</div>
                                            <?php elseif (($order['sync_state'] ?? '') === 'unknown'): ?>
                                                <span class="status missing_config">sin_verificar</span>
                                                <div class="muted"><?= $this->e($order['sync_message'] ?? 'No se pudo verificar Packiyo') ?></div>
                                            <?php elseif (($order['sync_error'] ?? '') !== ''): ?>
                                                <span class="status failed">error_envio</span>
                                                <div class="muted" title="<?= $this->e($order['sync_error']) ?>"><?= $this->e(mb_strimwidth((string) $order['sync_error'], 0, 160, '...')) ?></div>
                                                <?php if (($order['sync_error_at'] ?? '') !== ''): ?>
                                                    <div class="muted">Ultimo intento <?= $this->e($order['sync_error_at']) ?></div>
                                                <?php endif; ?>
                                            <?php elseif (!empty($order['review_required'])): ?>
                                                <span class="status missing_config">requiere_revision</span>
                                                <div class="muted">Corrige los articulos antes de enviar</div>
                                            <?php elseif (!empty($order['mapped'])): ?>
                                                <span class="status ready">lista</span>
                                            <?php else: ?>
                                                <span class="status missing_config">pendiente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (($order['sync_state'] ?? '') === 'confirmed'): ?>
                                                <span class="muted">Ya enviada</span>
                                            <?php elseif (($order['sync_state'] ?? '') === 'archived' && !empty($order['mapped']) && ($order['reference'] ?? '') !== ''): ?>
                                                <form class="inline-form" action="<?= $this->e($this->url('/sync/order')) ?>" method="post">
                                                    <input type="hidden" name="order_reference" value="<?= $this->e($order['reference']) ?>">
                                                    <input type="hidden" name="return_tab" value="jtl-orders">
                                                    <input type="hidden" name="force_resync" value="1">
                                                    <input type="hidden" name="resend_archived" value="1">
                                                    <button class="button small" type="submit">Reenviar a Packiyo</button>
                                                </form>
                                            <?php elseif (($order['sync_state'] ?? '') === 'local_only' && !empty($order['mapped']) && ($order['reference'] ?? '') !== ''): ?>
                                                <form class="inline-form" action="<?= $this->e($this->url('/sync/order')) ?>" method="post">
                                                    <input type="hidden" name="order_reference" value="<?= $this->e($order['reference']) ?>">
                                                    <input type="hidden" name="return_tab" value="jtl-orders">
                                                    <input type="hidden" name="force_resync" value="1">
                                                    <button class="button small" type="submit">Reenviar</button>
                                                </form>
                                            <?php elseif (($order['sync_state'] ?? '') === 'local_mapping' && ($order['reference'] ?? '') !== ''): ?>
                                                <a class="button secondary small button-link" href="<?= $this->e($this->url('/?') . http_build_query([
                                                    'tab' => 'jtl-orders',
                                                    'verify_packiyo_order' => $order['reference'],
                                                    'jtl_customer' => $customerFilter,
                                                    'jtl_mapped_customer' => $mappedCustomerFilter,
                                                ])) ?>">Verificar Packiyo</a>
                                            <?php elseif (empty($order['mapped'])): ?>
                                                <span class="muted">Mapear primero</span>
                                            <?php elseif (($order['sync_state'] ?? '') === 'unknown'): ?>
                                                <span class="muted">Recargar para verificar</span>
                                            <?php elseif (($order['reference'] ?? '') === ''): ?>
                                                <span class="muted">Sin ID</span>
                                            <?php else: ?>
                                                <form class="inline-form" action="<?= $this->e($this->url('/sync/order')) ?>" method="post">
                                                    <input type="hidden" name="order_reference" value="<?= $this->e($order['reference']) ?>">
                                                    <input type="hidden" name="return_tab" value="jtl-orders">
                                                    <button class="button small" type="submit">Enviar a Packiyo</button>
                                                </form>
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

    /**
     * @param array<int, array<string, mixed>> $workerSyncs
     * @param array<string, mixed>|null $workerStatus
     */
    private function renderJtlWorkerPanel(array $workerSyncs, ?array $workerStatus, ?string $error): string
    {
        ob_start();
        ?>
            <div class="worker-panel">
                <h3>JTL Worker 2.0</h3>

                <div class="details">
                    <div class="detail">
                        <span>Estado API Worker</span>
                        <strong><?= $this->e($this->workerStatusLabel($workerStatus)) ?></strong>
                    </div>
                    <div class="detail">
                        <span>Lectura desde API</span>
                        <strong><?= (bool) Config::get('jtl.worker_discovery_enabled', false) ? 'activa' : 'manual' ?></strong>
                    </div>
                    <div class="detail">
                        <span>Syncs leidos</span>
                        <strong><?= $this->e((string) count($workerSyncs)) ?></strong>
                    </div>
                    <div class="detail">
                        <span>Automatizacion app</span>
                        <strong>/automation/tick</strong>
                    </div>
                </div>

                <div class="notice">El marketplace abgleich ya no se inicia desde esta app. JTL Worker 2.0 debe quedar corriendo en la PC/servidor de JTL; despues esta app lee las ordenes nuevas desde JTL, las envia a Packiyo y devuelve tracking a JTL con el cron.</div>

                <ul class="worker-checklist">
                    <li>En JTL-Wawi abre Admin -> JTL-Worker-Status.</li>
                    <li>Activa el abgleich del marketplace/tienda, por ejemplo Temu EsSo, con intervalo minimo de 5 minutos.</li>
                    <li>Presiona Starten y luego Speichern para que el Worker siga ejecutandose.</li>
                    <li>Configura el cron de la app contra /automation/tick para procesar ordenes y fulfillment automaticamente.</li>
                </ul>

                <?php if ($error !== null): ?>
                    <div class="empty">No se pudo leer el estado del worker por API: <?= $this->e($error) ?></div>
                    <?php if ($this->looksLikeForbiddenWorkerError($error)): ?>
                        <div class="notice">JTL respondio 403 para Worker. El API token actual probablemente no tiene los scopes <strong>worker.getworkersyncs</strong> y <strong>system.worker.read</strong>. Guarda ajustes y registra la app de nuevo en JTL-Wawi para generar un token nuevo.</div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!(bool) Config::get('jtl.worker_discovery_enabled', false)): ?>
                    <div class="field-hint">La lectura automatica de Worker esta desactivada. Esto esta bien si el Worker ya corre en JTL; usa el boton solo como diagnostico opcional.</div>
                <?php endif; ?>

                <div class="jtl-worker-actions">
                    <form class="inline-form" action="<?= $this->e($this->url('/jtl/workers/discover')) ?>" method="post">
                        <button class="button secondary" type="submit">Leer estado Worker</button>
                    </form>
                    <a class="button secondary button-link" href="<?= $this->e($this->tabUrl('jtl-orders')) ?>">Recargar ordenes JTL</a>
                </div>

                <?php if ($workerSyncs !== []): ?>
                    <div class="field-hint">Syncs raw: <?= $this->e($this->shortJson(['items' => $workerSyncs], 420)) ?></div>
                <?php endif; ?>

                <?php if ($workerStatus !== null && $workerStatus !== []): ?>
                    <div class="field-hint">Status raw: <?= $this->e($this->shortJson($workerStatus)) ?></div>
                <?php endif; ?>
            </div>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed>|null $status */
    private function workerStatusLabel(?array $status): string
    {
        if ($status === null) {
            return 'sin_estado';
        }

        if ($status === []) {
            return 'sin_datos';
        }

        $running = Arr::firstScalar($status, ['isRunning', 'IsRunning', 'running', 'Running']);

        if ($running !== null) {
            return in_array(strtolower($running), ['1', 'true', 'yes'], true) ? 'running' : 'idle';
        }

        return Arr::firstScalar($status, ['status', 'Status', 'state', 'State', 'workerStatus', 'WorkerStatus'])
            ?? $this->shortJson($status, 80);
    }

    private function looksLikeForbiddenWorkerError(string $error): bool
    {
        return str_contains($error, 'HTTP 403');
    }
}
