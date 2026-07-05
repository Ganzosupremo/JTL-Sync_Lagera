<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Clients\JtlClient;
use App\Clients\PackiyoClient;
use App\Models\AppSyncState;
use App\Models\AppUser;
use App\Models\FulfillmentSync;
use App\Models\JtlApiCredential;
use App\Models\JtlOrderSource;
use App\Models\OrderMapping;
use App\Models\PackiyoCustomer;
use App\Models\PackiyoCustomerMapping;
use App\Models\ProductSkuAlias;
use App\Models\SyncLog;
use App\Models\UserInvitation;
use App\Services\MappingService;
use App\Services\PackiyoCustomerResolver;
use App\Services\ProductImportService;
use App\Services\ProductSkuAliasService;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\JtlScopeList;
use App\Support\Setting;
use App\Support\SettingsCatalog;

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
        $fulfillmentSyncs = new FulfillmentSync();
        $syncStates = new AppSyncState();
        $jtl = new JtlClient();
        $packiyo = new PackiyoClient();
        $registration = $credentials->latest();
        $tab = $this->activeTab($_GET['tab'] ?? 'overview');
        $jtlOrders = [];
        $jtlOrdersError = null;
        $jtlWorkerSyncs = [];
        $jtlWorkerStatus = null;
        $jtlWorkerError = null;
        $productRows = [];
        $productImportError = null;
        $productSkuAliasRows = [];
        $productSkuAliasProducts = [];
        $productSkuAliasError = null;
        $jtlOrderCustomerFilter = is_scalar($_GET['jtl_customer'] ?? null) ? trim((string) $_GET['jtl_customer']) : '';
        $jtlOrderMappedCustomerFilter = is_scalar($_GET['jtl_mapped_customer'] ?? null) ? trim((string) $_GET['jtl_mapped_customer']) : '';
        $selectedProductCustomerId = is_scalar($_GET['customer_id'] ?? null) ? (string) $_GET['customer_id'] : '';
        $selectedSkuAliasCustomerId = is_scalar($_GET['sku_customer_id'] ?? null) ? (string) $_GET['sku_customer_id'] : '';
        $productImportCategoryId = is_scalar($_GET['category_id'] ?? null)
            ? (string) $_GET['category_id']
            : (string) Config::get('jtl.product_import_category_id', '');
        $productImportWarehouseId = is_scalar($_GET['warehouse_id'] ?? null)
            ? (string) $_GET['warehouse_id']
            : (string) Config::get('jtl.product_import_warehouse_id', '');

        $summary = [
            'last_sync' => $mappings->lastSyncedAt() ?? '-',
            'synced_today' => $mappings->countSyncedToday(),
            'errors_today' => $logs->countErrorsToday(),
            'jtl_status' => $jtl->status(),
            'packiyo_status' => $packiyo->status(),
        ];

        if ($tab === 'jtl-orders') {
            $jtlWorkerSyncs = $this->cachedWorkerSyncs();

            if ((bool) Config::get('jtl.worker_discovery_enabled', false)) {
                try {
                    $jtlWorkerSyncs = $jtl->getWorkerSyncs();
                } catch (\Throwable $exception) {
                    $jtlWorkerError = 'Worker syncs: ' . $exception->getMessage();
                }

                try {
                    $jtlWorkerStatus = $jtl->getWorkerStatus();
                } catch (\Throwable $exception) {
                    $prefix = $jtlWorkerError !== null ? $jtlWorkerError . ' | ' : '';
                    $jtlWorkerError = $prefix . 'Worker status: ' . $exception->getMessage();
                }
            }

            try {
                $jtlOrders = $this->filterJtlOrderRows(
                    $this->jtlOrderRows($jtl->getOrders(), $customerMappings, $mappings, $packiyo),
                    $jtlOrderCustomerFilter,
                    $jtlOrderMappedCustomerFilter
                );
            } catch (\Throwable $exception) {
                $jtlOrdersError = $exception->getMessage();
            }
        }

        if ($tab === 'products' && $selectedProductCustomerId !== '') {
            try {
                $productRows = (new ProductImportService())->preview($selectedProductCustomerId);
            } catch (\Throwable $exception) {
                $productImportError = $exception->getMessage();
            }
        }

        if ($tab === 'customer-mappings' && $selectedSkuAliasCustomerId !== '') {
            $productSkuAliasRows = (new ProductSkuAlias())->allForCustomer($selectedSkuAliasCustomerId);

            try {
                $productSkuAliasProducts = (new ProductSkuAliasService())->preview($selectedSkuAliasCustomerId);
            } catch (\Throwable $exception) {
                $productSkuAliasError = $exception->getMessage();
            }
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo $this->render(
            $tab,
            $summary,
            $registration,
            $orderSources->all(),
            $customerMappings->all(),
            $packiyoCustomers->counts(),
            $packiyoCustomers->listByActive(true),
            $packiyoCustomers->listByActive(false),
            $syncStates->get('packiyo_customers'),
            $syncStates->get('fulfillment_sync'),
            $fulfillmentSyncs->recent(50),
            $jtlOrders,
            $jtlOrdersError,
            $jtlWorkerSyncs,
            $jtlWorkerStatus,
            $jtlWorkerError,
            $jtlOrderCustomerFilter,
            $jtlOrderMappedCustomerFilter,
            $productSkuAliasRows,
            $productSkuAliasProducts,
            $productSkuAliasError,
            $selectedSkuAliasCustomerId,
            $productRows,
            $productImportError,
            $selectedProductCustomerId,
            $productImportCategoryId,
            $productImportWarehouseId,
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
     * @param array<string, mixed>|null $fulfillmentState
     * @param array<int, array<string, mixed>> $fulfillmentRows
     * @param array<int, array<string, mixed>> $jtlOrders
     * @param array<int, array<string, mixed>> $jtlWorkerSyncs
     * @param array<string, mixed>|null $jtlWorkerStatus
     * @param string $jtlOrderCustomerFilter
     * @param string $jtlOrderMappedCustomerFilter
     * @param array<int, array<string, mixed>> $productSkuAliasRows
     * @param array<int, array<string, mixed>> $productSkuAliasProducts
     * @param array<int, array<string, mixed>> $productRows
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
        ?array $fulfillmentState,
        array $fulfillmentRows,
        array $jtlOrders,
        ?string $jtlOrdersError,
        array $jtlWorkerSyncs,
        ?array $jtlWorkerStatus,
        ?string $jtlWorkerError,
        string $jtlOrderCustomerFilter,
        string $jtlOrderMappedCustomerFilter,
        array $productSkuAliasRows,
        array $productSkuAliasProducts,
        ?string $productSkuAliasError,
        string $selectedSkuAliasCustomerId,
        array $productRows,
        ?string $productImportError,
        string $selectedProductCustomerId,
        string $productImportCategoryId,
        string $productImportWarehouseId,
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

        .button:disabled {
            background: #98a2b3;
            cursor: not-allowed;
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

        .status.synced {
            background: #e7f6ec;
            color: var(--ok);
        }

        .status.ready {
            background: #edf5ff;
            color: var(--accent);
        }

        .status.archived {
            background: #fff4df;
            color: var(--warn);
        }

        .status.inactive {
            background: #f3f4f6;
            color: #475467;
        }

        .status.registration_cancelled {
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

        .invite-form {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) minmax(120px, 180px) auto;
            gap: 10px;
            margin-bottom: 14px;
        }

        .product-filter-form {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) minmax(150px, 190px) minmax(150px, 190px) auto;
            gap: 10px;
            margin-bottom: 14px;
        }

        .worker-panel {
            border-bottom: 1px solid var(--line);
            margin: -2px 0 14px;
            padding-bottom: 14px;
        }

        .jtl-worker-form {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) minmax(180px, 260px) auto auto;
            gap: 10px;
            margin-bottom: 8px;
        }

        .jtl-worker-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 10px 0;
        }

        .jtl-order-filter-form {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) minmax(220px, 1fr) auto auto;
            gap: 10px;
            margin-bottom: 14px;
        }

        .button-link {
            align-items: center;
            display: inline-flex;
            justify-content: center;
            text-decoration: none;
        }

        .sku-alias-filter-form {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) auto;
            gap: 10px;
            margin-bottom: 14px;
        }

        .sku-alias-form {
            display: grid;
            grid-template-columns: minmax(170px, 1fr) minmax(170px, 1fr) minmax(140px, 180px) minmax(160px, 1fr) auto;
            gap: 10px;
            margin-bottom: 14px;
        }

        .sku-alias-row-form {
            display: grid;
            grid-template-columns: minmax(150px, 1fr) auto;
            gap: 8px;
            margin-top: 8px;
        }

        .alias-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .alias-chip {
            background: #edf5ff;
            border-radius: 999px;
            color: var(--accent);
            display: inline-block;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 8px;
        }

        .scroll-table {
            border: 1px solid var(--line);
            border-radius: 6px;
            margin-bottom: 14px;
            max-height: min(620px, 62vh);
            overflow: auto;
        }

        .scroll-table table {
            min-width: 980px;
        }

        .scroll-table th {
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .sku-alias-saved-scroll {
            max-height: min(360px, 42vh);
        }

        input, select, textarea {
            border: 1px solid var(--line);
            border-radius: 6px;
            font: inherit;
            min-height: 40px;
            padding: 0 10px;
            width: 100%;
        }

        input[type="checkbox"] {
            min-height: 0;
            width: auto;
        }

        textarea {
            min-height: 82px;
            padding: 10px;
            resize: vertical;
        }

        label {
            color: var(--muted);
            display: block;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .settings-form {
            display: grid;
            gap: 18px;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .setting-field {
            min-width: 0;
        }

        .setting-field.full {
            grid-column: 1 / -1;
        }

        .field-hint {
            color: var(--muted);
            font-size: 12px;
            margin-top: 5px;
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

            .mapping-form,
            .settings-grid {
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

            .summary, .details, .mapping-form, .manual-order-form, .invite-form, .product-filter-form, .jtl-worker-form, .jtl-order-filter-form, .sku-alias-filter-form, .sku-alias-form, .sku-alias-row-form, .settings-grid {
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
            <div class="actions">
                <form action="<?= $this->e($this->url('/sync')) ?>" method="post">
                    <button class="button" type="submit">Sincronizar ahora</button>
                </form>
                <?php if ((new Auth())->enabled()): ?>
                    <form action="<?= $this->e($this->url('/logout')) ?>" method="post">
                        <button class="button secondary" type="submit">Cerrar sesion</button>
                    </form>
                <?php endif; ?>
            </div>
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
            <a class="tab <?= $tab === 'jtl-orders' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('jtl-orders')) ?>">Ordenes JTL</a>
            <a class="tab <?= $tab === 'fulfillment' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('fulfillment')) ?>">Fulfillment</a>
            <a class="tab <?= $tab === 'packiyo-customers' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('packiyo-customers')) ?>">Clientes Packiyo</a>
            <a class="tab <?= $tab === 'customer-mappings' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('customer-mappings')) ?>">Mapeos</a>
            <a class="tab <?= $tab === 'products' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('products')) ?>">Productos</a>
            <a class="tab <?= $tab === 'settings' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('settings')) ?>">Ajustes</a>
            <a class="tab <?= $tab === 'logs' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('logs')) ?>">Logs</a>
        </nav>

        <div class="grid">
            <?php if ($tab === 'overview'): ?>
                <?= $this->renderRegistration($registration) ?>
                <?= $this->renderOrders($mappings) ?>
            <?php endif; ?>

            <?php if ($tab === 'jtl-orders'): ?>
                <?= $this->renderJtlOrders(
                    $jtlOrders,
                    $jtlOrdersError,
                    $jtlWorkerSyncs,
                    $jtlWorkerStatus,
                    $jtlWorkerError,
                    $activeCustomers,
                    $jtlOrderCustomerFilter,
                    $jtlOrderMappedCustomerFilter
                ) ?>
            <?php endif; ?>

            <?php if ($tab === 'fulfillment'): ?>
                <?= $this->renderFulfillment($fulfillmentRows, $fulfillmentState) ?>
            <?php endif; ?>

            <?php if ($tab === 'packiyo-customers'): ?>
                <?= $this->renderPackiyoCustomers($customerCounts, $activeCustomers, $inactiveCustomers, $customerSyncState) ?>
            <?php endif; ?>

            <?php if ($tab === 'customer-mappings'): ?>
                <?= $this->renderCustomerMappings(
                    $orderSources,
                    $customerMappings,
                    $activeCustomers,
                    $productSkuAliasRows,
                    $productSkuAliasProducts,
                    $productSkuAliasError,
                    $selectedSkuAliasCustomerId
                ) ?>
            <?php endif; ?>

            <?php if ($tab === 'products'): ?>
                <?= $this->renderProducts($activeCustomers, $productRows, $productImportError, $selectedProductCustomerId, $productImportCategoryId, $productImportWarehouseId) ?>
            <?php endif; ?>

            <?php if ($tab === 'settings'): ?>
                <?= $this->renderSettings() ?>
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
                            <form action="<?= $this->e($this->url('/jtl/register/reset')) ?>" method="post">
                                <button class="button secondary" type="submit">Descartar pendiente local</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if ($this->registrationStatus($registration) === 'registration_pending'): ?>
                        <div class="field-hint">Si cancelaste la solicitud en JTL-Wawi o necesitas cambiar scopes, descarta la pendiente local y luego registra la app de nuevo.</div>
                    <?php endif; ?>
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
     * @param array<int, array<string, mixed>> $workerSyncs
     * @param array<string, mixed>|null $workerStatus
     */
    private function renderJtlWorkerPanel(array $workerSyncs, ?array $workerStatus, ?string $error): string
    {
        ob_start();
        ?>
            <div class="worker-panel">
                <h3>Marketplace Abgleich</h3>

                <div class="details">
                    <div class="detail">
                        <span>Worker</span>
                        <strong><?= $this->e($this->workerStatusLabel($workerStatus)) ?></strong>
                    </div>
                    <div class="detail">
                        <span>Syncs disponibles</span>
                        <strong><?= $this->e((string) count($workerSyncs)) ?></strong>
                    </div>
                    <div class="detail">
                        <span>Endpoint</span>
                        <strong><?= $this->e(Config::get('jtl.worker_sync_method', 'POST') . ' ' . Config::get('jtl.worker_endpoint', '/api/eazybusiness/v1/workers/{syncId}')) ?></strong>
                    </div>
                </div>

                <?php if ($error !== null): ?>
                    <div class="empty">No se pudo leer el estado del worker: <?= $this->e($error) ?></div>
                    <?php if ($this->looksLikeForbiddenWorkerError($error)): ?>
                        <div class="notice">JTL respondio 403 para Worker. El API token actual probablemente no tiene los scopes <strong>worker.getworkersyncs</strong> y <strong>system.worker.read</strong>. Guarda ajustes y registra la app de nuevo en JTL-Wawi para generar un token nuevo.</div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!(bool) Config::get('jtl.worker_discovery_enabled', false)): ?>
                    <div class="field-hint">La lectura automatica de Worker esta desactivada porque esta JTL API interpreta /workers como SyncId. Ingresa el Identifier UUID del WorkerSyncItem como Sync ID manual; no uses IDs numericos como 1 o 2.</div>
                <?php endif; ?>

                <div class="jtl-worker-actions">
                    <form class="inline-form" action="<?= $this->e($this->url('/jtl/workers/discover')) ?>" method="post">
                        <button class="button secondary" type="submit">Leer GET /workers</button>
                    </form>
                </div>

                <form class="jtl-worker-form" action="<?= $this->e($this->url('/jtl/workers/start')) ?>" method="post">
                    <select name="worker_sync_id">
                        <option value="">Seleccionar abgleich</option>
                        <?php foreach ($workerSyncs as $sync): ?>
                            <?php $syncId = $this->workerSyncId($sync); ?>
                            <?php if ($syncId === '') {
                                continue;
                            } ?>
                            <option value="<?= $this->e($syncId) ?>"><?= $this->e($this->workerSyncLabel($sync)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input name="worker_sync_id_manual" placeholder="Identifier UUID manual" pattern="[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}">
                    <button class="button" type="submit">Iniciar abgleich</button>
                    <a class="button secondary button-link" href="<?= $this->e($this->tabUrl('jtl-orders')) ?>">Actualizar estado</a>
                </form>

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

    /**
     * @param array<int, array<string, mixed>> $jtlOrders
     * @param array<int, array<string, mixed>> $jtlWorkerSyncs
     * @param array<int, array<string, mixed>> $activeCustomers
     */
    private function renderJtlOrders(
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
        ob_start();
        ?>
            <section>
                <div class="section-head">
                    <h2>Ordenes nuevas de JTL</h2>
                    <form action="<?= $this->e($this->url('/')) ?>" method="get">
                        <input type="hidden" name="tab" value="jtl-orders">
                        <button class="button" type="submit">Recargar desde JTL</button>
                    </form>
                </div>
                <div class="section-body">
                    <?= $this->renderJtlWorkerPanel($jtlWorkerSyncs, $jtlWorkerStatus, $jtlWorkerError) ?>

                    <form class="jtl-order-filter-form" action="<?= $this->e($this->url('/')) ?>" method="get">
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
                        <a class="button secondary button-link" href="<?= $this->e($this->tabUrl('jtl-orders')) ?>">Limpiar</a>
                    </form>

                    <?php if ($error !== null): ?>
                        <div class="empty">No se pudieron leer las ordenes nuevas de JTL: <?= $this->e($error) ?></div>
                    <?php elseif ($jtlOrders === []): ?>
                        <div class="empty"><?= $filtersActive ? 'Sin ordenes nuevas de JTL para estos filtros.' : 'Sin ordenes nuevas de JTL.' ?></div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Orden JTL</th>
                                    <th>Fecha</th>
                                    <th>Cliente orden</th>
                                    <th>Canal JTL</th>
                                    <th>Cliente Packiyo</th>
                                    <th>Estado</th>
                                    <th>Accion</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($jtlOrders as $order): ?>
                                    <tr>
                                        <td>
                                            <strong><?= $this->e(($order['number'] ?? '') ?: ($order['id'] ?? '-')) ?></strong>
                                            <div class="muted">ID <?= $this->e(($order['id'] ?? '') ?: '-') ?></div>
                                            <?php if (($order['marketplace_number'] ?? '') !== ''): ?>
                                                <div class="muted">Marketplace <?= $this->e($order['marketplace_number']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $this->e($order['ordered_at'] ?? '-') ?></td>
                                        <td><?= $this->e($order['contact'] ?? '-') ?></td>
                                        <td>
                                            <strong><?= $this->e($order['source'] ?? '-') ?></strong>
                                            <?php if (($order['source_type'] ?? '') !== ''): ?>
                                                <div class="muted"><?= $this->e($order['source_type']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($order['mapped'])): ?>
                                                <?= $this->e($order['packiyo_customer'] ?? '-') ?>
                                            <?php else: ?>
                                                <span class="status missing_config">sin_mapeo</span>
                                                <?php if (($order['candidate_summary'] ?? '') !== ''): ?>
                                                    <div class="muted"><?= $this->e($order['candidate_summary']) ?></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (($order['sync_state'] ?? '') === 'confirmed'): ?>
                                                <span class="status synced">confirmada</span>
                                                <div class="muted">Packiyo #<?= $this->e($order['packiyo_order_id'] ?? '-') ?></div>
                                            <?php elseif (($order['sync_state'] ?? '') === 'archived'): ?>
                                                <span class="status archived">archivada</span>
                                                <div class="muted"><?= $this->e($order['sync_message'] ?? 'Archivada en Packiyo') ?></div>
                                            <?php elseif (($order['sync_state'] ?? '') === 'local_only'): ?>
                                                <span class="status missing_config">solo_local</span>
                                                <div class="muted">Packiyo #<?= $this->e($order['packiyo_order_id'] ?? '-') ?> no existe</div>
                                            <?php elseif (($order['sync_state'] ?? '') === 'unknown'): ?>
                                                <span class="status missing_config">sin_verificar</span>
                                                <div class="muted"><?= $this->e($order['sync_message'] ?? 'No se pudo verificar Packiyo') ?></div>
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
                    <?php endif; ?>
                </div>
            </section>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>|null $state
     */
    private function renderFulfillment(array $rows, ?array $state): string
    {
        ob_start();
        ?>
            <section>
                <div class="section-head">
                    <h2>Fulfillment Packiyo -> JTL</h2>
                    <form action="<?= $this->e($this->url('/fulfillment/sync')) ?>" method="post">
                        <button class="button" type="submit">Enviar tracking a JTL</button>
                    </form>
                </div>
                <div class="section-body">
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
                        <div class="empty">Todavia no hay tracking enviado a JTL.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Orden JTL</th>
                                    <th>Packiyo</th>
                                    <th>Tracking</th>
                                    <th>Carrier</th>
                                    <th>Delivery note</th>
                                    <th>Estado</th>
                                    <th>Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td>
                                            <strong><?= $this->e(($row['jtl_order_number'] ?? '') ?: ($row['jtl_order_id'] ?? '-')) ?></strong>
                                            <div class="muted">ID <?= $this->e($row['jtl_order_id'] ?? '-') ?></div>
                                        </td>
                                        <td><?= $this->e($row['packiyo_order_id'] ?? '-') ?></td>
                                        <td>
                                            <strong><?= $this->e($row['tracking_number'] ?? '-') ?></strong>
                                            <?php if (($row['tracking_url'] ?? '') !== ''): ?>
                                                <div class="muted"><?= $this->e($row['tracking_url']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $this->e($row['carrier'] ?? '-') ?></td>
                                        <td>
                                            <?= $this->e($row['jtl_delivery_note_id'] ?? '-') ?>
                                            <?php if (($row['jtl_package_id'] ?? '') !== ''): ?>
                                                <div class="muted">Package <?= $this->e($row['jtl_package_id']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="status <?= (($row['status'] ?? '') === 'synced' || ($row['status'] ?? '') === 'already_present') ? 'synced' : 'missing_config' ?>"><?= $this->e($row['status'] ?? '-') ?></span></td>
                                        <td><?= $this->e($row['synced_at'] ?? '-') ?></td>
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

    /**
     * @param array<int, array<string, mixed>> $orderSources
     * @param array<int, array<string, mixed>> $customerMappings
     * @param array<int, array<string, mixed>> $activeCustomers
     * @param array<int, array<string, mixed>> $productSkuAliasRows
     * @param array<int, array<string, mixed>> $productSkuAliasProducts
     */
    private function renderCustomerMappings(
        array $orderSources,
        array $customerMappings,
        array $activeCustomers,
        array $productSkuAliasRows,
        array $productSkuAliasProducts,
        ?string $productSkuAliasError,
        string $selectedSkuAliasCustomerId
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
     * @param array<int, array<string, mixed>> $activeCustomers
     * @param array<int, array<string, mixed>> $products
     */
    private function renderProducts(
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

    private function renderSettings(): string
    {
        $database = Config::get('database.mysql', []);
        $users = (new AppUser())->all();
        $invitations = (new UserInvitation())->recent(50);

        ob_start();
        ?>
            <section>
                <div class="section-head">
                    <h2>Ajustes</h2>
                </div>
                <div class="section-body">
                    <form class="settings-form" action="<?= $this->e($this->url('/settings')) ?>" method="post" autocomplete="off">
                        <?php foreach (SettingsCatalog::sections() as $section): ?>
                            <div>
                                <h3><?= $this->e($section['title'] ?? '') ?></h3>
                                <p class="muted"><?= $this->e($section['description'] ?? '') ?></p>
                                <div class="settings-grid">
                                    <?php foreach (($section['fields'] ?? []) as $field): ?>
                                        <?= $this->renderSettingField($field) ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div class="actions">
                            <button class="button" type="submit">Guardar ajustes</button>
                        </div>
                    </form>

                    <h3>Usuarios</h3>
                    <form class="invite-form" action="<?= $this->e($this->url('/users/invite')) ?>" method="post">
                        <input name="email" type="email" placeholder="email@empresa.com" required>
                        <input name="ttl_hours" type="number" min="1" max="720" value="<?= $this->e(Setting::get('AUTH_INVITATION_TTL_HOURS', 72)) ?>" aria-label="Horas">
                        <button class="button" type="submit">Crear invitacion</button>
                    </form>

                    <?php if ($users === []): ?>
                        <div class="empty">Todavia no hay usuarios en MySQL. Crea una invitacion y abre el link para crear el primer usuario.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Usuario</th>
                                    <th>Email</th>
                                    <th>Estado</th>
                                    <th>Ultimo login</th>
                                    <th>Creado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><strong><?= $this->e($user['username'] ?? '-') ?></strong></td>
                                        <td><?= $this->e($user['email'] ?? '-') ?></td>
                                        <td><span class="status <?= ((int) ($user['active'] ?? 0) === 1) ? 'active' : 'inactive' ?>"><?= ((int) ($user['active'] ?? 0) === 1) ? 'active' : 'inactive' ?></span></td>
                                        <td><?= $this->e($user['last_login_at'] ?? '-') ?></td>
                                        <td><?= $this->e($user['created_at'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <h3>Invitaciones recientes</h3>
                    <?php if ($invitations === []): ?>
                        <div class="empty">Sin invitaciones.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Estado</th>
                                    <th>Invitado por</th>
                                    <th>Expira</th>
                                    <th>Creada</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invitations as $invitation): ?>
                                    <?php $status = $this->invitationStatus($invitation); ?>
                                    <tr>
                                        <td><?= $this->e($invitation['email'] ?? '-') ?></td>
                                        <td><span class="status <?= $status === 'pending' ? 'ready' : ($status === 'accepted' ? 'synced' : 'inactive') ?>"><?= $this->e($status) ?></span></td>
                                        <td><?= $this->e($invitation['created_by_username'] ?? '-') ?></td>
                                        <td><?= $this->e($invitation['expires_at'] ?? '-') ?></td>
                                        <td><?= $this->e($invitation['created_at'] ?? '-') ?></td>
                                        <td>
                                            <?php if ($status === 'pending'): ?>
                                                <form class="inline-form" action="<?= $this->e($this->url('/users/invite/revoke')) ?>" method="post">
                                                    <input type="hidden" name="id" value="<?= $this->e($invitation['id'] ?? '') ?>">
                                                    <button class="button secondary small" type="submit">Revocar</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <h3>Base de datos</h3>
                    <div class="details">
                        <div class="detail">
                            <span>Host</span>
                            <strong><?= $this->e($database['host'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Puerto</span>
                            <strong><?= $this->e($database['port'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Database</span>
                            <strong><?= $this->e($database['database'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Usuario</span>
                            <strong><?= $this->e($database['username'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Charset</span>
                            <strong><?= $this->e($database['charset'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Collation</span>
                            <strong><?= $this->e($database['collation'] ?? '-') ?></strong>
                        </div>
                    </div>
                </div>
            </section>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $invitation */
    private function invitationStatus(array $invitation): string
    {
        if (($invitation['accepted_at'] ?? null) !== null) {
            return 'accepted';
        }

        if (($invitation['revoked_at'] ?? null) !== null) {
            return 'revoked';
        }

        $expiresAt = strtotime((string) ($invitation['expires_at'] ?? ''));

        if ($expiresAt !== false && $expiresAt <= time()) {
            return 'expired';
        }

        return 'pending';
    }

    /** @param array<string, mixed> $field */
    private function renderSettingField(array $field): string
    {
        $key = (string) ($field['key'] ?? '');
        $type = (string) ($field['type'] ?? 'text');
        $id = 'setting-' . strtolower(str_replace('_', '-', $key));
        $value = $this->settingValue($field);
        $full = $type === 'textarea' || in_array($key, [
            'JTL_BASE_URL',
            'PACKIYO_BASE_URL',
            'APP_BASE_URL',
            'JTL_DELIVERY_NOTE_PACKAGES_ENDPOINT',
        ], true);

        ob_start();
        ?>
            <div class="setting-field <?= $full ? 'full' : '' ?>">
                <label for="<?= $this->e($id) ?>"><?= $this->e($field['label'] ?? $key) ?></label>

                <?php if ($type === 'boolean'): ?>
                    <select id="<?= $this->e($id) ?>" name="<?= $this->e($key) ?>">
                        <option value="true" <?= $value === 'true' ? 'selected' : '' ?>>true</option>
                        <option value="false" <?= $value === 'false' ? 'selected' : '' ?>>false</option>
                    </select>
                <?php elseif ($type === 'select'): ?>
                    <select id="<?= $this->e($id) ?>" name="<?= $this->e($key) ?>">
                        <?php foreach (($field['options'] ?? []) as $option): ?>
                            <option value="<?= $this->e($option) ?>" <?= $value === (string) $option ? 'selected' : '' ?>><?= $this->e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($type === 'textarea'): ?>
                    <textarea id="<?= $this->e($id) ?>" name="<?= $this->e($key) ?>"><?= $this->e($value) ?></textarea>
                <?php elseif (!empty($field['secret'])): ?>
                    <input id="<?= $this->e($id) ?>" name="<?= $this->e($key) ?>" type="password" value="" placeholder="Nuevo valor">
                    <div class="field-hint"><?= $this->settingConfigured($key) ? 'Configurado. Dejar vacio para mantenerlo.' : 'Sin configurar.' ?></div>
                <?php else: ?>
                    <input id="<?= $this->e($id) ?>" name="<?= $this->e($key) ?>" type="<?= $type === 'number' ? 'number' : 'text' ?>" value="<?= $this->e($value) ?>">
                <?php endif; ?>

                <?php if ($key === 'JTL_BASE_URL'): ?>
                    <div class="field-hint">En hosting compartido, usa la URL publica del Cloudflare Tunnel, por ejemplo https://jtl-wawi.3plgermany.com.</div>
                <?php endif; ?>
                <?php if ($key === 'JTL_WORKER_SYNC_BODY_TEMPLATE'): ?>
                    <div class="field-hint">Para iniciar usa {"Action":0}. JTL define 0=Start, 1=Stop, 2=Restart.</div>
                <?php endif; ?>
            </div>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $field */
    private function settingValue(array $field): string
    {
        $key = (string) ($field['key'] ?? '');
        $default = (string) ($field['default'] ?? '');
        $value = Setting::get($key, $default);

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($key === 'JTL_MANDATORY_API_SCOPES' && is_scalar($value)) {
            return JtlScopeList::sanitizeString((string) $value);
        }

        return is_scalar($value) ? (string) $value : $default;
    }

    private function settingConfigured(string $key): bool
    {
        return Setting::configured($key);
    }

    /** @return array<int, array<string, mixed>> */
    private function cachedWorkerSyncs(): array
    {
        if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $syncs = $_SESSION['jtl_worker_syncs'] ?? [];

        if (!is_array($syncs)) {
            return [];
        }

        $items = [];

        foreach ($syncs as $sync) {
            if (is_array($sync)) {
                $items[] = $sync;
            }
        }

        return $items;
    }

    private function activeTab(mixed $tab): string
    {
        $tab = is_string($tab) ? $tab : 'overview';
        $allowed = ['overview', 'jtl-orders', 'fulfillment', 'packiyo-customers', 'customer-mappings', 'products', 'settings', 'logs'];

        return in_array($tab, $allowed, true) ? $tab : 'overview';
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private function jtlOrderRows(
        array $orders,
        PackiyoCustomerMapping $customerMappings,
        OrderMapping $orderMappings,
        PackiyoClient $packiyo
    ): array
    {
        $mapper = new MappingService($orderMappings);
        $resolver = new PackiyoCustomerResolver($customerMappings);
        $rows = [];

        foreach ($orders as $order) {
            $id = $mapper->jtlOrderId($order);
            $number = $mapper->jtlOrderNumber($order);
            $marketplaceNumber = $mapper->marketplaceOrderNumber($order);
            $candidates = $resolver->candidates($order);
            $mapping = $customerMappings->findForCandidates($candidates);
            $source = $this->primaryOrderSource($candidates);
            $orderMapping = $id !== null ? $orderMappings->findByJtlOrderId($id) : null;
            $syncState = $this->packiyoSyncState($orderMapping, $packiyo, $id);

            $rows[] = [
                'id' => $id ?? '',
                'number' => $number ?? '',
                'marketplace_number' => $marketplaceNumber ?? '',
                'reference' => $id ?? $number ?? '',
                'ordered_at' => $this->orderDate($order) ?? '-',
                'contact' => $this->orderContact($order) ?? '-',
                'source' => $source['value'],
                'source_type' => $source['label'],
                'mapped' => $mapping !== null,
                'packiyo_customer_id' => $mapping['packiyo_customer_id'] ?? '',
                'packiyo_customer' => $mapping !== null
                    ? trim((string) (($mapping['packiyo_customer_name'] ?: '-') . ' #' . $mapping['packiyo_customer_id']))
                    : '',
                'packiyo_order_id' => $orderMapping['packiyo_order_id'] ?? '',
                'packiyo_order_number' => $orderMapping['packiyo_order_number'] ?? '',
                'sync_state' => $syncState['state'],
                'sync_message' => $syncState['message'],
                'candidate_summary' => $resolver->describeCandidates($order),
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterJtlOrderRows(array $rows, string $customerFilter, string $mappedCustomerFilter): array
    {
        $customerFilter = strtolower(trim($customerFilter));
        $mappedCustomerFilter = trim($mappedCustomerFilter);

        if ($customerFilter === '' && $mappedCustomerFilter === '') {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static function (array $row) use ($customerFilter, $mappedCustomerFilter): bool {
                if ($customerFilter !== '') {
                    $contact = strtolower((string) ($row['contact'] ?? ''));

                    if (!str_contains($contact, $customerFilter)) {
                        return false;
                    }
                }

                if ($mappedCustomerFilter === '__unmapped__') {
                    return empty($row['mapped']);
                }

                if ($mappedCustomerFilter !== '') {
                    return (string) ($row['packiyo_customer_id'] ?? '') === $mappedCustomerFilter;
                }

                return true;
            }
        ));
    }

    /**
     * @param array<string, mixed>|null $orderMapping
     * @return array{state: string, message: string}
     */
    private function packiyoSyncState(?array $orderMapping, PackiyoClient $packiyo, ?string $jtlOrderId): array
    {
        if ($orderMapping === null) {
            return ['state' => 'not_synced', 'message' => ''];
        }

        $packiyoOrderId = trim((string) ($orderMapping['packiyo_order_id'] ?? ''));

        if ($packiyoOrderId === '') {
            return ['state' => 'local_only', 'message' => 'El mapeo local no tiene Packiyo order ID.'];
        }

        try {
            $response = $packiyo->getOrder($packiyoOrderId);
            $order = $this->firstPackiyoOrder($response) ?? $response;
            $inactiveMessage = $this->packiyoInactiveMessage($order);

            if ($inactiveMessage !== null) {
                return ['state' => 'archived', 'message' => $inactiveMessage];
            }

            return ['state' => 'confirmed', 'message' => ''];
        } catch (HttpException $exception) {
            if ($exception->statusCode() === 404) {
                $externalIds = array_values(array_unique(array_filter([
                    trim((string) ($orderMapping['packiyo_order_number'] ?? '')),
                    $jtlOrderId,
                ], static fn (?string $value): bool => $value !== null && $value !== '')));

                foreach ($externalIds as $externalId) {
                    try {
                        $foundOrder = $this->firstPackiyoOrder($packiyo->findOrder($externalId));

                        if ($foundOrder !== null) {
                            $inactiveMessage = $this->packiyoInactiveMessage($foundOrder);

                            if ($inactiveMessage !== null) {
                                return ['state' => 'archived', 'message' => $inactiveMessage];
                            }

                            return ['state' => 'confirmed', 'message' => 'Encontrada en Packiyo por external_id ' . $externalId . '.'];
                        }
                    } catch (\Throwable $lookupException) {
                        return ['state' => 'unknown', 'message' => 'No se pudo buscar por external_id: ' . $lookupException->getMessage()];
                    }
                }

                return ['state' => 'local_only', 'message' => 'Packiyo no encontro la orden guardada localmente.'];
            }

            return ['state' => 'unknown', 'message' => 'HTTP ' . $exception->statusCode() . ' al verificar Packiyo.'];
        } catch (\Throwable $exception) {
            return ['state' => 'unknown', 'message' => $exception->getMessage()];
        }
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

        $running = $this->firstScalar($status, ['isRunning', 'IsRunning', 'running', 'Running']);

        if ($running !== null) {
            return in_array(strtolower($running), ['1', 'true', 'yes'], true) ? 'running' : 'idle';
        }

        return $this->firstScalar($status, ['status', 'Status', 'state', 'State', 'workerStatus', 'WorkerStatus'])
            ?? $this->shortJson($status, 80);
    }

    private function looksLikeForbiddenWorkerError(string $error): bool
    {
        return str_contains($error, 'HTTP 403');
    }

    /** @param array<string, mixed> $sync */
    private function workerSyncId(array $sync): string
    {
        return $this->firstScalar($sync, [
            'identifier',
            'Identifier',
            'guid',
            'Guid',
            'syncId',
            'SyncId',
            'workerSyncId',
            'WorkerSyncId',
            'key',
            'Key',
            'value',
            'Value',
            'id',
            'Id',
            'ID',
            'internalId',
            'InternalId',
            'number',
            'Number',
        ]) ?? '';
    }

    /** @param array<string, mixed> $sync */
    private function workerSyncLabel(array $sync): string
    {
        $id = $this->workerSyncId($sync);
        $name = $this->firstScalar($sync, [
            'name',
            'Name',
            'syncName',
            'SyncName',
            'displayName',
            'DisplayName',
            'description',
            'Description',
            'title',
            'Title',
            'platform',
            'Platform',
        ]);

        if ($name === null) {
            return $id !== '' ? '#' . $id : $this->shortJson($sync, 80);
        }

        return $id !== '' ? $name . ' #' . $id : $name;
    }

    /** @param array<string, mixed> $data */
    private function shortJson(array $data, int $maxLength = 220): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($json) || $json === '') {
            return '-';
        }

        if (strlen($json) <= $maxLength) {
            return $json;
        }

        return substr($json, 0, max(0, $maxLength - 3)) . '...';
    }

    /** @param array<string, mixed> $response */
    private function firstPackiyoOrder(array $response): ?array
    {
        $data = $response['data'] ?? $response['Data'] ?? null;

        if (!is_array($data) || $data === []) {
            return null;
        }

        if (array_is_list($data)) {
            foreach ($data as $item) {
                if (is_array($item)) {
                    return $item;
                }
            }

            return null;
        }

        return $data;
    }

    /** @param array<string, mixed> $order */
    private function packiyoInactiveMessage(array $order): ?string
    {
        $attributes = $this->firstArray($order, ['attributes', 'Attributes']);
        $archivedAt = $this->firstScalar($attributes, ['archived_at', 'archivedAt']);
        $deletedAt = $this->firstScalar($attributes, ['deleted_at', 'deletedAt']);

        if ($archivedAt !== null) {
            return 'Archivada en Packiyo: ' . $archivedAt;
        }

        if ($deletedAt !== null) {
            return 'Eliminada en Packiyo: ' . $deletedAt;
        }

        return null;
    }

    /**
     * @param array<string, array<int, string>> $candidates
     * @return array{label: string, value: string}
     */
    private function primaryOrderSource(array $candidates): array
    {
        $labels = [
            'sales_channel' => 'Sales channel',
            'marketplace' => 'Marketplace',
            'shop' => 'Shop',
            'customer_number' => 'Customer number',
            'customer_id' => 'Customer ID',
            'company' => 'Company',
            'email' => 'Email',
        ];

        foreach ($labels as $type => $label) {
            $value = $candidates[$type][0] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return ['label' => $label, 'value' => $value];
            }
        }

        return ['label' => '', 'value' => '-'];
    }

    /** @param array<string, mixed> $order */
    private function orderDate(array $order): ?string
    {
        return $this->firstScalar($order, [
            'ordered_at',
            'created_at',
            'date',
            'Date',
            'orderDate',
            'OrderDate',
            'creationDate',
            'CreationDate',
            'SalesOrderDate',
        ]);
    }

    /** @param array<string, mixed> $order */
    private function orderContact(array $order): ?string
    {
        $customer = $this->firstArray($order, ['customer', 'Customer', 'customer_data', 'CustomerData', 'client', 'Client']);
        $billing = $this->firstArray($order, ['billing_address', 'billingAddress', 'BillingAddress', 'invoiceAddress', 'InvoiceAddress']);
        $shipping = $this->firstArray($order, ['shipping_address', 'shippingAddress', 'ShippingAddress', 'deliveryAddress', 'DeliveryAddress', 'Shipmentaddress', 'ShipmentAddress', 'shipmentAddress']);

        return $this->fullName($shipping)
            ?? $this->fullName($billing)
            ?? $this->fullName($customer)
            ?? $this->firstScalar($shipping, ['email', 'Email', 'mail', 'Mail', 'EmailAddress'])
            ?? $this->firstScalar($billing, ['email', 'Email', 'mail', 'Mail', 'EmailAddress']);
    }

    /** @param array<string, mixed> $data */
    private function firstArray(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        return [];
    }

    /** @param array<string, mixed> $data */
    private function firstScalar(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || !is_scalar($data[$key])) {
                continue;
            }

            $value = trim((string) $data[$key]);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function fullName(array $data): ?string
    {
        $fullName = $this->firstScalar($data, ['name', 'Name', 'full_name', 'fullName', 'FullName']);

        if ($fullName !== null) {
            return $fullName;
        }

        $firstName = $this->firstScalar($data, ['first_name', 'firstName', 'firstname', 'FirstName']);
        $lastName = $this->firstScalar($data, ['last_name', 'lastName', 'lastname', 'LastName']);
        $name = trim((string) ($firstName . ' ' . $lastName));

        return $name !== '' ? $name : null;
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

        if (($registration['status'] ?? '') === 'pending') {
            return 'registration_pending';
        }

        if (($registration['status'] ?? '') === 'cancelled') {
            return 'registration_cancelled';
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
