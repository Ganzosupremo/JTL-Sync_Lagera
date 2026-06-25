<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Clients\JtlClient;
use App\Clients\PackiyoClient;
use App\Models\AppSyncState;
use App\Models\JtlApiCredential;
use App\Models\JtlOrderSource;
use App\Models\OrderMapping;
use App\Models\PackiyoCustomer;
use App\Models\PackiyoCustomerMapping;
use App\Models\SyncLog;
use App\Support\Database;

final class DashboardController
{
    public function index(): void
    {
        Database::migrate();

        $mappings = new OrderMapping();
        $logs = new SyncLog();
        $credentials = new JtlApiCredential();
        $orderSources = new JtlOrderSource();
        $customerMappings = new PackiyoCustomerMapping();
        $packiyoCustomers = new PackiyoCustomer();
        $syncStates = new AppSyncState();
        $jtl = new JtlClient();
        $packiyo = new PackiyoClient();
        $registration = $credentials->latest();

        $summary = [
            'last_sync' => $mappings->lastSyncedAt() ?? '-',
            'synced_today' => $mappings->countSyncedToday(),
            'errors_today' => $logs->countErrorsToday(),
            'jtl_status' => $jtl->status(),
            'packiyo_status' => $packiyo->status(),
        ];

        header('Content-Type: text/html; charset=UTF-8');
        echo $this->render(
            $this->activeTab($_GET['tab'] ?? 'overview'),
            $summary,
            $registration,
            $orderSources->all(),
            $customerMappings->all(),
            $packiyoCustomers->counts(),
            $packiyoCustomers->listByActive(true),
            $packiyoCustomers->listByActive(false),
            $syncStates->get('packiyo_customers'),
            $mappings->recent(50),
            $logs->recent(100),
            $_GET['notice'] ?? $_GET['sync'] ?? null
        );
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed>|null $registration
     * @param array<int, array<string, mixed>> $orderSources
     * @param array<int, array<string, mixed>> $customerMappings
     * @param array{active: int, inactive: int, total: int} $customerCounts
     * @param array<int, array<string, mixed>> $activeCustomers
     * @param array<int, array<string, mixed>> $inactiveCustomers
     * @param array<string, mixed>|null $customerSyncState
     * @param array<int, array<string, mixed>> $mappings
     * @param array<int, array<string, mixed>> $logs
     */
    private function render(
        string $tab,
        array $summary,
        ?array $registration,
        array $orderSources,
        array $customerMappings,
        array $customerCounts,
        array $activeCustomers,
        array $inactiveCustomers,
        ?array $customerSyncState,
        array $mappings,
        array $logs,
        mixed $notice
    ): string {
        ob_start();
        ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lagera JTL Sync</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6f7f9;
            --panel: #ffffff;
            --text: #1b1f24;
            --muted: #667085;
            --line: #d9dee7;
            --accent: #2563eb;
            --ok: #16803c;
            --warn: #a16207;
            --bad: #b42318;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
            line-height: 1.45;
        }

        .shell {
            width: min(1180px, calc(100% - 32px));
            margin: 0 auto;
            padding: 28px 0 48px;
        }

        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
        }

        h1, h2, h3 {
            margin: 0;
            letter-spacing: 0;
        }

        h1 {
            font-size: 24px;
        }

        h2 {
            font-size: 16px;
        }

        h3 {
            font-size: 14px;
            margin: 18px 0 8px;
        }

        .subtitle {
            margin: 4px 0 0;
            color: var(--muted);
        }

        .button {
            border: 0;
            border-radius: 6px;
            background: var(--accent);
            color: #fff;
            cursor: pointer;
            font-weight: 700;
            min-height: 40px;
            padding: 0 16px;
            white-space: nowrap;
        }

        .button.secondary {
            background: #263241;
        }

        .button.danger {
            background: var(--bad);
        }

        .button.small {
            min-height: 34px;
            padding: 0 12px;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .notice {
            border: 1px solid #b9d3ff;
            background: #edf5ff;
            border-radius: 6px;
            margin-bottom: 16px;
            padding: 10px 12px;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .metric, section {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
        }

        .metric {
            min-height: 86px;
            padding: 14px;
        }

        .metric span {
            color: var(--muted);
            display: block;
            font-size: 12px;
            margin-bottom: 8px;
        }

        .metric strong {
            display: block;
            font-size: 18px;
            overflow-wrap: anywhere;
        }

        .status {
            border-radius: 999px;
            display: inline-block;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 8px;
        }

        .status.configured,
        .status.active {
            background: #e7f6ec;
            color: var(--ok);
        }

        .status.inactive {
            background: #f3f4f6;
            color: #475467;
        }

        .status.missing_config {
            background: #fff4df;
            color: var(--warn);
        }

        .status.registration_pending {
            background: #edf5ff;
            color: var(--accent);
        }

        .tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 0 0 18px;
        }

        .tab {
            border: 1px solid var(--line);
            border-radius: 6px;
            color: var(--text);
            background: #fff;
            font-weight: 700;
            min-height: 40px;
            padding: 10px 14px;
            text-decoration: none;
        }

        .tab.active {
            border-color: var(--accent);
            color: var(--accent);
        }

        .grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 18px;
        }

        section {
            overflow: hidden;
        }

        .section-head {
            align-items: center;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 16px;
        }

        .section-body {
            padding: 14px 16px 16px;
        }

        .details {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }

        .detail span {
            color: var(--muted);
            display: block;
            font-size: 12px;
            margin-bottom: 4px;
        }

        .detail strong {
            display: block;
            overflow-wrap: anywhere;
        }

        .mapping-form {
            display: grid;
            grid-template-columns: minmax(130px, 160px) minmax(180px, 1fr) minmax(140px, 180px) minmax(180px, 1fr) 90px auto;
            gap: 10px;
            margin-bottom: 14px;
        }

        .inline-form.mapping-form {
            grid-template-columns: minmax(150px, 1fr) minmax(140px, 1fr) 80px auto;
            margin-bottom: 0;
            min-width: 520px;
        }

        .manual-order-form {
            display: grid;
            grid-template-columns: minmax(220px, 320px) auto;
            gap: 10px;
            margin-bottom: 14px;
        }

        input, select {
            border: 1px solid var(--line);
            border-radius: 6px;
            font: inherit;
            min-height: 40px;
            padding: 0 10px;
            width: 100%;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th, td {
            border-bottom: 1px solid var(--line);
            padding: 10px 16px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #fbfcfe;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        .empty {
            color: var(--muted);
            padding: 16px;
        }

        .inline-form {
            margin: 0;
        }

        .muted {
            color: var(--muted);
        }

        .level-error {
            color: var(--bad);
            font-weight: 700;
        }

        .level-warning {
            color: var(--warn);
            font-weight: 700;
        }

        .level-info {
            color: var(--ok);
            font-weight: 700;
        }

        @media (max-width: 900px) {
            .summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .details {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .mapping-form {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            header {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        @media (max-width: 620px) {
            .shell {
                width: min(100% - 20px, 1180px);
                padding-top: 18px;
            }

            .summary, .details, .mapping-form, .manual-order-form {
                grid-template-columns: 1fr;
            }

            .section-head {
                align-items: flex-start;
                flex-direction: column;
            }

            table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <main class="shell">
        <header>
            <div>
                <h1>Lagera JTL Sync</h1>
                <p class="subtitle">JTL -> Packiyo</p>
            </div>
            <form action="<?= $this->e($this->url('/sync')) ?>" method="post">
                <button class="button" type="submit">Sincronizar ahora</button>
            </form>
        </header>

        <?php if (is_string($notice) && $notice !== ''): ?>
            <div class="notice"><?= $this->e($notice) ?></div>
        <?php endif; ?>

        <div class="summary">
            <div class="metric">
                <span>Ultima sincronizacion</span>
                <strong><?= $this->e($summary['last_sync']) ?></strong>
            </div>
            <div class="metric">
                <span>Pedidos hoy</span>
                <strong><?= $this->e($summary['synced_today']) ?></strong>
            </div>
            <div class="metric">
                <span>Errores hoy</span>
                <strong><?= $this->e($summary['errors_today']) ?></strong>
            </div>
            <div class="metric">
                <span>API JTL</span>
                <strong><span class="status <?= $this->e($summary['jtl_status']) ?>"><?= $this->e($summary['jtl_status']) ?></span></strong>
            </div>
            <div class="metric">
                <span>API Packiyo</span>
                <strong><span class="status <?= $this->e($summary['packiyo_status']) ?>"><?= $this->e($summary['packiyo_status']) ?></span></strong>
            </div>
        </div>

        <nav class="tabs" aria-label="Dashboard">
            <a class="tab <?= $tab === 'overview' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('overview')) ?>">Resumen</a>
            <a class="tab <?= $tab === 'packiyo-customers' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('packiyo-customers')) ?>">Clientes Packiyo</a>
            <a class="tab <?= $tab === 'customer-mappings' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('customer-mappings')) ?>">Mapeos</a>
            <a class="tab <?= $tab === 'logs' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('logs')) ?>">Logs</a>
        </nav>

        <div class="grid">
            <?php if ($tab === 'overview'): ?>
                <?= $this->renderRegistration($registration) ?>
                <?= $this->renderOrders($mappings) ?>
            <?php endif; ?>

            <?php if ($tab === 'packiyo-customers'): ?>
                <?= $this->renderPackiyoCustomers($customerCounts, $activeCustomers, $inactiveCustomers, $customerSyncState) ?>
            <?php endif; ?>

            <?php if ($tab === 'customer-mappings'): ?>
                <?= $this->renderCustomerMappings($orderSources, $customerMappings, $activeCustomers) ?>
            <?php endif; ?>

            <?php if ($tab === 'logs'): ?>
                <?= $this->renderLogs($logs) ?>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed>|null $registration */
    private function renderRegistration(?array $registration): string
    {
        ob_start();
        ?>
            <section>
                <div class="section-head">
                    <h2>Registro JTL-Wawi</h2>
                </div>
                <div class="section-body">
                    <div class="details">
                        <div class="detail">
                            <span>Estado</span>
                            <strong><span class="status <?= $this->e($this->registrationStatus($registration)) ?>"><?= $this->e($this->registrationStatus($registration)) ?></span></strong>
                        </div>
                        <div class="detail">
                            <span>Request ID</span>
                            <strong><?= $this->e($registration['registration_request_id'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Endpoint</span>
                            <strong><?= $this->e($registration['authentication_endpoint'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>API version</span>
                            <strong><?= $this->e($registration['api_version'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Solicitado</span>
                            <strong><?= $this->e($registration['requested_at'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Aprobado</span>
                            <strong><?= $this->e($registration['approved_at'] ?? '-') ?></strong>
                        </div>
                    </div>

                    <div class="actions">
                        <?php if ($this->registrationStatus($registration) !== 'registration_pending'): ?>
                            <form action="<?= $this->e($this->url('/jtl/register')) ?>" method="post">
                                <button class="button" type="submit"><?= $this->e($this->registrationActionLabel($registration)) ?></button>
                            </form>
                        <?php endif; ?>

                        <?php if ($this->registrationStatus($registration) === 'registration_pending'): ?>
                            <form action="<?= $this->e($this->url('/jtl/register/complete')) ?>" method="post">
                                <button class="button" type="submit">Obtener API token</button>
                            </form>
                            <form action="<?= $this->e($this->url('/jtl/register')) ?>" method="post">
                                <button class="button secondary" type="submit">Reiniciar registro</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param array{active: int, inactive: int, total: int} $customerCounts
     * @param array<int, array<string, mixed>> $activeCustomers
     * @param array<int, array<string, mixed>> $inactiveCustomers
     * @param array<string, mixed>|null $customerSyncState
     */
    private function renderPackiyoCustomers(
        array $customerCounts,
        array $activeCustomers,
        array $inactiveCustomers,
        ?array $customerSyncState
    ): string {
        ob_start();
        ?>
            <section>
                <div class="section-head">
                    <h2>Clientes Packiyo</h2>
                    <form action="<?= $this->e($this->url('/packiyo/customers/sync')) ?>" method="post">
                        <button class="button" type="submit">Actualizar desde Packiyo</button>
                    </form>
                </div>
                <div class="section-body">
                    <div class="details">
                        <div class="detail">
                            <span>Total cacheado</span>
                            <strong><?= $this->e($customerCounts['total']) ?></strong>
                        </div>
                        <div class="detail">
                            <span>Activos</span>
                            <strong><?= $this->e($customerCounts['active']) ?></strong>
                        </div>
                        <div class="detail">
                            <span>Inactivos</span>
                            <strong><?= $this->e($customerCounts['inactive']) ?></strong>
                        </div>
                        <div class="detail">
                            <span>Ultimo cambio leido</span>
                            <strong><?= $this->e($customerSyncState['last_synced_at'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Ultima corrida</span>
                            <strong><?= $this->e($customerSyncState['last_success_at'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Estado</span>
                            <strong><?= $this->e($customerSyncState['last_message'] ?? '-') ?></strong>
                        </div>
                    </div>

                    <h3>Clientes activos</h3>
                    <?= $this->renderCustomerTable($activeCustomers, true) ?>

                    <h3>Clientes inactivos</h3>
                    <?= $this->renderCustomerTable($inactiveCustomers, false) ?>
                </div>
            </section>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param array<int, array<string, mixed>> $customers
     */
    private function renderCustomerTable(array $customers, bool $active): string
    {
        if ($customers === []) {
            return '<div class="empty">' . ($active ? 'Sin clientes activos cacheados.' : 'Sin clientes inactivos.') . '</div>';
        }

        ob_start();
        ?>
            <table>
                <thead>
                    <tr>
                        <th>Customer ID</th>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Packiyo updated</th>
                        <th>Cache</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td><strong><?= $this->e($customer['packiyo_customer_id'] ?? '-') ?></strong></td>
                            <td><?= $this->e($this->customerDisplayName($customer)) ?></td>
                            <td><?= $this->e($customer['email'] ?? '-') ?></td>
                            <td><?= $this->e($customer['packiyo_updated_at'] ?? '-') ?></td>
                            <td><?= $this->e($customer['synced_at'] ?? '-') ?></td>
                            <td><span class="status <?= $active ? 'active' : 'inactive' ?>"><?= $active ? 'active' : 'inactive' ?></span></td>
                            <td>
                                <form class="inline-form" action="<?= $this->e($this->url($active ? '/packiyo/customers/deactivate' : '/packiyo/customers/activate')) ?>" method="post">
                                    <input type="hidden" name="customer_id" value="<?= $this->e($customer['packiyo_customer_id'] ?? '') ?>">
                                    <button class="button small <?= $active ? 'danger' : '' ?>" type="submit"><?= $active ? 'Desactivar' : 'Activar' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param array<int, array<string, mixed>> $orderSources
     * @param array<int, array<string, mixed>> $customerMappings
     * @param array<int, array<string, mixed>> $activeCustomers
     */
    private function renderCustomerMappings(array $orderSources, array $customerMappings, array $activeCustomers): string
    {
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
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<int, array<string, mixed>> $mappings */
    private function renderOrders(array $mappings): string
    {
        ob_start();
        ?>
            <section>
                <div class="section-head">
                    <h2>Pedidos</h2>
                </div>
                <?php if ($mappings === []): ?>
                    <div class="empty">Sin pedidos sincronizados.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>JTL Order</th>
                                <th>Packiyo Order</th>
                                <th>Fecha</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mappings as $mapping): ?>
                                <tr>
                                    <td><?= $this->e($mapping['jtl_order_number'] ?: $mapping['jtl_order_id']) ?></td>
                                    <td><?= $this->e($mapping['packiyo_order_number'] ?: $mapping['packiyo_order_id']) ?></td>
                                    <td><?= $this->e($mapping['synced_at']) ?></td>
                                    <td>synced</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<int, array<string, mixed>> $logs */
    private function renderLogs(array $logs): string
    {
        ob_start();
        ?>
            <section>
                <div class="section-head">
                    <h2>Logs</h2>
                </div>
                <?php if ($logs === []): ?>
                    <div class="empty">Sin logs.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Nivel</th>
                                <th>Mensaje</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?= $this->e($log['created_at']) ?></td>
                                    <td class="level-<?= $this->e($log['level']) ?>"><?= $this->e($log['level']) ?></td>
                                    <td><?= $this->e($log['message']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php

        return (string) ob_get_clean();
    }

    private function activeTab(mixed $tab): string
    {
        $tab = is_string($tab) ? $tab : 'overview';
        $allowed = ['overview', 'packiyo-customers', 'customer-mappings', 'logs'];

        return in_array($tab, $allowed, true) ? $tab : 'overview';
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

    private function customerDisplayName(array $customer): string
    {
        foreach (['name', 'company_name', 'email', 'packiyo_customer_id'] as $key) {
            $value = $customer[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return '-';
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /** @param array<string, mixed>|null $registration */
    private function registrationStatus(?array $registration): string
    {
        if ($registration === null) {
            return 'missing_config';
        }

        if (($registration['status'] ?? '') === 'approved' && $this->hasUsableApiKey($registration)) {
            return 'configured';
        }

        if (($registration['status'] ?? '') === 'pending' || !$this->hasUsableApiKey($registration)) {
            return 'registration_pending';
        }

        return 'missing_config';
    }

    /** @param array<string, mixed>|null $registration */
    private function registrationActionLabel(?array $registration): string
    {
        return $this->registrationStatus($registration) === 'configured'
            ? 'Registrar de nuevo'
            : 'Registrar app en JTL';
    }

    /** @param array<string, mixed>|null $registration */
    private function hasUsableApiKey(?array $registration): bool
    {
        $apiKey = $registration['api_key'] ?? null;

        return is_string($apiKey) && trim($apiKey) !== '' && $apiKey !== 'Array';
    }

    private function tabUrl(string $tab): string
    {
        return $this->url('/') . '?tab=' . rawurlencode($tab);
    }

    private function url(string $path): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

        return $base . '/' . ltrim($path, '/');
    }
}
