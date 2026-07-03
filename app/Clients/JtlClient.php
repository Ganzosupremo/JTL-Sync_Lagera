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

        return $this->http->get($endpoint);
    }

    /** @return array<int, array<string, mixed>> */
    public function getWorkerSyncs(): array
    {
        $endpoint = (string) ($this->config['workers_endpoint'] ?? '');

        if ($endpoint === '') {
            return [];
        }

        $response = $this->http->get($endpoint);

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
        $endpoint = $this->workerSyncEndpoint($syncId);

        if ($endpoint === '') {
            throw new RuntimeException('JTL worker endpoint is not configured.');
        }

        if ($syncId === '') {
            throw new RuntimeException('Select a JTL worker sync first.');
        }

        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'GET'], true)) {
            throw new RuntimeException('Unsupported JTL worker sync method: ' . $method);
        }

        $options = [];
        $body = $this->workerSyncBody($syncId, $syncName);

        if ($body !== null) {
            $options['body'] = $body;
            $options['headers'] = ['Content-Type' => 'application/json'];
        }

        return $this->http->request($method, $endpoint, $options);
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

    private function workerSyncBody(string $syncId, string $syncName): ?string
    {
        $template = trim((string) ($this->config['worker_sync_body_template'] ?? ''));

        if ($template === '') {
            return null;
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
