<?php

declare(strict_types=1);

namespace App\Clients;

use App\Models\JtlApiCredential;
use App\Support\Config;
use App\Support\HttpClient;
use App\Support\HttpException;
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
