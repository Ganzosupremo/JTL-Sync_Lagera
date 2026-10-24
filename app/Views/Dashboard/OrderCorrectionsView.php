<?php

declare(strict_types=1);

namespace App\Views\Dashboard;

use App\Services\OrderCorrectionService;
use App\Views\View;

final class OrderCorrectionsView extends View
{
    /** @param array<string, mixed> $data */
    public function render(array $data, ?string $error): string
    {
        $job = is_array($data['job'] ?? null) ? $data['job'] : null;
        $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
        $correctionSummary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
        $catalogs = is_array($data['catalogs'] ?? null) ? $data['catalogs'] : [];
        $activeCustomers = is_array($data['active_customers'] ?? null) ? $data['active_customers'] : [];
        $selectedCustomerIds = is_array($data['selected_customer_ids'] ?? null) ? $data['selected_customer_ids'] : [];
        $defaultCustomerIds = $selectedCustomerIds !== []
            ? $selectedCustomerIds
            : array_values(array_filter(array_map(
                static fn (array $customer): string => trim((string) ($customer['packiyo_customer_id'] ?? '')),
                $activeCustomers
            )));
        $defaultStartDate = date('Y-m-d', strtotime('-60 days'));
        $jobId = (string) ($job['id'] ?? '');
        $writeEnabled = !empty($data['write_enabled']);
        $testWriteEnabled = !empty($data['test_write_enabled']);
        $executionLimit = $writeEnabled ? 10 : 1;
        ob_start();
        ?>
            <section>
                <div class="section-head">
                    <div>
                        <h2>Correccion de ordenes</h2>
                        <div class="muted">Analiza Packiyo por lotes y prepara reemplazos seguros para lineas JTL-LINE-*. No modifica JTL.</div>
                    </div>
                </div>
                <?php if ($error !== null): ?>
                    <div class="notice"><strong>Error:</strong> <?= $this->e($error) ?></div>
                <?php endif; ?>
                <form method="post" action="<?= $this->e($this->url('/order-corrections/start')) ?>" class="correction-start-form">
                    <div>
                        <strong>Nuevo analisis</strong>
                        <div class="muted">Elige desde que fecha revisar las ordenes y los clientes activos incluidos.</div>
                    </div>
                    <label class="correction-start-date">Fecha de inicio
                        <input type="date" name="start_date" value="<?= $this->e($defaultStartDate) ?>" max="<?= $this->e(date('Y-m-d')) ?>" required>
                    </label>
                    <?php if ($activeCustomers === []): ?>
                        <div class="empty">No hay clientes Packiyo activos. Activa o sincroniza clientes antes de iniciar.</div>
                    <?php else: ?>
                        <div class="customer-check-grid">
                            <?php foreach ($activeCustomers as $customer): ?>
                                <?php $customerId = trim((string) ($customer['packiyo_customer_id'] ?? '')); ?>
                                <?php if ($customerId === '') { continue; } ?>
                                <label class="customer-check">
                                    <input type="checkbox" name="customer_ids[]" value="<?= $this->e($customerId) ?>" <?= in_array($customerId, $defaultCustomerIds, true) ? 'checked' : '' ?>>
                                    <span>
                                        <?= $this->e($this->customerDisplayName($customer)) ?>
                                        <small><?= $this->e($customerId) ?></small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="actions">
                            <button class="button secondary small" type="button" onclick="this.closest('form').querySelectorAll('input[name=&quot;customer_ids[]&quot;]').forEach(function(input){input.checked=true;})">Seleccionar todos</button>
                            <button class="button secondary small" type="button" onclick="this.closest('form').querySelectorAll('input[name=&quot;customer_ids[]&quot;]').forEach(function(input){input.checked=false;})">Quitar todos</button>
                            <button class="button" type="submit">Iniciar analisis</button>
                        </div>
                    <?php endif; ?>
                </form>
                <div class="notice">
                    <strong><?= $writeEnabled ? 'Escritura atomica habilitada' : ($testWriteEnabled ? 'Modo prueba: una orden' : 'Modo lectura/simulacion') ?></strong>.
                    <?php if ($writeEnabled): ?>
                        Cada orden se vuelve a leer, se valida y se verifica despues de escribir; se procesan como maximo diez por lote.
                    <?php elseif ($testWriteEnabled): ?>
                        Selecciona y previsualiza una sola orden. Si Packiyo confirma correctamente el reemplazo, la app habilitara automaticamente los lotes de hasta diez ordenes.
                    <?php else: ?>
                        Las acciones remotas estan bloqueadas porque falta un endpoint de correccion valido.
                    <?php endif; ?>
                </div>
                <?php if ($job === null): ?>
                    <div class="empty">Todavia no hay un trabajo de analisis.</div>
                <?php else: ?>
                    <div class="summary">
                        <div class="metric"><span>Estado</span><strong><?= $this->e($job['status']) ?></strong></div>
                        <div class="metric"><span>Ordenes revisadas</span><strong><?= $this->e($job['scanned_orders']) ?></strong></div>
                        <div class="metric"><span>Lineas JTL-LINE encontradas</span><strong><?= $this->e($job['detected_lines']) ?></strong></div>
                        <div class="metric"><span>Coincidencias con JTL</span><strong><?= $this->e($correctionSummary['jtl_matches'] ?? 0) ?></strong></div>
                        <div class="metric"><span>Producto Packiyo asignado</span><strong><?= $this->e($correctionSummary['packiyo_assignments'] ?? 0) ?></strong></div>
                        <div class="metric"><span>Clientes seleccionados</span><strong><?= $this->e($selectedCustomerIds === [] ? 'Todos' : count($selectedCustomerIds)) ?></strong></div>
                        <div class="metric"><span>Desde</span><strong><?= $this->e($job['window_start']) ?></strong></div>
                        <div class="metric"><span>Actualizado</span><strong><?= $this->e($job['updated_at']) ?></strong></div>
                    </div>
                    <?php if (($job['status'] ?? '') === 'running'): ?>
                        <div class="notice">
                            <strong>Analisis en curso.</strong>
                            Se revisaron <?= $this->e($job['scanned_orders']) ?> ordenes y se encontraron <?= $this->e($job['detected_lines']) ?> lineas JTL-LINE hasta ahora.
                            El resultado todavia no es definitivo; continua con el siguiente lote hasta que el estado sea completed.
                        </div>
                    <?php elseif (($job['status'] ?? '') === 'completed'): ?>
                        <div class="notice">
                            <strong>Analisis completado.</strong>
                            Se revisaron <?= $this->e($job['scanned_orders']) ?> ordenes, se encontraron <?= $this->e($job['detected_lines']) ?> lineas JTL-LINE y <?= $this->e($correctionSummary['jtl_matches'] ?? 0) ?> coincidencias con JTL.
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($job['last_error'])): ?>
                        <div class="notice"><strong>Ultimo error:</strong> <?= $this->e($job['last_error']) ?></div>
                    <?php endif; ?>
                    <div class="actions">
                        <?php if (($job['status'] ?? '') !== 'completed'): ?>
                            <form method="post" action="<?= $this->e($this->url('/order-corrections/continue')) ?>">
                                <input type="hidden" name="job_id" value="<?= $this->e($jobId) ?>">
                                <button class="button" type="submit">Continuar siguiente lote</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= $this->e($this->url('/order-corrections/preview')) ?>">
                            <input type="hidden" name="job_id" value="<?= $this->e($jobId) ?>">
                            <button class="button secondary" type="submit">Previsualizar todas las asignadas</button>
                        </form>
                        <form method="post" action="<?= $this->e($this->url('/order-corrections/execute')) ?>" data-correction-batch-form>
                            <input type="hidden" name="job_id" value="<?= $this->e($jobId) ?>">
                            <button class="button danger" type="submit" <?= $writeEnabled ? '' : 'disabled' ?>>Enviar siguiente batch a Packiyo (max. 10)</button>
                        </form>
                        <a class="button secondary" href="<?= $this->e($this->url('/order-corrections/export?') . http_build_query(['job_id' => $jobId])) ?>">Exportar CSV</a>
                    </div>
                    <form method="get" action="<?= $this->e($this->url('/')) ?>" class="filters">
                        <input type="hidden" name="tab" value="order-corrections">
                        <input type="hidden" name="correction_job" value="<?= $this->e($jobId) ?>">
                        <label>Cliente <input name="correction_customer" value="<?= $this->e($_GET['correction_customer'] ?? '') ?>"></label>
                        <label>Estado Packiyo <input name="correction_status" value="<?= $this->e($_GET['correction_status'] ?? '') ?>"></label>
                        <label>Fuente
                            <select name="correction_source">
                                <option value="">Todas</option>
                                <?php foreach (['live', 'local_copy', 'unavailable'] as $source): ?>
                                    <option value="<?= $this->e($source) ?>" <?= (string) ($_GET['correction_source'] ?? '') === $source ? 'selected' : '' ?>><?= $this->e($source) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Resultado <input name="correction_result" value="<?= $this->e($_GET['correction_result'] ?? '') ?>"></label>
                        <button class="button small" type="submit">Filtrar</button>
                    </form>
                    <?php if ($lines === []): ?>
                        <div class="empty">
                            <?php if (($job['status'] ?? '') === 'running'): ?>
                                Aun no se encontraron lineas JTL-LINE en las ordenes revisadas. El analisis sigue en curso.
                            <?php elseif ((int) ($job['detected_lines'] ?? 0) === 0): ?>
                                El analisis termino sin encontrar lineas JTL-LINE en el periodo seleccionado.
                            <?php else: ?>
                                No hay lineas que coincidan con los filtros actuales.
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php
                            $orderSelectionStates = [];
                            foreach ($lines as $candidateLine) {
                                $candidateOrderId = (string) ($candidateLine['packiyo_order_id'] ?? '');
                                if ($candidateOrderId === '') { continue; }
                                $state = $orderSelectionStates[$candidateOrderId] ?? ['editable' => true, 'pending' => false, 'cancelled_line' => false];
                                $snapshotItems = is_array($candidateLine['current_snapshot'] ?? null) ? $candidateLine['current_snapshot'] : [];
                                $snapshotIndex = (int) ($candidateLine['line_index'] ?? -1);
                                $cancelledLine = isset($snapshotItems[$snapshotIndex])
                                    && is_array($snapshotItems[$snapshotIndex])
                                    && OrderCorrectionService::isCancelledLineItem($snapshotItems[$snapshotIndex]);
                                $state['editable'] = $state['editable']
                                    && OrderCorrectionService::isEditableStatus((string) ($candidateLine['packiyo_status'] ?? ''));
                                $state['pending'] = $state['pending']
                                    || (!$cancelledLine && !OrderCorrectionService::isFinalCorrectionResult((string) ($candidateLine['result'] ?? '')));
                                $state['cancelled_line'] = $state['cancelled_line'] || $cancelledLine;
                                $orderSelectionStates[$candidateOrderId] = $state;
                            }
                        ?>
                        <form id="correction-selected-orders-form" method="post" action="<?= $this->e($this->url('/order-corrections/preview')) ?>" class="correction-selection-bar" data-correction-selected-form data-correction-max-orders="<?= $this->e($executionLimit) ?>">
                            <input type="hidden" name="job_id" value="<?= $this->e($jobId) ?>">
                            <input type="hidden" name="selection_mode" value="orders">
                            <strong>Ordenes seleccionadas</strong>
                            <span class="selection-count" data-correction-selection-count>0 orden(es) seleccionada(s)</span>
                            <button class="button secondary small" type="submit">Previsualizar seleccionadas</button>
                            <button class="button danger small" type="submit" formaction="<?= $this->e($this->url('/order-corrections/execute')) ?>" data-correction-execute <?= ($writeEnabled || $testWriteEnabled) ? '' : 'disabled' ?>><?= $writeEnabled ? 'Corregir seleccionadas en Packiyo' : 'Probar correccion de una orden' ?></button>
                        </form>
                        <div class="scroll-table order-table-scroll">
                            <table data-sort-table="order-corrections">
                                <thead>
                                    <tr>
                                        <th><input class="order-checkbox" type="checkbox" data-correction-select-all aria-label="Seleccionar todas las ordenes visibles"></th>
                                        <th>Orden</th><th>Cliente / estado</th><th>Linea actual</th><th>JTL</th>
                                        <th>Cantidad / precio</th><th>Producto Packiyo</th><th>Resultado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php $renderedOrderIds = []; ?>
                                <?php foreach ($lines as $line): ?>
                                    <?php
                                        $customerId = (string) ($line['packiyo_customer_id'] ?? '');
                                        $orderId = (string) ($line['packiyo_order_id'] ?? '');
                                        $showOrderCheckbox = $orderId !== '' && !isset($renderedOrderIds[$orderId]);
                                        $orderState = $orderSelectionStates[$orderId] ?? ['editable' => false, 'pending' => false, 'cancelled_line' => false];
                                        $orderSelectable = $showOrderCheckbox && $orderState['editable'] && $orderState['pending'];
                                        $orderDisabledReason = $orderState['cancelled_line']
                                            ? 'La linea JTL-LINE esta cancelada y la orden ya fue corregida manualmente'
                                            : (!$orderState['editable']
                                            ? 'El estado de esta orden no permite modificarla'
                                            : 'Esta orden ya fue corregida; cambia el producto asignado para habilitarla nuevamente');
                                        $renderedOrderIds[$orderId] = true;
                                        $catalog = is_array($catalogs[$customerId] ?? null) ? $catalogs[$customerId] : [];
                                        $suggestions = is_array($line['suggestions'] ?? null) ? $line['suggestions'] : [];
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ($showOrderCheckbox): ?>
                                                <input class="order-checkbox" type="checkbox" name="order_ids[]" value="<?= $this->e($orderId) ?>" form="correction-selected-orders-form" data-correction-order-checkbox aria-label="Seleccionar orden <?= $this->e($line['packiyo_order_number'] ?: $orderId) ?>" <?= $orderSelectable ? '' : 'disabled title="' . $this->e($orderDisabledReason) . '"' ?>>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= $this->e($line['packiyo_order_number'] ?: $line['packiyo_order_id']) ?></strong>
                                            <div class="muted">Packiyo <?= $this->e($line['packiyo_order_id']) ?></div>
                                            <div class="muted">JTL <?= $this->e($line['jtl_order_number'] ?: $line['jtl_order_id'] ?: '-') ?></div>
                                        </td>
                                        <td><?= $this->e($customerId ?: '-') ?><div><span class="status <?= $this->e($line['packiyo_status']) ?>"><?= $this->e($line['packiyo_status'] ?: 'desconocido') ?></span></div></td>
                                        <td><strong><?= $this->e($line['original_sku']) ?></strong><div class="muted"><?= $this->e($line['original_name']) ?></div><div class="muted">external_id: <?= $this->e($line['original_external_id'] ?: '-') ?></div></td>
                                        <td>
                                            <?= $this->e($line['jtl_name'] ?: 'Sin coincidencia') ?>
                                            <div class="muted">SKU: <?= $this->e($line['jtl_sku'] ?: '-') ?></div>
                                            <div><span class="status <?= $line['jtl_source'] === 'live' ? 'synced' : 'missing_config' ?>"><?= $this->e($line['jtl_source']) ?></span></div>
                                            <?php if ($line['jtl_source'] === 'local_copy'): ?><div class="muted">Copia local no actualizada.</div><?php endif; ?>
                                        </td>
                                        <td><?= $this->e($line['quantity']) ?><div class="muted"><?= $this->e($line['price']) ?></div></td>
                                        <td>
                                            <form method="post" action="<?= $this->e($this->url('/order-corrections/assign')) ?>">
                                                <input type="hidden" name="job_id" value="<?= $this->e($jobId) ?>">
                                                <input type="hidden" name="line_ids[]" value="<?= $this->e($line['id']) ?>">
                                                <select name="product_id" required>
                                                    <option value="">Seleccionar producto...</option>
                                                    <?php foreach ($catalog as $product): ?>
                                                        <option value="<?= $this->e($product['id']) ?>" <?= (string) $line['proposed_product_id'] === (string) $product['id'] ? 'selected' : '' ?>>
                                                            <?= $this->e($product['sku'] . ' — ' . $product['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="actions">
                                                    <button class="button small" name="scope" value="line">Solo esta linea</button>
                                                    <button class="button secondary small" name="scope" value="group">Todas las coincidencias</button>
                                                </div>
                                            </form>
                                            <?php if ($suggestions !== []): ?>
                                                <div class="muted">Sugerencias: <?= $this->e(implode(', ', array_map(static fn (array $row): string => (string) ($row['sku'] ?? ''), $suggestions))) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="status <?= $this->e($line['result']) ?>"><?= $this->e($line['result']) ?></span><?php if (!empty($line['error'])): ?><div class="muted"><?= $this->e($line['error']) ?></div><?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php
        return (string) ob_get_clean();
    }
}
