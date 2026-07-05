<?php

declare(strict_types=1);

namespace App\Clients;

use App\Models\JtlApiCredential;
use App\Support\Config;
use App\Support\HttpClient;
use App\Support\HttpException;
use RuntimeException;
use Throwable;

final class JtlClient
{
    private HttpClient $http;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?HttpClient $http = null)
    {
        $this->config = Config::load('jtl');
        $this->http = $http ?? new HttpClient(
            (string) ($this->config['base_url'] ?? ''),
            $this->headers(),
            (int) ($this->config['timeout'] ?? 30),
            (bool) ($this->config['ssl_verify'] ?? true)
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function getOrders(): array
    {
        $response = $this->http->get((string) $this->config['orders_endpoint'], [
            'query' => $this->config['new_orders_query'] ?? [],
        ]);

        return $this->collection($response);
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

        $lastException = null;

        foreach ($this->workerEndpointCandidates($endpoint) as $candidate) {
            try {
                return $this->http->get($candidate);
            } catch (HttpException $exception) {
                $lastException = $exception;

                if (!$this->shouldTryWorkerEndpointFallback($exception)) {
                    throw $exception;
                }
            }
        }

        throw $lastException ?? new RuntimeException('Unable to read JTL worker status.');
    }

    /** @return array<int, array<string, mixed>> */
    public function getWorkerSyncs(): array
    {
        $endpoint = (string) ($this->config['workers_endpoint'] ?? '');

        if ($endpoint === '') {
            return [];
        }

        $lastException = null;
        $response = null;

        $errors = [];

        foreach ($this->workerRequestVariants() as $variant => $options) {
            foreach ($this->workerCollectionEndpointCandidates($endpoint) as $candidate) {
                try {
                    $response = $this->http->get($candidate, $options);
                    break 2;
                } catch (HttpException $exception) {
                    $lastException = $exception;
                    $errors[] = '[' . $variant . '] ' . $exception->getMessage();

                    if (!$this->shouldTryWorkerEndpointFallback($exception)) {
                        throw $exception;
                    }
                }
            }
        }

        if ($response === null) {
            if ($errors !== []) {
                throw new RuntimeException('Unable to read JTL worker syncs. Tried: ' . implode(' | ', $errors));
            }

            throw $lastException ?? new RuntimeException('Unable to read JTL worker syncs.');
        }

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
    public function startWorkerSync(string $syncId, string $syncName = ''): array
    {
        $method = strtoupper((string) ($this->config['worker_sync_method'] ?? 'POST'));
        $method = $method === 'GET' ? 'PUT' : $method;
        $endpoint = (string) ($this->config['worker_endpoint'] ?? '');

        if ($endpoint === '') {
            throw new RuntimeException('JTL worker endpoint is not configured.');
        }

        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'GET'], true)) {
            throw new RuntimeException('Unsupported JTL worker sync method: ' . $method);
        }

        $lastException = null;

        foreach ($this->workerControlEndpointCandidates($endpoint, $syncId) as $candidate) {
            foreach ($this->workerSyncRequestPayloads($syncId, $syncName, $candidate) as $options) {
                foreach ($this->workerControlRequestVariants($options, $candidate) as $requestOptions) {
                    try {
                        return $this->http->request($method, $candidate, $requestOptions);
                    } catch (HttpException $exception) {
                        $lastException = $exception;

                        if (!$this->shouldTryWorkerEndpointFallback($exception)) {
                            throw $exception;
                        }
                    }
                }
            }
        }

        throw $lastException ?? new RuntimeException('Unable to start JTL worker sync.');
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

    private function deliveryNotePackagesEndpoint(string $id): string
    {
        return str_replace('{id}', rawurlencode($id), (string) ($this->config['delivery_note_packages_endpoint'] ?? ''));
    }

    private function workerSyncEndpoint(string $syncId): string
    {
        $endpoint = (string) ($this->config['worker_endpoint'] ?? '');

        return str_replace(
            ['{id}', '{syncId}', '{sync_id}'],
            rawurlencode($syncId),
            $endpoint
        );
    }

    /** @return array<int, string> */
    private function workerControlEndpointCandidates(string $endpoint, string $syncId): array
    {
        $encodedSyncId = rawurlencode($syncId);
        $configured = str_replace(['{id}', '{syncId}', '{sync_id}'], $encodedSyncId, $endpoint);
        $candidates = [];

        if ($this->workerActionIsInPath($configured)) {
            $candidates[] = $configured;
        }

        foreach ($this->workerPathControlCandidates($endpoint, $encodedSyncId) as $candidate) {
            $candidates[] = $candidate;
        }

        if (!$this->workerActionIsInPath($configured) && !str_contains(strtolower($configured), '/workers/control')) {
            $candidates[] = $configured;
        }

        return array_values(array_filter(array_unique(array_map(
            fn (string $candidate): string => rtrim($this->workerVersionedControlEndpoint($candidate), '/'),
            $candidates
        ))));
    }

    /** @return array<int, string> */
    private function workerPathControlCandidates(string $endpoint, string $encodedSyncId): array
    {
        $normalized = rtrim($endpoint, '/');
        $candidates = [];

        foreach ([
            '/api/eazybusiness/v2/workers/control',
            '/api/eazybusiness/v1/workers/control',
            '/api/eazybusiness/workers/control',
        ] as $controlPath) {
            if (str_contains($normalized, $controlPath)) {
                $candidates[] = str_replace($controlPath, '/api/eazybusiness/v1/workers/' . $encodedSyncId, $normalized);
                return $candidates;
            }
        }

        if (preg_match('#/api/eazybusiness/(?:v[0-9]+/)?workers(?:/\{(?:id|syncId|sync_id)\})?$#', $normalized) === 1) {
            $base = preg_replace('#(?:/\{(?:id|syncId|sync_id)\})?$#', '', $normalized) ?? $normalized;
            $candidates[] = rtrim($base, '/') . '/' . $encodedSyncId;
        }

        return $candidates;
    }

    private function workerVersionedControlEndpoint(string $endpoint): string
    {
        if (str_contains($endpoint, '/api/eazybusiness/v1/workers/')) {
            return $endpoint;
        }

        if (str_contains($endpoint, '/api/eazybusiness/v2/workers/')) {
            return str_replace('/api/eazybusiness/v2/workers/', '/api/eazybusiness/v1/workers/', $endpoint);
        }

        if (str_contains($endpoint, '/api/eazybusiness/workers/')) {
            return str_replace('/api/eazybusiness/workers/', '/api/eazybusiness/v1/workers/', $endpoint);
        }

        return $endpoint;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, array<string, mixed>>
     */
    private function workerControlRequestVariants(array $options, string $endpoint): array
    {
        $withoutApiVersion = $options;
        $headers = is_array($withoutApiVersion['headers'] ?? null) ? $withoutApiVersion['headers'] : [];
        $headers['api-version'] = '';
        $withoutApiVersion['headers'] = $headers;

        if (str_contains($endpoint, '/api/eazybusiness/v1/') || str_contains($endpoint, '/api/eazybusiness/v2/')) {
            return ['without api-version header' => $withoutApiVersion];
        }

        return [
            'without api-version header' => $withoutApiVersion,
            'default headers' => $options,
        ];
    }

    /** @return array<int, string> */
    private function workerEndpointCandidates(string $endpoint): array
    {
        $candidates = [$endpoint];

        if (str_contains($endpoint, '/api/eazybusiness/v1/workers')) {
            $candidates[] = str_replace('/api/eazybusiness/v1/workers', '/api/eazybusiness/workers', $endpoint);
            $candidates[] = str_replace('/api/eazybusiness/v1/workers', '/api/eazybusiness/v2/workers', $endpoint);
            $candidates[] = preg_replace('#/api/eazybusiness/v1/workers(?:/.*)?$#', '/api/eazybusiness/v2/workers/control', $endpoint) ?? $endpoint;
        }

        if (str_contains($endpoint, '/api/eazybusiness/workers')) {
            $candidates[] = str_replace('/api/eazybusiness/workers', '/api/eazybusiness/v1/workers', $endpoint);
            $candidates[] = str_replace('/api/eazybusiness/workers', '/api/eazybusiness/v2/workers', $endpoint);
            $candidates[] = preg_replace('#/api/eazybusiness/workers(?:/.*)?$#', '/api/eazybusiness/v2/workers/control', $endpoint) ?? $endpoint;
        }

        if (str_contains($endpoint, '/api/eazybusiness/v2/workers')) {
            $candidates[] = str_replace('/api/eazybusiness/v2/workers', '/api/eazybusiness/workers', $endpoint);
            $candidates[] = str_replace('/api/eazybusiness/v2/workers', '/api/eazybusiness/v1/workers', $endpoint);
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && !str_ends_with($candidate, '/')) {
                $candidates[] = $candidate . '/';
            }
        }

        return array_values(array_unique($candidates));
    }

    /** @return array<int, string> */
    private function workerCollectionEndpointCandidates(string $endpoint): array
    {
        $candidates = $this->workerEndpointCandidates($endpoint);

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '/api/eazybusiness/v1/workers')) {
                $candidates[] = str_replace('/api/eazybusiness/v1/workers', '/api/eazybusiness/workers', $candidate);
                $candidates[] = str_replace('/api/eazybusiness/v1/workers', '/api/eazybusiness/v2/workers', $candidate);
            }

            if (str_contains($candidate, '/api/eazybusiness/v2/workers')) {
                $candidates[] = str_replace('/api/eazybusiness/v2/workers', '/api/eazybusiness/workers', $candidate);
                $candidates[] = str_replace('/api/eazybusiness/v2/workers', '/api/eazybusiness/v1/workers', $candidate);
            }

            if (str_contains($candidate, '/v1/workers')) {
                $candidates[] = str_replace('/v1/workers', '/workers', $candidate);
            }

            if (str_contains($candidate, '/workers/control')) {
                continue;
            }
        }

        return array_values(array_unique($candidates));
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

        return str_contains($message, 'formatnotparsable')
            || str_contains($message, 'ambiguous api version')
            || str_contains($message, 'api versions were requested')
            || str_contains($message, 'only a single api version')
            || str_contains($message, 'guid string')
            || str_contains($message, 'invalid key format')
            || str_contains($message, 'key must from type guid')
            || str_contains($message, 'key must from type int')
            || str_contains($message, "property 'reference'")
            || str_contains($message, 'unsupported api version')
            || str_contains($message, 'does not support the api version');
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, array<string, mixed>>
     */
    private function workerRequestVariants(array $options = []): array
    {
        $withoutApiVersion = $options;
        $headers = is_array($withoutApiVersion['headers'] ?? null) ? $withoutApiVersion['headers'] : [];
        $headers['api-version'] = '';
        $withoutApiVersion['headers'] = $headers;

        $withApiVersion20 = $options;
        $headers = is_array($withApiVersion20['headers'] ?? null) ? $withApiVersion20['headers'] : [];
        $headers['api-version'] = '2.0';
        $withApiVersion20['headers'] = $headers;

        $withApiVersion21 = $options;
        $headers = is_array($withApiVersion21['headers'] ?? null) ? $withApiVersion21['headers'] : [];
        $headers['api-version'] = '2.1';
        $withApiVersion21['headers'] = $headers;

        return [
            'without api-version header' => $withoutApiVersion,
            'api-version 2.0 header' => $withApiVersion20,
            'api-version 2.1 header' => $withApiVersion21,
            'default headers' => $options,
        ];
    }

    private function workerSyncBody(string $syncId, string $syncName, string $endpoint): ?string
    {
        $template = trim((string) ($this->config['worker_sync_body_template'] ?? ''));
        $usesControlEndpoint = str_contains(strtolower($endpoint), '/workers/control');
        $usesPathControlEndpoint = $this->workerActionIsInPath($endpoint);

        if (
            $template === ''
            || $template === '{}'
            || ($usesControlEndpoint && in_array($template, ['{"Action":0}', '{"action":0}'], true))
            || ($usesPathControlEndpoint && (str_contains($template, 'sync_id') || str_contains(strtolower($template), 'syncid')))
        ) {
            $template = $usesControlEndpoint ? '{"syncId":"{{sync_id}}","action":0}' : '{"Action":0}';
        }

        $body = str_replace(
            ['{{sync_id}}', '{{sync_name}}', '{{sales_channel_id}}', '{{sales_channel_name}}'],
            [
                $this->jsonStringFragment($syncId),
                $this->jsonStringFragment($syncName),
                $this->jsonStringFragment($syncId),
                $this->jsonStringFragment($syncName),
            ],
            $template
        );

        json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('JTL worker sync body is not valid JSON: ' . json_last_error_msg());
        }

        return $body;
    }

    /** @return array<int, array<string, mixed>> */
    private function workerSyncRequestPayloads(string $syncId, string $syncName, string $endpoint): array
    {
        $payloads = [];
        $seen = [];
        $usesPathControlEndpoint = $this->workerActionIsInPath($endpoint);

        if ($usesPathControlEndpoint) {
            foreach ([
                ['Action' => 0],
                ['action' => 0],
            ] as $payload) {
                $this->addWorkerBody($payloads, $seen, json_encode($payload, JSON_THROW_ON_ERROR));
            }
        }

        $configuredBody = $this->workerSyncBody($syncId, $syncName, $endpoint);

        if ($configuredBody !== null) {
            $this->addWorkerBody($payloads, $seen, $configuredBody);
        }

        if (!str_contains(strtolower($endpoint), '/workers/control')) {
            return $payloads !== [] ? $payloads : [[]];
        }

        foreach ([
            ['syncId' => $syncId, 'action' => 0],
            ['SyncId' => $syncId, 'Action' => 0],
            ['syncId' => ['value' => $syncId], 'action' => 0],
            ['SyncId' => ['Value' => $syncId], 'Action' => 0],
            ['syncId' => ['type' => 'guid', 'value' => $syncId], 'action' => 0],
            ['SyncId' => ['Type' => 'guid', 'Value' => $syncId], 'Action' => 0],
            ['syncId' => ['reference' => $syncId], 'action' => 0],
            ['SyncId' => ['Reference' => $syncId], 'Action' => 0],
            ['syncId' => ['type' => 'guid', 'reference' => $syncId], 'action' => 0],
            ['SyncId' => ['Type' => 'guid', 'Reference' => $syncId], 'Action' => 0],
            ['syncId' => ['key' => $syncId], 'action' => 0],
            ['SyncId' => ['Key' => $syncId], 'Action' => 0],
            ['syncId' => 'guid:' . $syncId, 'action' => 0],
            ['SyncId' => 'guid:' . $syncId, 'Action' => 0],
            ['syncId' => 'Guid:' . $syncId, 'action' => 0],
            ['SyncId' => 'Guid:' . $syncId, 'Action' => 0],
        ] as $payload) {
            $this->addWorkerBody($payloads, $seen, json_encode($payload, JSON_THROW_ON_ERROR));
        }

        return $payloads;
    }

    private function workerActionIsInPath(string $endpoint): bool
    {
        $path = strtolower(parse_url($endpoint, PHP_URL_PATH) ?: $endpoint);

        return preg_match('#/workers/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/?$#', $path) === 1;
    }

    /**
     * @param array<int, array<string, mixed>> $payloads
     * @param array<string, bool> $seen
     */
    private function addWorkerBody(array &$payloads, array &$seen, string $body): void
    {
        if (isset($seen[$body])) {
            return;
        }

        $seen[$body] = true;
        $payloads[] = [
            'body' => $body,
            'headers' => ['Content-Type' => 'application/json'],
        ];
    }

    private function jsonStringFragment(string $value): string
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);

        return substr($encoded, 1, -1);
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
