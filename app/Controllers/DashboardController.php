<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Clients\JtlClient;
use App\Clients\PackiyoClient;
use App\Models\AppSyncState;
use App\Models\AutomationOrderSkip;
use App\Models\JtlApiCredential;
use App\Models\JtlOrderSource;
use App\Models\OrderMapping;
use App\Models\OrderSyncFailure;
use App\Models\PackiyoCustomer;
use App\Models\PackiyoCustomerMapping;
use App\Models\ProductNameMapping;
use App\Models\ProductSkuAlias;
use App\Models\SyncLog;
use App\Services\MappingService;
use App\Services\OrderCorrectionService;
use App\Services\OrderDetailService;
use App\Services\OrderPreparationService;
use App\Services\PackiyoCustomerResolver;
use App\Services\ProductImportService;
use App\Services\ProductSkuAliasService;
use App\Support\Arr;
use App\Support\Config;
use App\Support\HttpException;
use App\Views\Dashboard\DashboardPage;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $mappings = new OrderMapping();
        $logs = new SyncLog();
        $credentials = new JtlApiCredential();
        $orderSources = new JtlOrderSource();
        $customerMappings = new PackiyoCustomerMapping();
        $packiyoCustomers = new PackiyoCustomer();
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
        echo (new DashboardPage())->render(
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
            $mappings->operationalFulfillmentRows(500, $fulfillmentCustomerId),
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
        $syncFailures = (new OrderSyncFailure())->findByJtlOrderIds($orderIds);
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
                'sync_error' => $orderMapping === null ? (string) ($syncFailures[$id ?? '']['last_error'] ?? '') : '',
                'sync_error_at' => $orderMapping === null ? (string) ($syncFailures[$id ?? '']['last_failed_at'] ?? '') : '',
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
        $attributes = Arr::firstArray($order, ['attributes', 'Attributes']);
        $archivedAt = Arr::firstScalar($attributes, ['archived_at', 'archivedAt']);
        $deletedAt = Arr::firstScalar($attributes, ['deleted_at', 'deletedAt']);

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
        return Arr::firstScalar($order, [
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
        $customer = Arr::firstArray($order, ['customer', 'Customer', 'customer_data', 'CustomerData', 'client', 'Client']);
        $billing = Arr::firstArray($order, ['billing_address', 'billingAddress', 'BillingAddress', 'invoiceAddress', 'InvoiceAddress']);
        $shipping = Arr::firstArray($order, ['shipping_address', 'shippingAddress', 'ShippingAddress', 'deliveryAddress', 'DeliveryAddress', 'Shipmentaddress', 'ShipmentAddress', 'shipmentAddress']);

        return $this->fullName($shipping)
            ?? $this->fullName($billing)
            ?? $this->fullName($customer)
            ?? Arr::firstScalar($shipping, ['email', 'Email', 'mail', 'Mail', 'EmailAddress'])
            ?? Arr::firstScalar($billing, ['email', 'Email', 'mail', 'Mail', 'EmailAddress']);
    }

    /** @param array<string, mixed> $data */
    private function fullName(array $data): ?string
    {
        $fullName = Arr::firstScalar($data, ['name', 'Name', 'full_name', 'fullName', 'FullName']);

        if ($fullName !== null) {
            return $fullName;
        }

        $firstName = Arr::firstScalar($data, ['first_name', 'firstName', 'firstname', 'FirstName']);
        $lastName = Arr::firstScalar($data, ['last_name', 'lastName', 'lastname', 'LastName']);
        $name = trim((string) ($firstName . ' ' . $lastName));

        return $name !== '' ? $name : null;
    }
}
