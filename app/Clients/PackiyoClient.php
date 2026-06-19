<?php

declare(strict_types=1);

namespace App\Clients;

use App\Support\Config;
use App\Support\HttpClient;

final class PackiyoClient
{
    private HttpClient $http;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?HttpClient $http = null)
    {
        $this->config = Config::load('packiyo');
        $this->http = $http ?? new HttpClient(
            (string) ($this->config['base_url'] ?? ''),
            $this->headers(),
            (int) ($this->config['timeout'] ?? 30)
        );
    }

    /** @param array<string, mixed> $payload */
    public function createOrder(array $payload): array
    {
        return $this->http->post((string) $this->config['orders_endpoint'], [
            'json' => $payload,
        ]);
    }

    public function getOrder(string $id): array
    {
        return $this->http->get($this->endpoint('order_endpoint', $id));
    }

    public function findOrder(string $externalId): array
    {
        return $this->http->get((string) $this->config['find_order_endpoint'], [
            'query' => ['external_id' => $externalId],
        ]);
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

        return $headers;
    }

    private function endpoint(string $name, string $id): string
    {
        return str_replace('{id}', rawurlencode($id), (string) ($this->config[$name] ?? ''));
    }
}
