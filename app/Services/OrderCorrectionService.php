<?php

declare(strict_types=1);

namespace App\Services;

use App\Clients\JtlClient;
use App\Clients\PackiyoClient;
use App\Models\OrderCorrection;
use App\Models\OrderDraft;
use App\Models\OrderMapping;
use App\Models\PackiyoCustomer;
use App\Models\ProductNameMapping;
use App\Models\ProductSkuAlias;
use App\Support\Config;
use App\Support\HttpException;
use App\Support\Setting;
use RuntimeException;
use Throwable;

final class OrderCorrectionService
{
    public function __construct(
        private readonly ?PackiyoClient $packiyo = null,
        private readonly ?JtlClient $jtl = null,
        private readonly ?OrderCorrection $corrections = null,
        private readonly ?OrderMapping $mappings = null,
        private readonly ?OrderDraft $drafts = null,
        private readonly ?OrderPreparationService $preparation = null,
        private readonly ?PackiyoCustomer $customers = null
    ) {
    }

    /** @param array<int, string> $customerIds @return array<string, mixed> */
    public function start(string $startDate, array $customerIds = [], ?int $userId = null): array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($startDate));
        if ($start === false || $start->format('Y-m-d') !== trim($startDate)) {
            throw new RuntimeException('Selecciona una fecha de inicio valida.');
        }
        $today = new \DateTimeImmutable('today');
        if ($start > $today) {
            throw new RuntimeException('La fecha de inicio no puede estar en el futuro.');
        }
        $windowStart = $start->format('Y-m-d 00:00:00');

        $activeCustomers = $this->customerModel()->listByActive(true);
        $activeIds = array_values(array_filter(array_map(
            static fn (array $customer): string => trim((string) ($customer['packiyo_customer_id'] ?? '')),
            $activeCustomers
        )));
        $selectedIds = self::normalizeCustomerIds($customerIds);
        if ($selectedIds === []) {
            throw new RuntimeException('Selecciona al menos un cliente activo para el analisis.');
        }
        $invalidIds = array_diff($selectedIds, $activeIds);
        if ($invalidIds !== []) {
            throw new RuntimeException('La seleccion contiene clientes inactivos o desconocidos. Actualiza la pagina e intentalo de nuevo.');
        }

        $job = $this->store()->createJob($windowStart, $selectedIds, $userId);
        return $this->continue((string) $job['id']);
    }

    /** @return array<string, mixed> */
    public function continue(string $jobId, int $maxPages = 10, float $timeBudgetSeconds = 20.0): array
    {
        $job = $this->requireJob($jobId);
        if ((string) $job['status'] === 'completed') {
            return $job;
        }

        $started = microtime(true);
        $page = max(1, (int) $job['cursor_page']);
        $offset = max(0, (int) ($job['cursor_offset'] ?? 0));
        $selectedCustomerIds = self::jobCustomerIds($job);
        $totalScanned = 0;
        $totalDetected = 0;

        try {
            for ($processed = 0; $processed < max(1, min(10, $maxPages)); $processed++) {
                if ($processed > 0 && microtime(true) - $started >= $timeBudgetSeconds) {
                    break;
                }

                $response = $this->packiyoClient()->listOrdersPage(
                    (string) $job['window_start'],
                    (string) $job['window_end'],
                    $page,
                    100
                );
                $orders = self::resources($response);
                $detected = 0;
                for ($index = $offset; $index < count($orders); $index++) {
                    $withinWindow = self::isOrderWithinWindow(
                        $orders[$index],
                        (string) $job['window_start'],
                        (string) $job['window_end']
                    );
                    $selectedCustomer = self::isOrderForSelectedCustomers($orders[$index], $selectedCustomerIds);
                    $reviewed = $withinWindow && $selectedCustomer ? 1 : 0;
                    $lineCount = $reviewed === 1 ? $this->analyzeOrder($jobId, $orders[$index], $response) : 0;
                    $detected += $lineCount;
                    $totalScanned += $reviewed;
                    $totalDetected += $lineCount;
                    $offset = $index + 1;
                    $this->store()->advanceJob($jobId, $page, $offset, $reviewed, $lineCount, 'running');
                    if (microtime(true) - $started >= $timeBudgetSeconds && $offset < count($orders)) {
                        return $this->requireJob($jobId);
                    }
                }

                $finished = $orders === [] || !self::hasNextPage($response, $page, 100);
                $page++;
                $offset = 0;
                $this->store()->advanceJob(
                    $jobId,
                    $page,
                    $offset,
                    0,
                    0,
                    $finished ? 'completed' : 'running'
                );
                if ($finished || microtime(true) - $started >= $timeBudgetSeconds) {
                    break;
                }
            }
        } catch (Throwable $exception) {
            $this->store()->advanceJob($jobId, $page, $offset, 0, 0, 'failed', $exception->getMessage());
        }

        return $this->requireJob($jobId);
    }

    /** @return array<string, mixed> */
    public function dashboard(?string $jobId = null, array $filters = []): array
    {
        $job = $jobId !== null && $jobId !== '' ? $this->store()->job($jobId) : $this->store()->latestJob();
        $lines = $job !== null ? $this->store()->lines((string) $job['id'], $filters) : [];
        $catalogs = [];
        foreach ($lines as $line) {
            $customerId = trim((string) ($line['packiyo_customer_id'] ?? ''));
            if ($customerId !== '' && !isset($catalogs[$customerId])) {
                $catalogs[$customerId] = $this->preparationService()->catalog($customerId, false);
            }
        }
        return [
            'job' => $job,
            'lines' => $lines,
            'active_customers' => $this->customerModel()->listByActive(true),
            'selected_customer_ids' => $job !== null ? self::jobCustomerIds($job) : [],
            'summary' => $job !== null ? $this->store()->summary((string) $job['id']) : [
                'jtl_matches' => 0,
                'packiyo_assignments' => 0,
            ],
            'catalogs' => $catalogs,
            'write_enabled' => $this->writeEnabled(),
            'test_write_enabled' => $this->testWriteEnabled(),
        ];
    }

    /** @param array<int, int> $lineIds */
    public function assign(string $jobId, array $lineIds, string $productId, string $scope): int
    {
        $lines = $this->store()->selectedLines($jobId, $lineIds);
        if ($lines === []) {
            throw new RuntimeException('Selecciona al menos una linea.');
        }
        $scope = $scope === 'group' ? 'group' : 'line';
        $updated = 0;

        foreach ($lines as $line) {
            $customerId = trim((string) ($line['packiyo_customer_id'] ?? ''));
            if ($customerId === '') {
                $this->store()->markResult((int) $line['id'], 'failed', 'La orden no tiene cliente Packiyo.');
                continue;
            }
            $product = $this->catalogProduct($customerId, $productId);
            if ($product === null) {
                throw new RuntimeException('El producto no esta activo o no pertenece al cliente Packiyo de la linea.');
            }
            $sourceName = trim((string) ($line['jtl_name'] ?: $line['original_name']));
            $key = $customerId . ':' . OrderPreparationService::normalizeName($sourceName);
            if ($scope === 'group') {
                if ($sourceName === '') {
                    throw new RuntimeException('Una asignacion masiva requiere un nombre JTL utilizable.');
                }
                $this->store()->saveGroupAssignment($customerId, $sourceName, $product);
                foreach ($this->store()->lines($jobId, ['customer' => $customerId], 5000) as $candidate) {
                    $candidateName = trim((string) ($candidate['jtl_name'] ?: $candidate['original_name']));
                    if (OrderPreparationService::normalizeName($candidateName) === OrderPreparationService::normalizeName($sourceName)) {
                        $this->store()->assignLine((int) $candidate['id'], $product, 'group', $key);
                        $updated++;
                    }
                }
                continue;
            }
            $this->store()->assignLine((int) $line['id'], $product, 'line', $key . ':' . $line['packiyo_order_id']);
            $updated++;
        }
        return $updated;
    }

    /** @param array<int, int> $lineIds @return array<string, mixed> */
    public function preview(string $jobId, array $lineIds, ?int $userId = null): array
    {
        $lines = $this->assignedLines($jobId, $lineIds);
        $orders = [];
        foreach ($this->groupByOrder($lines) as $orderId => $orderLines) {
            $orderId = (string) $orderId;
            try {
                $current = $this->packiyoClient()->getOrder($orderId);
                $currentItems = self::extractPackiyoLineItems($current);
                $status = self::orderStatus($current);
                self::assertCustomerUnchanged($current, $orderLines);
                if (!self::isEditableStatus($status)) {
                    throw new RuntimeException('Estado Packiyo no editable: ' . ($status ?: 'desconocido'));
                }
                $payload = self::buildReplacementPayload($orderId, $current, $currentItems, $orderLines);
                $hash = self::snapshotHash($currentItems);
                foreach ($orderLines as $line) {
                    $this->store()->markPreview((int) $line['id'], $hash);
                    $this->store()->addAttempt(
                        $jobId, (int) $line['id'], $orderId, 'preview',
                        $currentItems, $payload, 'previewed', null, $userId
                    );
                }
                $orders[$orderId] = ['status' => 'ready', 'payload' => $payload, 'hash' => $hash];
            } catch (Throwable $exception) {
                foreach ($orderLines as $line) {
                    $this->store()->markResult((int) $line['id'], 'skipped', $exception->getMessage());
                }
                $orders[$orderId] = ['status' => 'skipped', 'error' => $exception->getMessage()];
            }
        }
        return ['orders' => $orders, 'write_enabled' => $this->writeEnabled()];
    }

    /** @param array<int, string> $orderIds @return array<string, mixed> */
    public function previewOrders(string $jobId, array $orderIds, ?int $userId = null): array
    {
        return $this->preview($jobId, $this->lineIdsForOrders($jobId, $orderIds), $userId);
    }

    /** @param array<int, int> $lineIds @return array<string, int> */
    public function execute(string $jobId, array $lineIds, ?int $userId = null, bool $singleOrderTest = false): array
    {
        if (!$this->writeEnabled() && !$singleOrderTest) {
            throw new RuntimeException(
                'Modo simulacion activo. Configure y confirme primero un endpoint Packiyo de reemplazo atomico.'
            );
        }
        $lines = array_slice($this->assignedLines($jobId, $lineIds), 0, 100);
        $summary = ['corrected' => 0, 'skipped' => 0, 'failed' => 0];
        $orderCount = 0;
        $orderLimit = $singleOrderTest ? 1 : 10;
        foreach ($this->groupByOrder($lines) as $orderId => $orderLines) {
            $orderId = (string) $orderId;
            if (++$orderCount > $orderLimit) {
                break;
            }
            try {
                $current = $this->packiyoClient()->getOrder($orderId);
                $items = self::extractPackiyoLineItems($current);
                $status = self::orderStatus($current);
                self::assertCustomerUnchanged($current, $orderLines);
                if (!self::isEditableStatus($status)) {
                    throw new RuntimeException('Estado Packiyo no editable: ' . ($status ?: 'desconocido'));
                }
                if (self::alreadyCorrected($items, $orderLines)) {
                    foreach ($orderLines as $line) {
                        $this->store()->markResult((int) $line['id'], 'already_corrected');
                        $this->store()->addAttempt($jobId, (int) $line['id'], $orderId, 'execute', $items, $current, 'already_corrected', null, $userId);
                        $summary['corrected']++;
                    }
                    continue;
                }
                $currentHash = self::snapshotHash($items);
                foreach ($orderLines as $line) {
                    $expectedHash = (string) ($line['preview_hash'] ?? '');
                    if ($expectedHash === '' || !hash_equals($expectedHash, $currentHash)) {
                        throw new RuntimeException('Todas las lineas requieren una previsualizacion vigente del mismo snapshot.');
                    }
                }
                foreach ($orderLines as $line) {
                    if ($this->catalogProduct((string) $line['packiyo_customer_id'], (string) $line['proposed_product_id']) === null) {
                        throw new RuntimeException('El producto destino ya no esta activo para este cliente.');
                    }
                }
                $payload = self::buildReplacementPayload($orderId, $current, $items, $orderLines);
                $this->atomicReplaceWithRetry($orderId, $payload, $singleOrderTest);
                $after = $this->packiyoClient()->getOrder($orderId);
                self::verifyReplacement($items, self::extractPackiyoLineItems($after), $orderLines);
                foreach ($orderLines as $line) {
                    $this->store()->markResult((int) $line['id'], 'corrected');
                    $this->store()->addAttempt($jobId, (int) $line['id'], $orderId, 'execute', $items, $after, 'corrected', null, $userId);
                    $summary['corrected']++;
                }
                if ($singleOrderTest) {
                    $this->confirmAtomicWriteSettings();
                }
            } catch (Throwable $exception) {
                $kind = str_contains(strtolower($exception->getMessage()), 'no editable')
                    || str_contains(strtolower($exception->getMessage()), 'cambio desde') ? 'skipped' : 'failed';
                foreach ($orderLines as $line) {
                    $this->store()->markResult((int) $line['id'], $kind, $exception->getMessage());
                    $this->store()->addAttempt($jobId, (int) $line['id'], $orderId, 'execute', $line['current_snapshot'], null, $kind, $exception->getMessage(), $userId);
                    $summary[$kind]++;
                }
            }
        }
        return $summary;
    }

    /** @param array<int, string> $orderIds @return array<string, int> */
    public function executeOrders(string $jobId, array $orderIds, ?int $userId = null): array
    {
        $orderIds = self::normalizeOrderIds($orderIds);
        $singleOrderTest = !$this->writeEnabled();
        $limit = $singleOrderTest ? 1 : 10;
        if (count($orderIds) > $limit) {
            throw new RuntimeException($singleOrderTest
                ? 'Antes de habilitar los lotes debes probar con exactamente una orden.'
                : 'Selecciona como maximo 10 ordenes por ejecucion.');
        }
        return $this->execute($jobId, $this->lineIdsForOrders($jobId, $orderIds), $userId, $singleOrderTest);
    }

    /** @param array<int, int> $lineIds */
    public function csv(string $jobId, array $lineIds = []): string
    {
        $lines = $lineIds === [] ? $this->store()->lines($jobId, [], 5000) : $this->store()->selectedLines($jobId, $lineIds);
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('No se pudo generar el CSV.');
        }
        fputcsv($stream, [
            'packiyo_order_id', 'packiyo_order_number', 'packiyo_customer_id', 'packiyo_status',
            'line_index', 'original_external_id', 'original_sku', 'jtl_name', 'quantity', 'price',
            'target_product_id', 'target_sku', 'target_name', 'result', 'error',
        ]);
        foreach ($lines as $line) {
            fputcsv($stream, [
                $line['packiyo_order_id'], $line['packiyo_order_number'], $line['packiyo_customer_id'],
                $line['packiyo_status'], $line['line_index'], $line['original_external_id'],
                $line['original_sku'], $line['jtl_name'], $line['quantity'], $line['price'],
                $line['proposed_product_id'], $line['proposed_sku'], $line['proposed_name'],
                $line['result'], $line['error'],
            ]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return "\xEF\xBB\xBF" . ($csv === false ? '' : $csv);
    }

    /** @return array<int, array<string, mixed>> */
    public static function extractPackiyoLineItems(array $response): array
    {
        $resource = self::firstResource($response);
        $includedValue = $response['included'] ?? $response['Included'] ?? [];
        $included = is_array($includedValue) ? $includedValue : [];
        $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : $resource;
        foreach (['order_item_data', 'order_items', 'orderItems', 'line_items', 'lineItems', 'items'] as $key) {
            if (isset($attributes[$key]) && is_array($attributes[$key])) {
                return self::normalizeLineList($attributes[$key], $included);
            }
        }
        foreach (['order_items', 'orderItems', 'line_items', 'lineItems', 'items'] as $key) {
            $related = $resource['relationships'][$key]['data']
                ?? $resource['Relationships'][$key]['Data']
                ?? null;
            if (!is_array($related)) {
                continue;
            }
            $refs = array_is_list($related) ? $related : [$related];
            $lines = [];
            foreach ($refs as $ref) {
                if (!is_array($ref)) {
                    continue;
                }
                if (isset($ref['attributes'])) {
                    $lines[] = $ref;
                    continue;
                }
                foreach ($included as $candidate) {
                    if (is_array($candidate)
                        && (string) ($candidate['id'] ?? '') === (string) ($ref['id'] ?? '')
                        && (string) ($candidate['type'] ?? '') === (string) ($ref['type'] ?? '')) {
                        $lines[] = $candidate;
                        break;
                    }
                }
            }
            if ($lines !== []) {
                return self::normalizeLineList($lines, $included);
            }
        }
        return [];
    }

    /** @param array<int, array<string, mixed>> $items @param array<int, array<string, mixed>> $corrections */
    public static function buildReplacementPayload(string $orderId, array $order, array $items, array $corrections): array
    {
        $resource = self::firstResource($order);
        $orderAttributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : $resource;
        $byIndex = [];
        foreach ($corrections as $line) {
            $byIndex[(int) $line['line_index']] = $line;
        }
        foreach ($byIndex as $index => $line) {
            if (!isset($items[$index])) {
                throw new RuntimeException('La linea original ya no existe en Packiyo.');
            }
            $currentSku = self::itemSku($items[$index]);
            if (!self::isPlaceholderSku($currentSku) || strcasecmp($currentSku, (string) $line['original_sku']) !== 0) {
                throw new RuntimeException('La linea provisional cambio desde el analisis.');
            }
            $items[$index]['sku'] = (string) $line['proposed_sku'];
            $items[$index]['name'] = (string) $line['proposed_name'];
            $items[$index]['product_id'] = (string) $line['proposed_product_id'];
            $items[$index]['packiyo_product_id'] = (string) $line['proposed_product_id'];
        }
        $attributes = ['order_item_data' => $items];
        $shippingMethodId = self::lineString($orderAttributes, ['shipping_method_id', 'shippingMethodId']);
        if ($shippingMethodId === '') {
            $shippingMethodName = self::lineString($orderAttributes, ['shipping_method_name', 'shippingMethodName']);
            $attributes['shipping_method_name'] = $shippingMethodName !== '' ? $shippingMethodName : 'Generic';
        }
        return [
            'data' => [
                'type' => (string) ($resource['type'] ?? 'orders'),
                'id' => $orderId,
                'attributes' => $attributes,
            ],
        ];
    }

    public static function isPlaceholderSku(string $sku): bool
    {
        return preg_match('/^JTL-LINE(?:-|$)/i', trim($sku)) === 1;
    }

    public static function isEditableStatus(string $status): bool
    {
        $status = strtolower(trim($status));
        if ($status === '') {
            return false;
        }
        if (in_array($status, ['new', 'pending', 'open', 'draft', 'created', 'unfulfilled', 'on_hold', 'hold'], true)) {
            return true;
        }
        foreach (['picking', 'packing', 'shipped', 'fulfilled', 'cancel', 'closed', 'processed'] as $blocked) {
            if (str_contains($status, $blocked)) {
                return false;
            }
        }
        return false;
    }

    /** @param array<int, array<string, mixed>> $items */
    public static function snapshotHash(array $items): string
    {
        return hash('sha256', json_encode(array_values($items), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $order */
    public static function orderStatus(array $order): string
    {
        $resource = self::firstResource($order);
        $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : $resource;
        return self::lineString($attributes, ['status_text', 'statusText', 'status', 'state', 'order_status', 'orderStatus']);
    }

    /** @param array<string, mixed> $order */
    public static function isOrderWithinWindow(array $order, string $windowStart, string $windowEnd): bool
    {
        $attributes = is_array($order['attributes'] ?? null) ? $order['attributes'] : $order;
        $orderedAt = self::lineString($attributes, ['ordered_at', 'orderedAt', 'created_at', 'createdAt', 'created']);

        if ($orderedAt === '') {
            // Do not discard an order only because the list response omitted its timestamp.
            return true;
        }

        $createdTimestamp = strtotime($orderedAt);
        $startTimestamp = strtotime($windowStart);
        $endTimestamp = strtotime($windowEnd);

        if ($createdTimestamp === false || $startTimestamp === false || $endTimestamp === false) {
            return true;
        }

        return $createdTimestamp >= $startTimestamp && $createdTimestamp <= $endTimestamp;
    }

    /** @param array<string, mixed> $order @param array<int, string> $customerIds */
    public static function isOrderForSelectedCustomers(array $order, array $customerIds): bool
    {
        if ($customerIds === []) {
            return true;
        }
        $attributes = is_array($order['attributes'] ?? null) ? $order['attributes'] : $order;
        $customerId = self::customerId($order, $attributes);
        return $customerId !== '' && in_array($customerId, $customerIds, true);
    }

    /** @param array<string, mixed> $job @return array<int, string> */
    private static function jobCustomerIds(array $job): array
    {
        $decoded = json_decode((string) ($job['customer_ids_json'] ?? ''), true);
        return is_array($decoded) ? self::normalizeCustomerIds($decoded) : [];
    }

    /** @param array<int, mixed> $customerIds @return array<int, string> */
    private static function normalizeCustomerIds(array $customerIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $customerId): string => is_scalar($customerId) ? trim((string) $customerId) : '',
            $customerIds
        ))));
    }

    /** @param array<string, mixed> $resource @param array<string, mixed> $parent */
    private function analyzeOrder(string $jobId, array $resource, array $parent): int
    {
        $orderId = (string) ($resource['id'] ?? self::lineString($resource, ['id', 'Id']));
        if ($orderId === '') {
            return 0;
        }
        $response = ['data' => $resource, 'included' => $parent['included'] ?? []];
        $items = self::extractPackiyoLineItems($response);
        if ($items === []) {
            $response = $this->packiyoClient()->getOrder($orderId);
            $items = self::extractPackiyoLineItems($response);
        }
        $orderResource = self::firstResource($response);
        $attributes = is_array($orderResource['attributes'] ?? null) ? $orderResource['attributes'] : $orderResource;
        $number = self::lineString($attributes, ['number', 'order_number', 'orderNumber']);
        $customerId = self::customerId($orderResource, $attributes);
        $status = self::orderStatus($response);
        $jtl = $this->jtlContext($orderId, $number, $customerId);
        $detected = 0;
        foreach ($items as $index => $item) {
            $sku = self::itemSku($item);
            if (!self::isPlaceholderSku($sku)) {
                continue;
            }
            $jtlItem = self::matchJtlItem($item, $index, $jtl['items']);
            $jtlName = self::lineString($jtlItem, ['name', 'Name', 'title', 'description']);
            $sourceName = $jtlName ?: self::lineString($item, ['name', 'Name', 'title']);
            $suggestions = [];
            $proposed = null;
            if ($customerId !== '' && $sourceName !== '') {
                $saved = $this->store()->groupAssignment($customerId, $sourceName)
                    ?? (new ProductNameMapping())->find($customerId, OrderPreparationService::normalizeName($sourceName));
                if ($saved !== null) {
                    $proposed = [
                        'id' => (string) ($saved['packiyo_product_id'] ?? ''),
                        'sku' => (string) ($saved['packiyo_sku'] ?? ''),
                        'name' => (string) ($saved['packiyo_product_name'] ?? ''),
                    ];
                }
                $catalog = $this->preparationService()->catalog($customerId, false);
                $suggestions = $this->preparationService()->matchCandidates($sourceName, $catalog);
                $jtlSku = self::lineString($jtlItem, ['sku', 'SKU', 'articleNumber', 'itemNumber']);
                $resolvedSku = $jtlSku !== '' ? ((new ProductSkuAlias())->resolve($customerId, $jtlSku) ?? $jtlSku) : '';
                if ($resolvedSku !== '') {
                    foreach ($catalog as $product) {
                        if (strcasecmp((string) $product['sku'], $resolvedSku) === 0) {
                            array_unshift($suggestions, $product + ['score' => 1.0, 'source' => $resolvedSku === $jtlSku ? 'exact_sku' : 'sku_alias']);
                            break;
                        }
                    }
                }
                $unique = [];
                foreach ($suggestions as $suggestion) {
                    $unique[(string) ($suggestion['id'] ?? $suggestion['sku'] ?? count($unique))] = $suggestion;
                }
                $suggestions = array_slice(array_values($unique), 0, 5);
            }
            $this->store()->upsertLine([
                'job_id' => $jobId,
                'packiyo_order_id' => $orderId,
                'packiyo_order_number' => $number,
                'packiyo_customer_id' => $customerId,
                'packiyo_status' => $status,
                'jtl_order_id' => $jtl['id'],
                'jtl_order_number' => $jtl['number'],
                'jtl_source' => $jtl['source'],
                'line_index' => $index,
                'original_external_id' => self::lineString($item, ['external_id', 'externalId', 'id']),
                'original_sku' => $sku,
                'original_name' => self::lineString($item, ['name', 'Name', 'title']),
                'jtl_name' => $jtlName,
                'jtl_sku' => self::lineString($jtlItem, ['sku', 'SKU', 'articleNumber', 'itemNumber']),
                'quantity' => self::lineNumber($item, ['quantity', 'qty', 'amount'], 1),
                'price' => self::lineNumber($item, ['price', 'unit_price', 'unitPrice'], 0),
                'current_snapshot' => $items,
                'jtl_snapshot' => $jtlItem,
                'suggestions' => $suggestions,
                'proposed_product_id' => $proposed['id'] ?? '',
                'proposed_sku' => $proposed['sku'] ?? '',
                'proposed_name' => $proposed['name'] ?? '',
                'result' => $proposed !== null ? 'suggested' : 'pending',
            ]);
            $detected++;
        }
        return $detected;
    }

    /** @return array{id:string,number:string,source:string,items:array<int,array<string,mixed>>} */
    private function jtlContext(string $orderId, string $number, string $customerId): array
    {
        $mapping = $this->mappingModel()->findByPackiyoOrder($orderId, $number, $customerId);
        $id = trim((string) ($mapping['jtl_order_id'] ?? ''));
        $jtlNumber = trim((string) ($mapping['jtl_order_number'] ?? ''));
        if ($id !== '') {
            try {
                $items = $this->jtlClient()->getOrderItems($id);
                if ($items === []) {
                    $items = self::nestedItems($this->jtlClient()->getOrder($id));
                }
                return ['id' => $id, 'number' => $jtlNumber, 'source' => 'live', 'items' => $items];
            } catch (Throwable) {
                $draft = $this->draftModel()->find($id);
                $source = is_array($draft['source'] ?? null) ? $draft['source'] : [];
                $items = is_array($source['items'] ?? null) ? array_values(array_filter($source['items'], 'is_array')) : [];
                return ['id' => $id, 'number' => $jtlNumber, 'source' => $items !== [] ? 'local_copy' : 'unavailable', 'items' => $items];
            }
        }
        return ['id' => '', 'number' => '', 'source' => 'unavailable', 'items' => []];
    }

    /** @param array<int, array<string, mixed>> $items @param array<int, array<string, mixed>> $after @param array<int, array<string, mixed>> $corrections */
    private static function verifyReplacement(array $items, array $after, array $corrections): void
    {
        if (count($items) !== count($after)) {
            throw new RuntimeException('La verificacion detecto un cambio en el numero de lineas.');
        }
        $indexes = array_fill_keys(array_map(static fn (array $line): int => (int) $line['line_index'], $corrections), true);
        foreach ($items as $index => $before) {
            if (!isset($after[$index])) {
                throw new RuntimeException('Falta una linea despues de la correccion.');
            }
            if (isset($indexes[$index])) {
                $line = null;
                foreach ($corrections as $candidate) {
                    if ((int) $candidate['line_index'] === $index) {
                        $line = $candidate;
                        break;
                    }
                }
                $actualSku = self::itemSku($after[$index]);
                $actualProduct = self::lineString($after[$index], ['product_id', 'packiyo_product_id', 'productId']);
                if ($line === null
                    || strcasecmp($actualSku, (string) $line['proposed_sku']) !== 0
                    || $actualProduct !== (string) $line['proposed_product_id']) {
                    throw new RuntimeException('Packiyo no aplico el producto destino solicitado.');
                }
                if (abs(self::lineNumber($after[$index], ['quantity', 'qty', 'amount'], -1) - self::lineNumber($before, ['quantity', 'qty', 'amount'], -2)) > 0.0001
                    || abs(self::lineNumber($after[$index], ['price', 'unit_price', 'unitPrice'], -1) - self::lineNumber($before, ['price', 'unit_price', 'unitPrice'], -2)) > 0.0001) {
                    throw new RuntimeException('Packiyo cambio la cantidad o el precio de una linea corregida.');
                }
                continue;
            }
            if (self::snapshotHash([$before]) !== self::snapshotHash([$after[$index]])) {
                throw new RuntimeException('Una linea no seleccionada cambio durante la correccion.');
            }
        }
    }

    /** @param array<int, array<string, mixed>> $items @param array<int, array<string, mixed>> $corrections */
    private static function alreadyCorrected(array $items, array $corrections): bool
    {
        foreach ($corrections as $line) {
            $index = (int) $line['line_index'];
            if (!isset($items[$index])
                || strcasecmp(self::itemSku($items[$index]), (string) $line['proposed_sku']) !== 0
                || self::lineString($items[$index], ['product_id', 'packiyo_product_id', 'productId']) !== (string) $line['proposed_product_id']
                || abs(self::lineNumber($items[$index], ['quantity', 'qty', 'amount'], 0) - (float) $line['quantity']) > 0.0001
                || abs(self::lineNumber($items[$index], ['price', 'unit_price', 'unitPrice'], -1) - (float) $line['price']) > 0.0001) {
                return false;
            }
        }
        return $corrections !== [];
    }

    /** @param array<int, array<string, mixed>> $lines */
    private static function assertCustomerUnchanged(array $order, array $lines): void
    {
        $resource = self::firstResource($order);
        $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : $resource;
        $current = self::customerId($resource, $attributes);
        if ($current === '') {
            throw new RuntimeException('No se pudo confirmar el cliente actual de la orden Packiyo.');
        }
        foreach ($lines as $line) {
            $expected = trim((string) ($line['packiyo_customer_id'] ?? ''));
            if ($expected === '' || !hash_equals($expected, $current)) {
                throw new RuntimeException('El cliente Packiyo cambio desde el analisis; la orden queda intacta.');
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function atomicReplaceWithRetry(string $orderId, array $payload, bool $singleOrderTest = false): void
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $this->packiyoClient()->atomicReplaceOrderLines($orderId, $payload, $singleOrderTest);
                return;
            } catch (HttpException $exception) {
                if ($attempt >= 2 || !in_array($exception->statusCode(), [408, 429, 500, 502, 503, 504], true)) {
                    throw $exception;
                }
                usleep(250000);
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function assignedLines(string $jobId, array $ids): array
    {
        $lines = $this->store()->selectedLines($jobId, $ids);
        $lines = array_values(array_filter($lines, static fn (array $line): bool =>
            trim((string) ($line['proposed_product_id'] ?? '')) !== ''
            && trim((string) ($line['proposed_sku'] ?? '')) !== ''
        ));
        if ($lines === []) {
            throw new RuntimeException('Selecciona lineas con una asignacion confirmada.');
        }
        return $lines;
    }

    /** @param array<int, string> $orderIds @return array<int, int> */
    private function lineIdsForOrders(string $jobId, array $orderIds): array
    {
        $orderIds = self::normalizeOrderIds($orderIds);
        if ($orderIds === []) {
            throw new RuntimeException('Selecciona al menos una orden.');
        }

        $wanted = array_fill_keys($orderIds, true);
        $lines = array_values(array_filter(
            $this->store()->lines($jobId, [], 5000),
            static fn (array $line): bool => isset($wanted[trim((string) ($line['packiyo_order_id'] ?? ''))])
        ));
        if ($lines === []) {
            throw new RuntimeException('Las ordenes seleccionadas no pertenecen a este analisis.');
        }

        foreach ($lines as $line) {
            if (trim((string) ($line['proposed_product_id'] ?? '')) === ''
                || trim((string) ($line['proposed_sku'] ?? '')) === '') {
                throw new RuntimeException('Todas las lineas JTL-LINE de cada orden seleccionada deben tener un producto Packiyo asignado.');
            }
        }

        return array_values(array_map(static fn (array $line): int => (int) $line['id'], $lines));
    }

    /** @param array<int, string> $orderIds @return array<int, string> */
    private static function normalizeOrderIds(array $orderIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $orderId): string => is_scalar($orderId) ? trim((string) $orderId) : '',
            $orderIds
        ))));
    }

    /** @param array<int, array<string, mixed>> $lines @return array<string, array<int, array<string, mixed>>> */
    private function groupByOrder(array $lines): array
    {
        $grouped = [];
        foreach ($lines as $line) {
            $grouped[(string) $line['packiyo_order_id']][] = $line;
        }
        return $grouped;
    }

    /** @return array{id:string,sku:string,name:string}|null */
    private function catalogProduct(string $customerId, string $productId): ?array
    {
        $catalog = $this->preparationService()->catalog($customerId, true);
        foreach ($catalog as $product) {
            if ((string) $product['id'] === $productId) {
                return ['id' => (string) $product['id'], 'sku' => (string) $product['sku'], 'name' => (string) $product['name']];
            }
        }
        return null;
    }

    private function writeEnabled(): bool
    {
        return (bool) Config::get('packiyo.order_correction_write_enabled', false)
            && (bool) Config::get('packiyo.order_correction_atomic_confirmed', false)
            && trim((string) Config::get('packiyo.order_correction_atomic_endpoint', '')) !== '';
    }

    private function testWriteEnabled(): bool
    {
        $endpoint = trim((string) Config::get('packiyo.order_correction_atomic_endpoint', ''));
        if ($endpoint === '') {
            $endpoint = trim((string) Config::get('packiyo.order_endpoint', '/orders/{id}'));
        }
        $method = strtoupper(trim((string) Config::get('packiyo.order_correction_atomic_method', 'PATCH')));
        return $endpoint !== '' && in_array($method, ['POST', 'PUT', 'PATCH'], true);
    }

    private function confirmAtomicWriteSettings(): void
    {
        $endpoint = trim((string) Config::get('packiyo.order_correction_atomic_endpoint', ''));
        if ($endpoint === '') {
            $endpoint = trim((string) Config::get('packiyo.order_endpoint', '/orders/{id}'));
        }
        Setting::putMany([
            'PACKIYO_ORDER_CORRECTION_WRITE_ENABLED' => 'true',
            'PACKIYO_ORDER_CORRECTION_ATOMIC_CONFIRMED' => 'true',
            'PACKIYO_ORDER_CORRECTION_ATOMIC_METHOD' => strtoupper(trim((string) Config::get('packiyo.order_correction_atomic_method', 'PATCH'))),
            'PACKIYO_ORDER_CORRECTION_ATOMIC_ENDPOINT' => $endpoint,
        ]);
    }

    /** @return array<string, mixed> */
    private function requireJob(string $id): array
    {
        $job = $this->store()->job($id);
        if ($job === null) {
            throw new RuntimeException('Trabajo de correccion no encontrado.');
        }
        return $job;
    }

    /** @return array<int, array<string, mixed>> */
    private static function resources(array $response): array
    {
        $data = $response['data'] ?? $response['Data'] ?? [];
        if (!is_array($data)) {
            return [];
        }
        return array_values(array_filter(array_is_list($data) ? $data : [$data], 'is_array'));
    }

    private static function hasNextPage(array $response, int $current, int $pageSize): bool
    {
        $next = $response['links']['next'] ?? $response['Links']['Next'] ?? null;
        if (is_string($next) && trim($next) !== '') {
            return true;
        }
        $page = $response['meta']['page'] ?? $response['Meta']['Page'] ?? [];
        if (is_array($page)) {
            return (int) ($page['currentPage'] ?? $page['current_page'] ?? $current)
                < (int) ($page['lastPage'] ?? $page['last_page'] ?? $current);
        }
        return count(self::resources($response)) >= $pageSize;
    }

    /** @return array<string, mixed> */
    private static function firstResource(array $response): array
    {
        $data = $response['data'] ?? $response['Data'] ?? $response;
        if (is_array($data) && array_is_list($data)) {
            return is_array($data[0] ?? null) ? $data[0] : [];
        }
        return is_array($data) ? $data : [];
    }

    /** @param array<int, mixed> $items @param array<int, mixed> $included @return array<int, array<string, mixed>> */
    private static function normalizeLineList(array $items, array $included = []): array
    {
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $attributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : $item;
            if (isset($item['id']) && !isset($attributes['id'])) {
                $attributes['id'] = (string) $item['id'];
            }
            $relatedProduct = $item['relationships']['product']['data'] ?? null;
            if (is_array($relatedProduct)) {
                $attributes = self::mergeProductSnapshot($attributes, $relatedProduct);
            }
            $productId = $relatedProduct['id']
                ?? $attributes['product_id']
                ?? $attributes['productId']
                ?? $attributes['packiyo_product_id']
                ?? null;
            if (is_scalar($productId) && !isset($attributes['product_id'])) {
                $attributes['product_id'] = (string) $productId;
            }
            foreach (['product', 'product_data', 'productData', 'inventory_item', 'inventoryItem'] as $productKey) {
                if (is_array($attributes[$productKey] ?? null)) {
                    $attributes = self::mergeProductSnapshot($attributes, $attributes[$productKey]);
                }
            }
            if (is_scalar($productId)) {
                foreach ($included as $candidate) {
                    if (!is_array($candidate) || (string) ($candidate['id'] ?? '') !== (string) $productId) {
                        continue;
                    }
                    $candidateType = strtolower((string) ($candidate['type'] ?? ''));
                    if ($candidateType !== '' && !str_contains($candidateType, 'product')) {
                        continue;
                    }
                    $candidateAttributes = is_array($candidate['attributes'] ?? null) ? $candidate['attributes'] : $candidate;
                    $attributes = self::mergeProductSnapshot($attributes, $candidateAttributes);
                    break;
                }
            }
            $result[] = $attributes;
        }
        return $result;
    }

    /** @param array<string, mixed> $line @param array<string, mixed> $product @return array<string, mixed> */
    private static function mergeProductSnapshot(array $line, array $product): array
    {
        if (self::itemSku($line) === '') {
            $sku = self::itemSku($product);
            if ($sku !== '') {
                $line['sku'] = $sku;
            }
        }
        if (self::lineString($line, ['name', 'Name', 'title', 'product_name', 'productName']) === '') {
            $name = self::lineString($product, ['name', 'Name', 'title', 'product_name', 'productName']);
            if ($name !== '') {
                $line['name'] = $name;
            }
        }
        return $line;
    }

    /** @param array<string, mixed> $item */
    private static function itemSku(array $item): string
    {
        return self::lineString($item, ['sku', 'SKU', 'product_sku', 'productSku', 'item_sku', 'itemSku']);
    }

    /** @param array<string, mixed> $resource @param array<string, mixed> $attributes */
    private static function customerId(array $resource, array $attributes): string
    {
        $value = $resource['relationships']['customer']['data']['id']
            ?? $attributes['customer_id'] ?? $attributes['customerId'] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<int, array<string, mixed>> $jtlItems @return array<string, mixed> */
    private static function matchJtlItem(array $packiyo, int $position, array $jtlItems): array
    {
        $externalId = self::lineString($packiyo, ['external_id', 'externalId', 'id']);
        if ($externalId !== '') {
            foreach ($jtlItems as $item) {
                if ($externalId === self::lineString($item, ['external_id', 'externalId', 'id', 'Id', 'positionId'])) {
                    return $item;
                }
            }
        }
        if (isset($jtlItems[$position])) {
            return $jtlItems[$position];
        }
        $name = OrderPreparationService::normalizeName(self::lineString($packiyo, ['name', 'Name']));
        $quantity = self::lineNumber($packiyo, ['quantity', 'qty', 'amount'], 0);
        $price = self::lineNumber($packiyo, ['price', 'unit_price', 'unitPrice'], 0);
        $matches = array_values(array_filter($jtlItems, static function (array $item) use ($name, $quantity, $price): bool {
            return $name !== ''
                && OrderPreparationService::normalizeName(self::lineString($item, ['name', 'Name', 'description'])) === $name
                && abs(self::lineNumber($item, ['quantity', 'Quantity', 'amount'], 0) - $quantity) < 0.0001
                && abs(self::lineNumber($item, ['price', 'Price', 'unitPrice'], 0) - $price) < 0.01;
        }));
        return count($matches) === 1 ? $matches[0] : [];
    }

    /** @return array<int, array<string, mixed>> */
    private static function nestedItems(array $order): array
    {
        foreach (['items', 'Items', 'lineItems', 'LineItems', 'positions', 'Positions'] as $key) {
            if (isset($order[$key]) && is_array($order[$key])) {
                return array_values(array_filter($order[$key], 'is_array'));
            }
        }
        foreach (['data', 'Data'] as $key) {
            if (isset($order[$key]) && is_array($order[$key])) {
                return self::nestedItems($order[$key]);
            }
        }
        return [];
    }

    /** @param array<string, mixed> $data @param array<int, string> $keys */
    private static function lineString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }
        return '';
    }

    /** @param array<string, mixed> $data @param array<int, string> $keys */
    private static function lineNumber(array $data, array $keys, float $default): float
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (float) $data[$key];
            }
        }
        return $default;
    }

    private function packiyoClient(): PackiyoClient { return $this->packiyo ?? new PackiyoClient(); }
    private function jtlClient(): JtlClient { return $this->jtl ?? new JtlClient(timeout: 10); }
    private function store(): OrderCorrection { return $this->corrections ?? new OrderCorrection(); }
    private function mappingModel(): OrderMapping { return $this->mappings ?? new OrderMapping(); }
    private function draftModel(): OrderDraft { return $this->drafts ?? new OrderDraft(); }
    private function preparationService(): OrderPreparationService { return $this->preparation ?? new OrderPreparationService(); }
    private function customerModel(): PackiyoCustomer { return $this->customers ?? new PackiyoCustomer(); }
}
