<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Clients\JtlClient;
use App\Clients\PackiyoClient;
use App\Models\AppSyncState;
use App\Models\AutomationOrderSkip;
use App\Models\AppUser;
use App\Models\FulfillmentSync;
use App\Models\JtlApiCredential;
use App\Models\JtlOrderSource;
use App\Models\OrderMapping;
use App\Models\ProductNameMapping;
use App\Models\PackiyoCustomer;
use App\Models\PackiyoCustomerMapping;
use App\Models\ProductSkuAlias;
use App\Models\SyncLog;
use App\Models\UserInvitation;
use App\Services\MappingService;
use App\Services\OrderDetailService;
use App\Services\OrderCorrectionService;
use App\Services\OrderPreparationService;
use App\Services\PackiyoCustomerResolver;
use App\Services\ProductImportService;
use App\Services\ProductSkuAliasService;
use App\Support\Auth;
use App\Support\Config;
use App\Support\HttpException;
use App\Support\JtlScopeList;
use App\Support\Setting;
use App\Support\SettingsCatalog;

final class DashboardController
{
    public function index(): void
    {
        $mappings = new OrderMapping();
        $logs = new SyncLog();
        $credentials = new JtlApiCredential();
        $orderSources = new JtlOrderSource();
        $customerMappings = new PackiyoCustomerMapping();
        $packiyoCustomers = new PackiyoCustomer();
        $fulfillmentSyncs = new FulfillmentSync();
        $syncStates = new AppSyncState();
        $jtl = new JtlClient(timeout: 10);
        $packiyo = new PackiyoClient();
        $registration = $credentials->latest();
        $tab = $this->activeTab($_GET['tab'] ?? 'overview');
        $jtlOrders = [];
        $rawJtlOrders = [];
        $jtlOrdersError = null;
        $jtlWorkerSyncs = [];
        $jtlWorkerStatus = null;
        $jtlWorkerError = null;
        $productRows = [];
        $productImportError = null;
        $productSkuAliasRows = [];
        $productSkuAliasProducts = [];
        $productSkuAliasError = null;
        $orderDetail = null;
        $orderDetailError = null;
        $productNameMappings = [];
        $productNameCatalog = [];
        $productNameCatalogError = null;
        $correctionData = ['job' => null, 'lines' => [], 'catalogs' => [], 'write_enabled' => false];
        $correctionError = null;
        $orderReference = is_scalar($_GET['order_reference'] ?? null) ? trim((string) $_GET['order_reference']) : '';
        $selectedNameCustomerId = is_scalar($_GET['name_customer_id'] ?? null) ? trim((string) $_GET['name_customer_id']) : '';
        $jtlOrderCustomerFilter = is_scalar($_GET['jtl_customer'] ?? null) ? trim((string) $_GET['jtl_customer']) : '';
        $jtlOrderMappedCustomerFilter = is_scalar($_GET['jtl_mapped_customer'] ?? null) ? trim((string) $_GET['jtl_mapped_customer']) : '';
        $verifyPackiyoOrderReference = is_scalar($_GET['verify_packiyo_order'] ?? null)
            ? trim((string) $_GET['verify_packiyo_order'])
            : '';
        $fulfillmentCustomerId = is_scalar($_GET['fulfillment_customer_id'] ?? null) ? trim((string) $_GET['fulfillment_customer_id']) : '';
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
                $rawJtlOrders = $jtl->getOrders();
                $jtlOrders = $this->filterJtlOrderRows(
                    $this->jtlOrderRows(
                        $rawJtlOrders,
                        $customerMappings,
                        $mappings,
                        $packiyo,
                        $verifyPackiyoOrderReference
                    ),
                    $jtlOrderCustomerFilter,
                    $jtlOrderMappedCustomerFilter
                );
            } catch (\Throwable $exception) {
                $jtlOrdersError = $exception->getMessage();
            }

            if ($orderReference !== '') {
                try {
                    $orderDetail = (new OrderDetailService())->load($orderReference, false, $rawJtlOrders);
                } catch (\Throwable $exception) {
                    $orderDetailError = $exception->getMessage();
                }
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

        if ($tab === 'customer-mappings' && $selectedNameCustomerId !== '') {
            $productNameMappings = (new ProductNameMapping())->allForCustomer($selectedNameCustomerId);
            try {
                $productNameCatalog = (new OrderPreparationService())->catalog($selectedNameCustomerId, false);
            } catch (\Throwable $exception) {
                $productNameCatalogError = $exception->getMessage();
            }
        }

        if ($tab === 'order-corrections') {
            try {
                $correctionData = (new OrderCorrectionService())->dashboard(
                    is_scalar($_GET['correction_job'] ?? null) ? trim((string) $_GET['correction_job']) : null,
                    [
                        'customer' => is_scalar($_GET['correction_customer'] ?? null) ? trim((string) $_GET['correction_customer']) : '',
                        'status' => is_scalar($_GET['correction_status'] ?? null) ? trim((string) $_GET['correction_status']) : '',
                        'source' => is_scalar($_GET['correction_source'] ?? null) ? trim((string) $_GET['correction_source']) : '',
                        'result' => is_scalar($_GET['correction_result'] ?? null) ? trim((string) $_GET['correction_result']) : '',
                    ]
                );
            } catch (\Throwable $exception) {
                $correctionError = $exception->getMessage();
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
            $syncStates->get('automation'),
            $fulfillmentSyncs->recent(500, $fulfillmentCustomerId),
            $jtlOrders,
            $jtlOrdersError,
            $jtlWorkerSyncs,
            $jtlWorkerStatus,
            $jtlWorkerError,
            $jtlOrderCustomerFilter,
            $jtlOrderMappedCustomerFilter,
            $orderDetail,
            $orderDetailError,
            $fulfillmentCustomerId,
            $productSkuAliasRows,
            $productSkuAliasProducts,
            $productSkuAliasError,
            $selectedSkuAliasCustomerId,
            $selectedNameCustomerId,
            $productNameMappings,
            $productNameCatalog,
            $productNameCatalogError,
            $productRows,
            $productImportError,
            $selectedProductCustomerId,
            $productImportCategoryId,
            $productImportWarehouseId,
            $mappings->recent(500),
            $logs->recent(500),
            $correctionData,
            $correctionError,
            $this->noticeFromRequest($_GET['notice'] ?? $_GET['sync'] ?? null)
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
     * @param array<string, mixed>|null $automationState
     * @param array<int, array<string, mixed>> $fulfillmentRows
     * @param array<int, array<string, mixed>> $jtlOrders
     * @param array<int, array<string, mixed>> $jtlWorkerSyncs
     * @param array<string, mixed>|null $jtlWorkerStatus
     * @param string $jtlOrderCustomerFilter
     * @param string $jtlOrderMappedCustomerFilter
     * @param string $fulfillmentCustomerId
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
        ?array $automationState,
        array $fulfillmentRows,
        array $jtlOrders,
        ?string $jtlOrdersError,
        array $jtlWorkerSyncs,
        ?array $jtlWorkerStatus,
        ?string $jtlWorkerError,
        string $jtlOrderCustomerFilter,
        string $jtlOrderMappedCustomerFilter,
        ?array $orderDetail,
        ?string $orderDetailError,
        string $fulfillmentCustomerId,
        array $productSkuAliasRows,
        array $productSkuAliasProducts,
        ?string $productSkuAliasError,
        string $selectedSkuAliasCustomerId,
        string $selectedNameCustomerId,
        array $productNameMappings,
        array $productNameCatalog,
        ?string $productNameCatalogError,
        array $productRows,
        ?string $productImportError,
        string $selectedProductCustomerId,
        string $productImportCategoryId,
        string $productImportWarehouseId,
        array $mappings,
        array $logs,
        array $correctionData,
        ?string $correctionError,
        mixed $notice
    ): string {
        $automationEnabled = (bool) Config::get('automation.enabled', true);
        $automationIntervalMinutes = max(1, (int) Config::get('automation.interval_minutes', 360));
        $automationLastRun = $automationState['last_success_at'] ?? null;
        $automationNextRun = $this->automationNextRunAt(
            is_string($automationLastRun) ? $automationLastRun : null,
            $automationIntervalMinutes,
            $automationEnabled
        );
        $automationLastSummary = $this->automationLastSummary($automationState);
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
            --danger: #b42318;
            --bad: #b42318;
        }

        * {
            box-sizing: border-box;
        }

        .sr-only {
            clip: rect(0, 0, 0, 0);
            clip-path: inset(50%);
            height: 1px;
            overflow: hidden;
            position: absolute;
            white-space: nowrap;
            width: 1px;
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
            align-items: center;
            border: 0;
            border-radius: 6px;
            background: var(--accent);
            color: #fff;
            cursor: pointer;
            display: inline-flex;
            font-weight: 700;
            justify-content: center;
            line-height: 1.2;
            min-height: 40px;
            padding: 0 16px;
            text-decoration: none;
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

        .correction-selection-bar {
            align-items: center;
            background: #fff8e8;
            border: 1px solid #f2cf7d;
            border-radius: 6px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 14px 0;
            padding: 10px 12px;
        }

        .correction-selection-bar .selection-count {
            color: var(--muted);
            margin-right: auto;
        }

        .order-checkbox {
            height: 18px;
            width: 18px;
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
            grid-template-columns: repeat(6, minmax(0, 1fr));
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

        .metric-note {
            color: var(--muted);
            font-size: 12px;
            margin-top: 8px;
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

        .status.failed {
            background: #fdecea;
            color: var(--danger);
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

        .table-search {
            align-items: center;
            display: grid;
            gap: 10px;
            grid-template-columns: minmax(220px, 1fr) auto auto;
            margin: 0 0 10px;
        }

        .table-search input[type="search"] {
            min-width: 0;
            width: 100%;
        }

        .table-search-count {
            color: var(--muted);
            font-size: 12px;
            min-width: 72px;
            text-align: right;
        }

        .table-search-empty td {
            color: var(--muted);
            padding: 22px 14px;
            text-align: center;
        }

        .worker-panel {
            border-bottom: 1px solid var(--line);
            margin: -2px 0 14px;
            padding-bottom: 14px;
        }

        .worker-checklist {
            color: var(--muted);
            line-height: 1.55;
            margin: 10px 0 12px;
            padding-left: 20px;
        }

        .jtl-worker-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 10px 0;
        }

        .code-block {
            background: #111827;
            border-radius: 6px;
            color: #f9fafb;
            overflow-x: auto;
            padding: 12px;
            white-space: pre-wrap;
        }

        .jtl-order-filter-form {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) minmax(220px, 1fr) auto auto;
            gap: 10px;
            margin-bottom: 14px;
        }

        .order-edit-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .address-editor, .order-line {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 12px;
        }

        .address-fields, .order-line-fields {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .order-lines { display: grid; gap: 10px; margin: 14px 0; }
        .order-line-fields { grid-template-columns: 1.4fr 1.4fr .7fr .7fr; }
        .review-errors { color: var(--bad); margin: 10px 0; }
        .suggestions { color: var(--muted); font-size: 12px; margin-top: 6px; }

        .correction-start-form {
            border: 1px solid var(--line);
            border-radius: 6px;
            display: grid;
            gap: 12px;
            margin-bottom: 14px;
            padding: 14px;
        }

        .customer-check-grid {
            display: grid;
            gap: 8px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            max-height: 240px;
            overflow: auto;
        }

        .customer-check {
            align-items: flex-start;
            border: 1px solid var(--line);
            border-radius: 6px;
            color: inherit;
            display: flex;
            gap: 9px;
            margin: 0;
            padding: 10px;
        }

        .customer-check small {
            color: var(--muted);
            display: block;
            font-weight: 400;
            margin-top: 2px;
        }

        .fulfillment-toolbar {
            align-items: flex-start;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
        }

        .fulfillment-filter-form {
            align-items: stretch;
            display: grid;
            flex: 1 1 620px;
            gap: 10px;
            grid-template-columns: minmax(260px, 1fr) auto auto;
            margin: 0;
        }

        .fulfillment-sync-form {
            align-items: flex-end;
            display: flex;
            flex: 0 0 auto;
            flex-direction: column;
            gap: 6px;
            margin: 0;
        }

        .fulfillment-sync-form .button {
            min-width: 190px;
        }

        .fulfillment-sync-hint {
            font-size: 12px;
            max-width: 260px;
            text-align: right;
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

        .sortable-table th {
            white-space: nowrap;
        }

        .table-sort {
            align-items: center;
            background: transparent;
            border: 0;
            color: inherit;
            cursor: pointer;
            display: inline-flex;
            font: inherit;
            gap: 6px;
            justify-content: flex-start;
            letter-spacing: inherit;
            min-height: 0;
            padding: 0;
            text-align: left;
            text-transform: inherit;
            width: auto;
        }

        .table-sort:hover {
            color: var(--accent);
        }

        .table-sort:focus-visible {
            border-radius: 4px;
            outline: 2px solid var(--accent);
            outline-offset: 3px;
        }

        .sort-indicator {
            color: var(--accent);
            font-size: 14px;
            line-height: 1;
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
            .order-edit-grid,
            .order-line-fields,
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

            .summary, .details, .mapping-form, .manual-order-form, .invite-form, .product-filter-form, .table-search, .jtl-order-filter-form, .fulfillment-filter-form, .sku-alias-filter-form, .sku-alias-form, .sku-alias-row-form, .settings-grid, .order-edit-grid, .address-fields, .order-line-fields {
                grid-template-columns: 1fr;
            }

            .table-search-count {
                text-align: left;
            }

            .fulfillment-toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .fulfillment-filter-form,
            .fulfillment-sync-form,
            .fulfillment-sync-form .button {
                width: 100%;
            }

            .fulfillment-sync-hint {
                max-width: 100%;
                text-align: left;
            }

            .section-head {
                align-items: flex-start;
                flex-direction: column;
            }

            table {
                display: block;
                overflow-x: auto;
            }

            .scroll-table > table {
                display: table;
                overflow: visible;
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
                <form action="<?= $this->e($this->url('/automation/manual')) ?>" method="post">
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
            <div class="metric">
                <span>Proximo auto sync</span>
                <strong><?= $this->e($automationNextRun) ?></strong>
                <div class="metric-note"><?= $this->e($automationLastSummary) ?></div>
            </div>
        </div>

        <nav class="tabs" aria-label="Dashboard">
            <a class="tab <?= $tab === 'overview' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('overview')) ?>">Resumen</a>
            <a class="tab <?= $tab === 'jtl-orders' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('jtl-orders')) ?>">Ordenes JTL</a>
            <a class="tab <?= $tab === 'fulfillment' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('fulfillment')) ?>">Fulfillment</a>
            <a class="tab <?= $tab === 'order-corrections' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('order-corrections')) ?>">Correccion de ordenes</a>
            <a class="tab <?= $tab === 'packiyo-customers' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('packiyo-customers')) ?>">Clientes Packiyo</a>
            <a class="tab <?= $tab === 'customer-mappings' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('customer-mappings')) ?>">Mapeos</a>
            <a class="tab <?= $tab === 'products' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('products')) ?>">Productos</a>
            <a class="tab <?= $tab === 'settings' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('settings')) ?>">Ajustes</a>
            <a class="tab <?= $tab === 'logs' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('logs')) ?>">Logs</a>
        </nav>

        <div class="grid">
            <?php if ($tab === 'overview'): ?>
                <?= $this->renderRegistration($registration) ?>
                <?= $this->renderAutomation($automationState) ?>
                <?= $this->renderOrders($mappings) ?>
            <?php endif; ?>

            <?php if ($tab === 'jtl-orders'): ?>
                <?= $this->renderOrderDetail($orderDetail, $orderDetailError) ?>
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
                <?= $this->renderFulfillment($fulfillmentRows, $fulfillmentState, $activeCustomers, $fulfillmentCustomerId) ?>
            <?php endif; ?>

            <?php if ($tab === 'order-corrections'): ?>
                <?= $this->renderOrderCorrections($correctionData, $correctionError) ?>
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
                    $selectedSkuAliasCustomerId,
                    $selectedNameCustomerId,
                    $productNameMappings,
                    $productNameCatalog,
                    $productNameCatalogError
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
    <script>
        (() => {
            const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

            const sortableValue = (cell, type) => {
                const raw = (cell?.dataset.sortValue ?? cell?.textContent ?? '').trim();
                if (type !== 'date') {
                    return { empty: raw === '', value: raw };
                }

                const timestamp = Date.parse(raw);
                return {
                    empty: raw === '' || Number.isNaN(timestamp),
                    value: Number.isNaN(timestamp) ? raw : timestamp,
                };
            };

            document.querySelectorAll('[data-sort-table]').forEach((table) => {
                const body = table.tBodies[0];
                if (!body) return;

                let activeKey = null;
                let direction = 1;

                table.querySelectorAll('.table-sort').forEach((button) => {
                    const header = button.closest('th');
                    if (header) header.setAttribute('aria-sort', 'none');
                    button.removeAttribute('aria-sort');

                    button.addEventListener('click', () => {
                        const key = button.dataset.sortKey || '';
                        direction = activeKey === key ? direction * -1 : 1;
                        activeKey = key;

                        table.querySelectorAll('.table-sort').forEach((other) => {
                            const active = other === button;
                            const indicator = other.querySelector('.sort-indicator');
                            const header = other.closest('th');
                            if (header) {
                                header.setAttribute('aria-sort', active ? (direction === 1 ? 'ascending' : 'descending') : 'none');
                            }
                            if (indicator) indicator.textContent = active ? (direction === 1 ? '↑' : '↓') : '↕';
                        });

                        const type = button.dataset.sortType || 'text';
                        const columnIndex = button.parentElement.cellIndex;
                        const emptyRow = body.querySelector('.table-search-empty');
                        const rows = Array.from(body.rows)
                            .filter((row) => !row.classList.contains('table-search-empty'))
                            .map((row, index) => ({ row, index }));
                        rows.sort((left, right) => {
                            const leftValue = sortableValue(left.row.cells[columnIndex], type);
                            const rightValue = sortableValue(right.row.cells[columnIndex], type);
                            if (leftValue.empty !== rightValue.empty) return leftValue.empty ? 1 : -1;

                            const comparison = type === 'date'
                                ? (leftValue.value < rightValue.value ? -1 : (leftValue.value > rightValue.value ? 1 : 0))
                                : collator.compare(leftValue.value, rightValue.value);
                            return comparison === 0 ? left.index - right.index : comparison * direction;
                        });
                        rows.forEach(({ row }) => body.appendChild(row));
                        if (emptyRow) body.appendChild(emptyRow);
                    });
                });
            });

            const normalizeSearchText = (value) => String(value ?? '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLocaleLowerCase()
                .replace(/\s+/g, ' ')
                .trim();

            const rowSearchText = (row) => {
                const controlValues = Array.from(row.querySelectorAll('input, select, textarea'))
                    .flatMap((control) => {
                        if (control instanceof HTMLSelectElement) {
                            const selected = Array.from(control.selectedOptions).map((option) => option.textContent ?? '');
                            return [control.value, ...selected];
                        }

                        return [control.value ?? ''];
                    });

                return normalizeSearchText([row.textContent ?? '', ...controlValues].join(' '));
            };

            const tableSearchControllers = new Map();

            document.querySelectorAll('table').forEach((table, index) => {
                const body = table.tBodies[0];
                if (!body || body.rows.length === 0) return;

                const rows = Array.from(body.rows);
                const searchTexts = new Map(rows.map((row) => [row, rowSearchText(row)]));
                const filters = new Map();
                const tableKey = table.dataset.sortTable || `table-${index + 1}`;
                const heading = table.closest('section')?.querySelector('h2, h3')?.textContent?.trim() || 'esta tabla';
                const toolbar = document.createElement('div');
                const inputId = `table-search-${index + 1}`;
                toolbar.className = 'table-search';
                toolbar.innerHTML = `
                    <label class="sr-only" for="${inputId}">Buscar en ${heading}</label>
                    <input id="${inputId}" type="search" placeholder="Buscar en ${heading}..." autocomplete="off">
                    <span class="table-search-count" aria-live="polite"></span>
                    <button class="button secondary small" type="button">Limpiar</button>
                `;

                const searchHost = table.closest('.scroll-table') || table;
                searchHost.parentNode?.insertBefore(toolbar, searchHost);

                const input = toolbar.querySelector('input');
                const count = toolbar.querySelector('.table-search-count');
                const clear = toolbar.querySelector('button');
                const emptyRow = document.createElement('tr');
                emptyRow.className = 'table-search-empty';
                emptyRow.hidden = true;
                emptyRow.innerHTML = `<td colspan="${Math.max(1, table.tHead?.rows[0]?.cells.length || 1)}">Sin coincidencias para esta búsqueda.</td>`;
                body.appendChild(emptyRow);

                const apply = () => {
                    const tokens = normalizeSearchText(input?.value ?? '').split(' ').filter(Boolean);
                    let visible = 0;

                    rows.forEach((row) => {
                        const text = searchTexts.get(row) || '';
                        const matchesText = tokens.every((token) => text.includes(token));
                        const matchesFilters = Array.from(filters.values()).every((filter) => filter(row));
                        row.hidden = !(matchesText && matchesFilters);
                        if (!row.hidden) visible++;
                    });

                    emptyRow.hidden = visible !== 0;
                    if (count) count.textContent = `${visible} de ${rows.length}`;
                };

                input?.addEventListener('input', apply);
                input?.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter') event.preventDefault();
                });
                clear?.addEventListener('click', () => {
                    if (input) input.value = '';
                    apply();
                    input?.focus();
                });

                tableSearchControllers.set(tableKey, {
                    apply,
                    setFilter(name, filter) {
                        if (typeof filter === 'function') filters.set(name, filter);
                        else filters.delete(name);
                        apply();
                    },
                });
                apply();
            });

            const jtlFilterForm = document.querySelector('[data-client-filter-table="jtl-orders"]');
            const jtlTableSearch = tableSearchControllers.get('jtl-orders');

            if (jtlFilterForm && jtlTableSearch) {
                const customerInput = jtlFilterForm.querySelector('[name="jtl_customer"]');
                const mappedCustomerSelect = jtlFilterForm.querySelector('[name="jtl_mapped_customer"]');
                const clearButton = jtlFilterForm.querySelector('[data-clear-jtl-filters]');
                const bulkForm = document.querySelector('[data-jtl-bulk-form]');

                const applyJtlFilters = () => {
                    const customer = normalizeSearchText(customerInput?.value ?? '');
                    const mappedCustomer = mappedCustomerSelect?.value ?? '';

                    jtlTableSearch.setFilter('jtl-customer', (row) => {
                        if (customer && !normalizeSearchText(row.dataset.jtlContact).includes(customer)) return false;
                        if (mappedCustomer === '__unmapped__') return row.dataset.jtlMapped !== '1';
                        if (mappedCustomer) return row.dataset.jtlMappedCustomer === mappedCustomer;
                        return true;
                    });

                    if (bulkForm) {
                        const hiddenCustomer = bulkForm.querySelector('[name="jtl_customer"]');
                        const hiddenMappedCustomer = bulkForm.querySelector('[name="jtl_mapped_customer"]');
                        const submit = bulkForm.querySelector('button[type="submit"]');
                        if (hiddenCustomer) hiddenCustomer.value = customerInput?.value ?? '';
                        if (hiddenMappedCustomer) hiddenMappedCustomer.value = mappedCustomer;
                        if (submit) {
                            submit.disabled = mappedCustomer === '__unmapped__';
                            submit.textContent = mappedCustomer === '__unmapped__'
                                ? 'Mapear antes de enviar'
                                : (customer || mappedCustomer ? 'Enviar filtradas a Packiyo' : 'Enviar todas a Packiyo');
                        }
                    }
                };

                jtlFilterForm.addEventListener('submit', (event) => {
                    event.preventDefault();
                    applyJtlFilters();
                });
                customerInput?.addEventListener('input', applyJtlFilters);
                mappedCustomerSelect?.addEventListener('change', applyJtlFilters);
                clearButton?.addEventListener('click', () => {
                    if (customerInput) customerInput.value = '';
                    if (mappedCustomerSelect) mappedCustomerSelect.value = '';
                    applyJtlFilters();
                });
                applyJtlFilters();
            } else {
                document.querySelector('[data-clear-jtl-filters]')?.addEventListener('click', (event) => {
                    const url = event.currentTarget.dataset.clearUrl;
                    if (url) window.location.assign(url);
                });
            }

            const correctionForm = document.querySelector('[data-correction-selected-form]');
            if (correctionForm) {
                const table = document.querySelector('[data-sort-table="order-corrections"]');
                const checkboxes = Array.from(document.querySelectorAll('[data-correction-order-checkbox]'))
                    .filter((checkbox) => !checkbox.disabled);
                const selectAll = document.querySelector('[data-correction-select-all]');
                const count = correctionForm.querySelector('[data-correction-selection-count]');
                const executeButton = correctionForm.querySelector('[data-correction-execute]');

                const updateCorrectionSelection = () => {
                    const selected = checkboxes.filter((checkbox) => checkbox.checked).length;
                    if (count) count.textContent = `${selected} orden(es) seleccionada(s)`;
                    if (selectAll) {
                        const visible = checkboxes.filter((checkbox) => !checkbox.closest('tr')?.hidden);
                        selectAll.checked = visible.length > 0 && visible.every((checkbox) => checkbox.checked);
                        selectAll.indeterminate = visible.some((checkbox) => checkbox.checked) && !selectAll.checked;
                    }
                };

                selectAll?.addEventListener('change', () => {
                    checkboxes.forEach((checkbox) => {
                        if (!checkbox.closest('tr')?.hidden) checkbox.checked = selectAll.checked;
                    });
                    updateCorrectionSelection();
                });
                checkboxes.forEach((checkbox) => checkbox.addEventListener('change', updateCorrectionSelection));
                correctionForm.addEventListener('submit', (event) => {
                    const selected = checkboxes.filter((checkbox) => checkbox.checked).length;
                    if (selected === 0) {
                        event.preventDefault();
                        window.alert('Selecciona al menos una orden.');
                        return;
                    }
                    if (event.submitter === executeButton) {
                        if (selected > 10) {
                            event.preventDefault();
                            window.alert('Selecciona como maximo 10 ordenes por ejecucion.');
                            return;
                        }
                        if (!window.confirm(`Se corregiran ${selected} orden(es) en Packiyo. ¿Quieres continuar?`)) {
                            event.preventDefault();
                        }
                    }
                });
                table?.addEventListener('input', updateCorrectionSelection);
                updateCorrectionSelection();
            }
        })();
    </script>
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

    /** @param array<string, mixed>|null $state */
    private function renderAutomation(?array $state): string
    {
        $enabled = (bool) Config::get('automation.enabled', true);
        $intervalMinutes = max(1, (int) Config::get('automation.interval_minutes', 360));
        $lastRun = $state['last_success_at'] ?? null;
        $nextRun = $this->automationNextRunAt(is_string($lastRun) ? $lastRun : null, $intervalMinutes, $enabled);
        $tokenConfigured = trim((string) Config::get('automation.token', '')) !== '';
        $tickUrl = $this->automationTickUrl();
        ob_start();
        ?>
            <section>
                <div class="section-head">
                    <h2>Automatizacion</h2>
                    <form action="<?= $this->e($this->url('/automation/manual')) ?>" method="post">
                        <button class="button" type="submit">Ejecutar ahora</button>
                    </form>
                </div>
                <div class="section-body">
                    <div class="details">
                        <div class="detail">
                            <span>Estado</span>
                            <strong><span class="status <?= $enabled ? 'active' : 'inactive' ?>"><?= $enabled ? 'activa' : 'inactiva' ?></span></strong>
                        </div>
                        <div class="detail">
                            <span>Intervalo</span>
                            <strong><?= $this->e((string) $intervalMinutes) ?> min</strong>
                        </div>
                        <div class="detail">
                            <span>Ultima corrida</span>
                            <strong><?= $this->e(is_string($lastRun) && $lastRun !== '' ? $lastRun : '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Proxima corrida</span>
                            <strong><?= $this->e($nextRun) ?></strong>
                        </div>
                        <div class="detail">
                            <span>Cron HTTP</span>
                            <strong><span class="status <?= $tokenConfigured ? 'configured' : 'missing_config' ?>"><?= $tokenConfigured ? 'configurado' : 'sin_token' ?></span></strong>
                        </div>
                    </div>

                    <?php if (is_string($state['last_message'] ?? null) && $state['last_message'] !== ''): ?>
                        <div class="field-hint">Ultimo resultado: <?= $this->e((string) $state['last_message']) ?></div>
                    <?php endif; ?>

                    <div class="field-hint">Configura un cron frecuente, por ejemplo cada 5 minutos. La ruta <code>/automation/tick</code> revisa el intervalo y solo ejecuta el flujo completo cuando ya toca.</div>

                    <pre class="code-block"><code>*/5 * * * * curl -fsS -H "X-Automation-Token: &lt;AUTOMATION_TOKEN&gt;" <?= $this->e($tickUrl) ?></code></pre>
                    <pre class="code-block"><code>*/5 * * * * php /ruta/al/proyecto/cron/automation.php</code></pre>

                    <?php if (!$tokenConfigured): ?>
                        <div class="notice">Configura AUTOMATION_TOKEN en Ajustes para usar el cron HTTP. Si usas cron CLI dentro del mismo servidor, el token HTTP no es necesario.</div>
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

    /** @param array<string, mixed>|null $detail */
    private function renderOrderDetail(?array $detail, ?string $error): string
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
                            <div class="detail"><span>ID JTL</span><strong><?= $this->e($detail['id'] ?? '-') ?></strong></div>
                            <div class="detail"><span>Cliente Packiyo</span><strong><?= $this->e(($detail['customer_name'] ?? '-') . ' #' . ($detail['customer_id'] ?? '-')) ?></strong></div>
                            <div class="detail"><span>Estado</span><strong><span class="status <?= $sent ? 'synced' : (($detail['errors'] ?? []) === [] ? 'ready' : 'missing_config') ?>"><?= $sent ? 'enviada' : (($detail['errors'] ?? []) === [] ? 'lista' : 'requiere_revision') ?></span></strong></div>
                            <div class="detail"><span>Borrador</span><strong><?= $this->e($detail['draft']['updated_at'] ?? 'sin guardar') ?></strong></div>
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
                                        <td data-sort-value="<?= $this->e(($order['number'] ?? '') ?: ($order['id'] ?? '')) ?>">
                                            <strong><?= $this->e(($order['number'] ?? '') ?: ($order['id'] ?? '-')) ?></strong>
                                            <div class="muted">ID <?= $this->e(($order['id'] ?? '') ?: '-') ?></div>
                                            <?php if (($order['marketplace_number'] ?? '') !== ''): ?>
                                                <div class="muted">Marketplace <?= $this->e($order['marketplace_number']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-sort-value="<?= $this->e($order['ordered_at'] ?? '') ?>"><?= $this->e($order['ordered_at'] ?? '-') ?></td>
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
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>|null $state
     * @param array<int, array<string, mixed>> $activeCustomers
     */
    private function renderFulfillment(array $rows, ?array $state, array $activeCustomers, string $selectedCustomerId): string
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
                        <div class="empty">Todavia no hay tracking enviado a JTL.</div>
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
                                        <td data-sort-value="<?= $this->e(($row['jtl_order_number'] ?? '') ?: ($row['jtl_order_id'] ?? '')) ?>">
                                            <strong><?= $this->e(($row['jtl_order_number'] ?? '') ?: ($row['jtl_order_id'] ?? '-')) ?></strong>
                                            <div class="muted">ID <?= $this->e($row['jtl_order_id'] ?? '-') ?></div>
                                        </td>
                                        <td data-sort-value="<?= $this->e($row['packiyo_order_id'] ?? '') ?>"><?= $this->e($row['packiyo_order_id'] ?? '-') ?></td>
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
                                                    $fulfillmentStatus === 'failed' => 'failed',
                                                    default => 'missing_config',
                                                };
                                            ?>
                                            <span class="status <?= $this->e($fulfillmentStatusClass) ?>"><?= $this->e($fulfillmentStatus ?: '-') ?></span>
                                            <?php if ($fulfillmentStatus === 'failed' && ($row['last_error'] ?? '') !== ''): ?>
                                                <div class="muted"><?= $this->e($row['last_error']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-sort-value="<?= $this->e($row['synced_at'] ?? '') ?>"><?= $this->e($row['synced_at'] ?? '-') ?></td>
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
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mappings as $mapping): ?>
                                <tr>
                                    <td><?= $this->e($mapping['jtl_order_number'] ?: $mapping['jtl_order_id']) ?></td>
                                    <td><?= $this->e($mapping['packiyo_order_number'] ?: $mapping['packiyo_order_id']) ?></td>
                                    <td><?= $this->e($mapping['synced_at']) ?></td>
                                    <td>synced</td>
                                    <td><a class="button secondary small button-link" href="<?= $this->e($this->url('/?') . http_build_query(['tab' => 'jtl-orders', 'order_reference' => $mapping['jtl_order_id']])) ?>">Editar y mapear</a></td>
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

    /** @param array<string, mixed> $data */
    private function renderOrderCorrections(array $data, ?string $error): string
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
                    <strong><?= $writeEnabled ? 'Escritura atomica habilitada' : 'Modo lectura/simulacion' ?></strong>.
                    <?php if (!$writeEnabled): ?>
                        Las acciones remotas estan bloqueadas. Usa la previsualizacion y el CSV hasta confirmar un endpoint atomico con una orden de prueba.
                    <?php else: ?>
                        Cada orden se vuelve a leer, se valida y se verifica despues de escribir; se procesan como maximo diez por lote.
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
                        <form id="correction-selected-orders-form" method="post" action="<?= $this->e($this->url('/order-corrections/preview')) ?>" class="correction-selection-bar" data-correction-selected-form>
                            <input type="hidden" name="job_id" value="<?= $this->e($jobId) ?>">
                            <input type="hidden" name="selection_mode" value="orders">
                            <strong>Ordenes seleccionadas</strong>
                            <span class="selection-count" data-correction-selection-count>0 orden(es) seleccionada(s)</span>
                            <button class="button secondary small" type="submit">Previsualizar seleccionadas</button>
                            <button class="button danger small" type="submit" formaction="<?= $this->e($this->url('/order-corrections/execute')) ?>" data-correction-execute <?= $writeEnabled ? '' : 'disabled' ?>>Corregir seleccionadas en Packiyo</button>
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
                                        $orderSelectable = $showOrderCheckbox
                                            && OrderCorrectionService::isEditableStatus((string) ($line['packiyo_status'] ?? ''));
                                        $renderedOrderIds[$orderId] = true;
                                        $catalog = is_array($catalogs[$customerId] ?? null) ? $catalogs[$customerId] : [];
                                        $suggestions = is_array($line['suggestions'] ?? null) ? $line['suggestions'] : [];
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ($showOrderCheckbox): ?>
                                                <input class="order-checkbox" type="checkbox" name="order_ids[]" value="<?= $this->e($orderId) ?>" form="correction-selected-orders-form" data-correction-order-checkbox aria-label="Seleccionar orden <?= $this->e($line['packiyo_order_number'] ?: $orderId) ?>" <?= $orderSelectable ? '' : 'disabled title="El estado de esta orden no permite modificarla"' ?>>
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

    private function renderSettings(): string
    {
        $database = Config::get('database.mysql', []);
        $users = (new AppUser())->all();
        $invitations = (new UserInvitation())->recent(500);
        $workflowEvents = $this->cachedSalesOrderWorkflowEvents();
        $workflowEventsReadAt = $this->cachedSessionString('jtl_sales_order_workflow_events_read_at');

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

                    <h3>Workflow events de Aufträge</h3>
                    <p class="muted">Usa esto para sacar el ID del evento manual que crea el Lieferschein. Copia el numero en JTL_DELIVERY_NOTE_WORKFLOW_EVENT_ID.</p>
                    <div class="jtl-worker-actions">
                        <form class="inline-form" action="<?= $this->e($this->url('/jtl/workflows/sales-order-events')) ?>" method="post">
                            <button class="button secondary" type="submit">Leer workflow events de JTL</button>
                        </form>
                    </div>

                    <?php if ($workflowEventsReadAt !== null): ?>
                        <div class="field-hint">Leido desde JTL: <?= $this->e($workflowEventsReadAt) ?></div>
                    <?php endif; ?>

                    <?php if ($workflowEvents !== []): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nombre</th>
                                    <th>Raw</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($workflowEvents as $event): ?>
                                    <tr>
                                        <td><strong><?= $this->e($this->workflowEventId($event) ?: '-') ?></strong></td>
                                        <td><?= $this->e($this->workflowEventName($event) ?: '-') ?></td>
                                        <td class="muted"><?= $this->e($this->shortJson($event, 260)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty">Todavia no hay workflow events leidos. Crea el evento manual en JTL-Wawi y usa el boton para leer su ID.</div>
                    <?php endif; ?>

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
            'JTL_SALES_ORDER_WORKFLOW_EVENTS_ENDPOINT',
            'JTL_SALES_ORDER_WORKFLOW_TRIGGER_ENDPOINT',
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
                <?php if ($key === 'JTL_WORKER_DISCOVERY_ENABLED'): ?>
                    <div class="field-hint">Solo activa esto para diagnostico. El marketplace abgleich debe ejecutarlo JTL Worker 2.0 en JTL-Wawi, no esta app.</div>
                <?php endif; ?>
                <?php if ($key === 'JTL_AUTO_CREATE_DELIVERY_NOTE'): ?>
                    <div class="field-hint">Activalo solo despues de crear en JTL-Wawi un workflow manual de Auftrag/Sales Order que genere el Lieferschein.</div>
                <?php endif; ?>
                <?php if ($key === 'JTL_DELIVERY_NOTE_WORKFLOW_EVENT_ID'): ?>
                    <div class="field-hint">Es el ID numerico del workflow event de Auftrag/Sales Order que debe crear el delivery note.</div>
                <?php endif; ?>
                <?php if ($key === 'AUTOMATION_INTERVAL_MINUTES'): ?>
                    <div class="field-hint">360 minutos son 6 horas. El cron puede llamar /automation/tick cada 5 minutos; la app decide si ya toca correr.</div>
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
            return implode(',', JtlScopeList::mandatoryFromConfigured((string) $value));
        }

        return is_scalar($value) ? (string) $value : $default;
    }

    private function settingConfigured(string $key): bool
    {
        return Setting::configured($key);
    }

    private function noticeFromRequest(mixed $queryNotice): mixed
    {
        if (is_string($queryNotice) && $queryNotice !== '') {
            return $this->compactNotice($queryNotice);
        }

        if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $notice = $_SESSION['flash_notice'] ?? null;
        unset($_SESSION['flash_notice']);

        return is_string($notice) ? $this->compactNotice($notice) : $notice;
    }

    private function compactNotice(string $notice): string
    {
        $limit = 1600;

        if (strlen($notice) <= $limit) {
            return $notice;
        }

        return substr($notice, 0, $limit - 70)
            . '... Detalle completo guardado en Logs.';
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

    private function cachedSessionString(string $key): ?string
    {
        if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $value = $_SESSION[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<int, array<string, mixed>> */
    private function cachedSalesOrderWorkflowEvents(): array
    {
        if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $events = $_SESSION['jtl_sales_order_workflow_events'] ?? [];

        if (!is_array($events)) {
            return [];
        }

        $items = [];

        foreach ($events as $event) {
            if (is_array($event)) {
                $items[] = $event;
            }
        }

        return $items;
    }

    /** @param array<string, mixed> $event */
    private function workflowEventId(array $event): string
    {
        return $this->firstScalar($event, ['Id', 'id', 'ID']) ?? '';
    }

    /** @param array<string, mixed> $event */
    private function workflowEventName(array $event): string
    {
        return $this->firstScalar($event, ['Name', 'name', 'DisplayName', 'displayName']) ?? '';
    }

    private function activeTab(mixed $tab): string
    {
        $tab = is_string($tab) ? $tab : 'overview';
        $allowed = ['overview', 'jtl-orders', 'fulfillment', 'order-corrections', 'packiyo-customers', 'customer-mappings', 'products', 'settings', 'logs'];

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
        PackiyoClient $packiyo,
        string $verifyPackiyoOrderReference = ''
    ): array
    {
        $mapper = new MappingService($orderMappings);
        $resolver = new PackiyoCustomerResolver($customerMappings);
        $rows = [];
        $orderIds = [];
        foreach ($orders as $candidateOrder) {
            $candidateId = $mapper->jtlOrderId($candidateOrder);
            if ($candidateId !== null) $orderIds[] = $candidateId;
        }
        $skipReasons = (new AutomationOrderSkip())->findReasonsForOrderIds($orderIds);
        $customerMappingRows = $customerMappings->all();
        $orderMappingsByJtlOrderId = $orderMappings->findByJtlOrderIds($orderIds);

        foreach ($orders as $order) {
            $id = $mapper->jtlOrderId($order);
            $number = $mapper->jtlOrderNumber($order);
            $marketplaceNumber = $mapper->marketplaceOrderNumber($order);
            $candidates = $resolver->candidates($order);
            $mapping = $customerMappings->findForCandidatesIn($customerMappingRows, $candidates);
            $source = $this->primaryOrderSource($candidates);
            $orderMapping = $id !== null ? ($orderMappingsByJtlOrderId[$id] ?? null) : null;
            $shouldVerifyPackiyo = $verifyPackiyoOrderReference !== ''
                && in_array($verifyPackiyoOrderReference, array_filter([$id, $number]), true);
            $syncState = $this->packiyoSyncState($orderMapping, $packiyo, $id, $shouldVerifyPackiyo);

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
                'readonly' => $orderMapping !== null,
                'sync_state' => $syncState['state'],
                'sync_message' => $syncState['message'],
                'review_required' => ($skipReasons[$id ?? ''] ?? '') === 'requires_review',
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
    private function packiyoSyncState(
        ?array $orderMapping,
        PackiyoClient $packiyo,
        ?string $jtlOrderId,
        bool $verifyRemotely = false
    ): array
    {
        if ($orderMapping === null) {
            return ['state' => 'not_synced', 'message' => ''];
        }

        $packiyoOrderId = trim((string) ($orderMapping['packiyo_order_id'] ?? ''));

        if ($packiyoOrderId === '') {
            return ['state' => 'local_only', 'message' => 'El mapeo local no tiene Packiyo order ID.'];
        }

        if (!$verifyRemotely) {
            return [
                'state' => 'local_mapping',
                'message' => 'La orden se envio anteriormente; verifica Packiyo solo si necesitas confirmarla.',
            ];
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

    private function automationNextRunAt(?string $lastRun, int $intervalMinutes, bool $enabled): string
    {
        if (!$enabled) {
            return '-';
        }

        if ($lastRun === null || trim($lastRun) === '') {
            return 'proximo tick';
        }

        $timestamp = strtotime($lastRun);

        if ($timestamp === false) {
            return 'proximo tick';
        }

        $nextRun = $timestamp + ($intervalMinutes * 60);

        if ($nextRun <= time()) {
            return 'proximo tick';
        }

        return date('Y-m-d H:i:s', $nextRun);
    }

    /** @param array<string, mixed>|null $state */
    private function automationLastSummary(?array $state): string
    {
        $message = $state['last_message'] ?? null;

        if (!is_string($message) || trim($message) === '') {
            return 'Sin corridas previas.';
        }

        $message = trim($message);

        return strlen($message) > 96 ? substr($message, 0, 93) . '...' : $message;
    }

    private function automationTickUrl(): string
    {
        $configured = trim((string) Config::get('app.base_url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/') . $this->url('/automation/tick');
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : 'localhost';

        return $scheme . '://' . $host . $this->url('/automation/tick');
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
