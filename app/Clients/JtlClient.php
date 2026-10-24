<?php

declare(strict_types=1);

namespace App\Clients;

use App\Models\JtlApiCredential;
use App\Support\Config;
use App\Support\HttpClient;
use App\Support\HttpException;
use App\Support\Logger;
use RuntimeException;
use Throwable;

final class JtlClient
{
    /** JTL's default page size; set explicitly so paging is predictable. */
    private const ORDERS_PAGE_SIZE = 100;

    /**
     * JTL's fixed DeliveryNoteWorkflowEvent enum (REST Contracts V1): 1 = Created, 2 = Deleted, 3 = Shipped.
     * Triggering "Shipped" is what flips the delivery note's/order's shipped status inside JTL-Wawi so that
     * JTL-Worker's marketplace sync (BOL, etc.) reports the order as fulfilled. JTL does not expose a writable
     * shipping-method/carrier field on delivery note packages: it is a read-only value JTL derives itself.
     */
    private const DELIVERY_NOTE_WORKFLOW_EVENT_SHIPPED = 3;

    private HttpClient $http;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?HttpClient $http = null, ?int $timeout = null)
    {
        $this->config = Config::load('jtl');
        $this->http = $http ?? new HttpClient(
            (string) ($this->config['base_url'] ?? ''),
            $this->headers(),
            max(1, $timeout ?? (int) ($this->config['timeout'] ?? 30)),
            (bool) ($this->config['ssl_verify'] ?? true)
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function getOrders(): array
    {
        $query = is_array($this->config['new_orders_query'] ?? null) ? $this->config['new_orders_query'] : [];
        $query['pageSize'] ??= self::ORDERS_PAGE_SIZE;
        $page = max(1, (int) ($query['pageNumber'] ?? 1));
        $maxPages = max(1, (int) ($this->config['orders_max_pages'] ?? 20));
        $orders = [];

        for ($fetched = 0; $fetched < $maxPages; $fetched++) {
            $query['pageNumber'] = $page;
            $response = $this->http->get((string) $this->config['orders_endpoint'], ['query' => $query]);
            array_push($orders, ...$this->collection($response));
            $nextPage = $this->nextPageNumber($response, $page);

            if ($nextPage === null) {
                return $orders;
            }

            $page = $nextPage;
        }

        (new Logger())->warning(
            'jtl',
            'JTL orders: stopped after ' . $maxPages . ' pages (' . count($orders) . ' orders) although more pages exist. '
            . 'Narrow JTL_NEW_ORDERS_QUERY or raise JTL_ORDERS_MAX_PAGES.'
        );

        return $orders;
    }

    /**
     * Next page of a JTL paged result (camelCase or PascalCase fields). A response without
     * paging fields counts as the only page.
     *
     * @param array<string, mixed> $response
     */
    private function nextPageNumber(array $response, int $currentPage): ?int
    {
        $hasNext = $response['hasNextPage'] ?? $response['HasNextPage'] ?? null;

        if ($hasNext !== null) {
            if ($hasNext !== true) {
                return null;
            }

            $next = (int) ($response['nextPageNumber'] ?? $response['NextPageNumber'] ?? 0);

            return $next > $currentPage ? $next : $currentPage + 1;
        }

        $totalPages = (int) ($response['totalPages'] ?? $response['TotalPages'] ?? 0);

        return $totalPages > $currentPage ? $currentPage + 1 : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function getSalesChannels(): array
    {
        $endpoint = (string) ($this->config['sales_channels_endpoint'] ?? '');

        if ($endpoint === '') {
            return [];
        }

        return $this->collection($this->http->get($endpoint));
    }

    /** @return array<string, mixed> */
    public function getWorkerStatus(): array
    {
        $endpoint = (string) ($this->config['worker_status_endpoint'] ?? '');

        if ($endpoint === '') {
            return [];
        }

        $syncId = trim((string) ($this->config['worker_sync_id'] ?? ''));
        $options = $syncId !== '' ? ['query' => ['syncId' => $syncId]] : [];

        return $this->workerGet($endpoint, $options, 'Unable to read JTL worker status.');
    }

    /** @return array<int, array<string, mixed>> */
    public function getWorkerSyncs(): array
    {
        $endpoint = (string) ($this->config['workers_endpoint'] ?? '');

        if ($endpoint === '') {
            return [];
        }

        $response = $this->workerGet($endpoint, [], 'Unable to read JTL worker syncs.');

        if (array_is_list($response)) {
            return array_values(array_map(
                static fn (mixed $item): array => is_array($item)
                    ? $item
                    : ['id' => (string) $item, 'name' => (string) $item],
                $response
            ));
        }

        return $this->collection($response);
    }

    /** @return array<string, mixed> */
    public function getOrder(string $id): array
    {
        return $this->http->get($this->endpoint('order_endpoint', $id));
    }

    /** @return array<int, array<string, mixed>> */
    public function getOrderItems(string $id): array
    {
        if (($this->config['order_items_endpoint'] ?? '') === '') {
            return [];
        }

        $response = $this->http->get($this->endpoint('order_items_endpoint', $id));

        return $this->collection($response);
    }

    /** @return array<int, array<string, mixed>> */
    public function queryItems(?string $searchKeyword = null): array
    {
        $query = ['pageSize' => 50];

        if ($searchKeyword !== null && $searchKeyword !== '') {
            $query['searchKeyWord'] = $searchKeyword;
        }

        $response = $this->http->get((string) $this->config['items_endpoint'], ['query' => $query]);

        return $this->collection($response);
    }

    /** @param array<string, mixed> $payload */
    public function createItem(array $payload): array
    {
        return $this->http->post((string) $this->config['items_endpoint'], [
            'json' => $payload,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getStocks(string $itemId, string $warehouseId): array
    {
        try {
            $response = $this->http->get((string) $this->config['stocks_endpoint'], [
                'query' => [
                    'itemId' => $itemId,
                    'warehouseId' => $warehouseId,
                    'pageSize' => 100,
                ],
            ]);
        } catch (HttpException $exception) {
            if ($exception->statusCode() === 404) {
                return [];
            }

            throw $exception;
        }

        return $this->collection($response);
    }

    /** @param array<string, mixed> $payload */
    public function createStockAdjustment(array $payload): array
    {
        return $this->http->post((string) $this->config['stocks_endpoint'], [
            'query' => ['disableAutomaticWorkflows' => 'false'],
            'json' => $payload,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getDeliveryNotes(string $salesOrderId, ?string $salesOrderNumber = null): array
    {
        $query = ['salesOrderNumberId' => $salesOrderId];
        $response = $this->http->get((string) $this->config['delivery_notes_endpoint'], ['query' => $query]);
        $notes = $this->collection($response);

        if ($notes !== [] || $salesOrderNumber === null || $salesOrderNumber === '') {
            return $notes;
        }

        $response = $this->http->get((string) $this->config['delivery_notes_endpoint'], [
            'query' => ['salesOrderNumber' => $salesOrderNumber],
        ]);

        return $this->collection($response);
    }

    /** @return array<int, array<string, mixed>> */
    public function getDeliveryNotePackages(string $deliveryNoteId): array
    {
        $response = $this->http->get($this->deliveryNotePackagesEndpoint($deliveryNoteId));

        return $this->collection($response);
    }

    /**
     * @param array<int, array<string, mixed>> $packages
     * @return array<string, mixed>
     */
    public function createDeliveryNotePackages(string $deliveryNoteId, array $packages): array
    {
        return $this->http->post($this->deliveryNotePackagesEndpoint($deliveryNoteId), [
            'json' => $packages,
        ]);
    }

    public function markDeliveryNoteShippedEnabled(): bool
    {
        return (bool) ($this->config['mark_delivery_note_shipped'] ?? true);
    }

    /**
     * Triggers JTL's built-in "Shipped" workflow event for a delivery note. This is the field/call BOL and other
     * marketplaces rely on (via JTL-Worker) to consider an order fulfilled; it is separate from, and required in
     * addition to, adding the tracking package via createDeliveryNotePackages().
     *
     * @return array<string, mixed>
     */
    public function triggerDeliveryNoteShippedWorkflowEvent(string $deliveryNoteId): array
    {
        $endpoint = $this->endpointWithReplacements(
            (string) ($this->config['delivery_note_workflow_trigger_endpoint'] ?? ''),
            [
                'id' => $deliveryNoteId,
                'deliveryNoteId' => $deliveryNoteId,
                'workflowEventId' => (string) self::DELIVERY_NOTE_WORKFLOW_EVENT_SHIPPED,
            ]
        );

        if ($endpoint === '') {
            throw new RuntimeException('JTL delivery note workflow trigger endpoint is not configured.');
        }

        return $this->http->post($endpoint);
    }

    /** @return array<int, array<string, mixed>> */
    public function getSalesOrderWorkflowEvents(): array
    {
        $endpoint = (string) ($this->config['sales_order_workflow_events_endpoint'] ?? '');

        if ($endpoint === '') {
            return [];
        }

        return $this->collection($this->http->get($endpoint));
    }

    /**
     * Triggers a JTL-Wawi sales order workflow event via `POST .../salesOrders/{id}/workflowEvents` with
     * `{"Id": workflowEventId}` in the body. This is the only endpoint shape JTL's REST API (eazyBusiness,
     * on-premise Wawi) documents and supports for sales orders (confirmed against JTL's own API reference and
     * community reports); unlike delivery notes, there is no `salesOrders/{id}/workflow/{workflowEventId}`
     * route, so a prior fallback attempt to that shape always 404'd and masked whatever the real error was.
     * If this call fails, the exception (including JTL's response body, e.g. its `ErrorMessage`) is left to
     * propagate so callers see the actual reason instead of a generic non-existent-route 404.
     *
     * @return array<string, mixed>
     */
    public function triggerSalesOrderWorkflowEvent(string $salesOrderId, int $workflowEventId): array
    {
        if ($workflowEventId <= 0) {
            throw new RuntimeException('JTL sales order workflow event ID is not configured.');
        }

        $endpoint = $this->endpointWithReplacements(
            (string) ($this->config['sales_order_workflow_trigger_endpoint'] ?? ''),
            ['id' => $salesOrderId, 'salesOrderId' => $salesOrderId]
        );

        if ($endpoint === '') {
            throw new RuntimeException('JTL sales order workflow trigger endpoint is not configured.');
        }

        return $this->http->post($endpoint, [
            'json' => ['Id' => $workflowEventId],
        ]);
    }

    public function autoCreateDeliveryNoteEnabled(): bool
    {
        return (bool) ($this->config['auto_create_delivery_note'] ?? false);
    }

    public function deliveryNoteWorkflowEventId(): ?int
    {
        $value = trim((string) ($this->config['delivery_note_workflow_event_id'] ?? ''));

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function deliveryNoteCreateRetries(): int
    {
        return max(1, min(20, (int) ($this->config['delivery_note_create_retries'] ?? 5)));
    }

    public function deliveryNoteCreateRetryDelaySeconds(): int
    {
        return max(0, min(30, (int) ($this->config['delivery_note_create_retry_delay_seconds'] ?? 2)));
    }

    public function status(): string
    {
        if ($this->isConfigured()) {
            return 'configured';
        }

        try {
            return (new JtlApiCredential())->status();
        } catch (Throwable) {
            return 'missing_config';
        }
    }

    public function isReachabilityException(Throwable $exception): bool
    {
        if ($exception instanceof HttpException) {
            if (in_array($exception->statusCode(), [408, 502, 503, 504, 520, 521, 522, 523, 524, 525, 526], true)) {
                return true;
            }

            return $exception->statusCode() === 530
                && preg_match('/(?:error\s*(?:code)?\s*[:#]?\s*1033|cloudflare\s+tunnel|argo\s+tunnel)/i', $exception->body()) === 1;
        }

        return preg_match(
            '/timed out|failed to connect|could not connect|could not resolve host|connection refused|no route to host|network is unreachable|connection reset|http request failed|ssl.*(connect|timeout)/i',
            $exception->getMessage()
        ) === 1;
    }

    public function friendlyReachabilityMessage(Throwable $exception): string
    {
        $baseUrl = trim((string) ($this->config['base_url'] ?? ''));
        $target = $baseUrl !== '' ? ' en ' . $baseUrl : '';

        if (
            $exception instanceof HttpException
            && $exception->statusCode() === 530
            && preg_match('/(?:error\s*(?:code)?\s*[:#]?\s*1033|cloudflare\s+tunnel|argo\s+tunnel)/i', $exception->body()) === 1
        ) {
            return 'JTL API no esta reachable' . $target
                . ' porque Cloudflare Tunnel devolvio el error 1033. Revisa que la PC de JTL este encendida, '
                . 'que el servicio cloudflared este corriendo y que el tunnel aparezca Healthy en Cloudflare.';
        }

        return 'JTL API no esta reachable' . $target
            . '. Revisa que la PC de JTL este encendida, que el servicio/API de JTL y cloudflared esten corriendo, '
            . 'y que el tunnel de Cloudflare aparezca conectado. Detalle: ' . $exception->getMessage();
    }

    public function isConfigured(): bool
    {
        $baseUrl = (string) ($this->config['base_url'] ?? '');
        $authType = (string) ($this->config['auth_type'] ?? 'bearer');

        if ($baseUrl === '') {
            return false;
        }

        if ($authType === 'none') {
            return true;
        }

        if ($authType === 'basic') {
            return ($this->config['username'] ?? '') !== '' && ($this->config['password'] ?? '') !== '';
        }

        return $this->apiKey() !== '';
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $headers = ['Accept' => 'application/json'];
        $authType = (string) ($this->config['auth_type'] ?? 'bearer');
        $apiKey = $this->apiKey();

        if (($this->config['app_id'] ?? '') !== '') {
            $headers['x-appid'] = (string) $this->config['app_id'];
        }

        if (($this->config['app_version'] ?? '') !== '') {
            $headers['x-appversion'] = (string) $this->config['app_version'];
        }

        if (($this->config['api_version'] ?? '') !== '') {
            $headers['api-version'] = (string) $this->config['api_version'];
        }

        if (($this->config['cloudflare_access_client_id'] ?? '') !== '') {
            $headers['CF-Access-Client-Id'] = (string) $this->config['cloudflare_access_client_id'];
        }

        if (($this->config['cloudflare_access_client_secret'] ?? '') !== '') {
            $headers['CF-Access-Client-Secret'] = (string) $this->config['cloudflare_access_client_secret'];
        }

        if ($authType === 'bearer' && $apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        if ($authType === 'wawi' && $apiKey !== '') {
            $headers['Authorization'] = 'Wawi ' . $apiKey;
        }

        if ($authType === 'api_key' && $apiKey !== '') {
            $headerName = (string) ($this->config['api_key_header'] ?? 'Authorization');
            $headers[$headerName] = $apiKey;
        }

        if ($authType === 'basic') {
            $username = (string) ($this->config['username'] ?? '');
            $password = (string) ($this->config['password'] ?? '');
            $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
        }

        return $headers;
    }

    private function apiKey(): string
    {
        $apiKey = (string) ($this->config['api_key'] ?? '');

        if ($apiKey !== '') {
            return $apiKey;
        }

        try {
            return (string) ((new JtlApiCredential())->currentApiKey() ?? '');
        } catch (Throwable) {
            return '';
        }
    }

    private function endpoint(string $name, string $id): string
    {
        return str_replace('{id}', rawurlencode($id), (string) ($this->config[$name] ?? ''));
    }

    /** @param array<string, string> $replacements */
    private function endpointWithReplacements(string $endpoint, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $endpoint = str_replace('{' . $key . '}', rawurlencode($value), $endpoint);
        }

        return $endpoint;
    }

    private function deliveryNotePackagesEndpoint(string $id): string
    {
        return str_replace('{id}', rawurlencode($id), (string) ($this->config['delivery_note_packages_endpoint'] ?? ''));
    }

    /**
     * The Worker endpoints are versioned (/v1/workers) and reject the api-version header that the rest of
     * the API needs, so each read tries at most: the configured path and the canonical /v1 path, each first
     * without and then with the default api-version header. That is four requests in the worst case.
     *
     * @param array<string, mixed> $options
     * @return array<mixed>
     */
    private function workerGet(string $endpoint, array $options, string $failureMessage): array
    {
        $withoutApiVersion = $options;
        $withoutApiVersion['headers'] = array_merge(
            is_array($options['headers'] ?? null) ? $options['headers'] : [],
            ['api-version' => '']
        );
        $errors = [];
        $lastException = null;

        foreach ($this->workerEndpointCandidates($endpoint) as $candidate) {
            foreach (['without api-version header' => $withoutApiVersion, 'default headers' => $options] as $variant => $requestOptions) {
                try {
                    return $this->http->get($candidate, $requestOptions);
                } catch (HttpException $exception) {
                    $lastException = $exception;
                    $errors[] = '[' . $variant . '] ' . $exception->getMessage();

                    if (!$this->shouldTryWorkerEndpointFallback($exception)) {
                        throw $exception;
                    }
                }
            }
        }

        if (count($errors) > 1) {
            throw new RuntimeException($failureMessage . ' Tried: ' . implode(' | ', $errors), 0, $lastException);
        }

        throw $lastException ?? new RuntimeException($failureMessage);
    }

    /** @return array<int, string> */
    private function workerEndpointCandidates(string $endpoint): array
    {
        $canonical = preg_replace(
            '#^(?:/api/eazybusiness)?/(?:v[0-9]+/)?workers#i',
            $this->workerCanonicalPrefix() . '/workers',
            $endpoint,
            1
        );

        return array_values(array_unique(array_filter([$endpoint, is_string($canonical) ? $canonical : ''])));
    }

    private function workerCanonicalPrefix(): string
    {
        $basePath = strtolower(trim((string) (parse_url((string) ($this->config['base_url'] ?? ''), PHP_URL_PATH) ?: ''), '/'));

        return str_ends_with($basePath, 'api/eazybusiness') ? '/v1' : '/api/eazybusiness/v1';
    }

    private function shouldTryWorkerEndpointFallback(HttpException $exception): bool
    {
        if (in_array($exception->statusCode(), [404, 405], true)) {
            return true;
        }

        if ($exception->statusCode() !== 400) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'ambiguous api version')
            || str_contains($message, 'api versions were requested')
            || str_contains($message, 'only a single api version')
            || str_contains($message, 'unsupported api version')
            || str_contains($message, 'does not support the api version');
    }

    /** @return array<int, array<string, mixed>> */
    private function collection(array $response): array
    {
        foreach ([
            'data',
            'Data',
            'items',
            'Items',
            'orders',
            'Orders',
            'salesOrders',
            'SalesOrders',
            'salesChannels',
            'SalesChannels',
            'workers',
            'Workers',
            'results',
            'Results',
            'value',
            'Value',
        ] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return array_values(array_filter($response[$key], 'is_array'));
            }
        }

        if (array_is_list($response)) {
            return array_values(array_filter($response, 'is_array'));
        }

        return $response === [] ? [] : [$response];
    }
}
