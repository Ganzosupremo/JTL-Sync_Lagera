<?php

declare(strict_types=1);

namespace App\Clients;

use App\Support\Config;
use App\Support\HttpClient;

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
            (int) ($this->config['timeout'] ?? 30)
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
        $response = $this->http->get($this->endpoint('order_items_endpoint', $id));

        return $this->collection($response);
    }

    public function status(): string
    {
        return $this->isConfigured() ? 'configured' : 'missing_config';
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

        return ($this->config['api_key'] ?? '') !== '';
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $headers = ['Accept' => 'application/json'];
        $authType = (string) ($this->config['auth_type'] ?? 'bearer');
        $apiKey = (string) ($this->config['api_key'] ?? '');

        if ($authType === 'bearer' && $apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
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

    private function endpoint(string $name, string $id): string
    {
        return str_replace('{id}', rawurlencode($id), (string) ($this->config[$name] ?? ''));
    }

    /** @return array<int, array<string, mixed>> */
    private function collection(array $response): array
    {
        foreach (['data', 'items', 'orders', 'results'] as $key) {
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
