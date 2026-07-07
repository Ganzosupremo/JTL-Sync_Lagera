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
            'query' => ['filter[external_id]' => $externalId],
        ]);
    }

    public function findOrderByNumber(string $number): array
    {
        return $this->http->get((string) $this->config['find_order_endpoint'], [
            'query' => ['filter[number]' => $number],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function listProductsForCustomer(string $customerId, int $maxPages = 10): array
    {
        $endpoint = (string) $this->config['products_endpoint'];
        $query = [
            'filter[customer]' => $customerId,
            'page[size]' => 100,
        ];

        $products = [];
        $page = 1;
        $nextEndpoint = $endpoint;
        $nextQuery = $query;
        $requests = 0;

        do {
            $requests++;

            if ($nextQuery !== [] && !isset($nextQuery['page[number]'])) {
                $nextQuery['page[number]'] = $page;
            }

            $response = $this->http->get($nextEndpoint, ['query' => $nextQuery]);
            $products = array_merge($products, $this->collection($response));

            $nextLink = $this->nextLink($response);
            $pageInfo = $this->pageInfo($response);

            if ($nextLink !== null) {
                $nextEndpoint = $nextLink;
                $nextQuery = [];
                continue;
            }

            $currentPage = (int) ($pageInfo['currentPage'] ?? $pageInfo['current_page'] ?? $page);
            $lastPage = (int) ($pageInfo['lastPage'] ?? $pageInfo['last_page'] ?? $currentPage);

            if ($currentPage >= $lastPage) {
                break;
            }

            $page = $currentPage + 1;
            $nextEndpoint = $endpoint;
            $nextQuery = $query;
            $nextQuery['page[number]'] = $page;
        } while ($requests < $maxPages);

        return $products;
    }

    /** @return array<int, array<string, mixed>> */
    public function listCustomers(?string $updatedAtMin = null): array
    {
        $endpoint = (string) $this->config['customers_endpoint'];
        $query = [
            'include' => 'contact_information',
            'page[size]' => 100,
        ];

        if ($updatedAtMin !== null && $updatedAtMin !== '') {
            $query['filter[updated_at_min]'] = $this->dateForFilter($updatedAtMin);
        }

        $customers = [];
        $page = 1;
        $nextEndpoint = $endpoint;
        $nextQuery = $query;
        $requests = 0;

        do {
            $requests++;

            if ($nextQuery !== [] && !isset($nextQuery['page[number]'])) {
                $nextQuery['page[number]'] = $page;
            }

            $response = $this->http->get($nextEndpoint, ['query' => $nextQuery]);
            $customers = array_merge($customers, $this->collection($response));

            $nextLink = $this->nextLink($response);
            $pageInfo = $this->pageInfo($response);

            if ($nextLink !== null) {
                $nextEndpoint = $nextLink;
                $nextQuery = [];
                continue;
            }

            $currentPage = (int) ($pageInfo['currentPage'] ?? $pageInfo['current_page'] ?? $page);
            $lastPage = (int) ($pageInfo['lastPage'] ?? $pageInfo['last_page'] ?? $currentPage);

            if ($currentPage >= $lastPage) {
                break;
            }

            $page = $currentPage + 1;
            $nextEndpoint = $endpoint;
            $nextQuery = $query;
            $nextQuery['page[number]'] = $page;
        } while ($requests < 50);

        return $customers;
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
        $mediaType = (string) ($this->config['media_type'] ?? 'application/vnd.api+json');
        $headers = [
            'Accept' => $mediaType,
            'Content-Type' => $mediaType,
        ];
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

    /**
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    private function collection(array $response): array
    {
        $data = $response['data'] ?? $response['Data'] ?? [];

        if (!is_array($data)) {
            return [];
        }

        $resources = array_is_list($data) ? $data : [$data];
        $resources = array_values(array_filter($resources, 'is_array'));

        return $this->withIncludedContactInformation($resources, $response);
    }

    /**
     * @param array<int, array<string, mixed>> $resources
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    private function withIncludedContactInformation(array $resources, array $response): array
    {
        $included = $response['included'] ?? $response['Included'] ?? [];

        if (!is_array($included) || $included === []) {
            return $resources;
        }

        $lookup = [];

        foreach ($included as $resource) {
            if (!is_array($resource)) {
                continue;
            }

            $type = (string) ($resource['type'] ?? $resource['Type'] ?? '');
            $id = (string) ($resource['id'] ?? $resource['Id'] ?? '');

            if ($type === '' || $id === '') {
                continue;
            }

            $lookup[$type . ':' . $id] = $resource;
        }

        foreach ($resources as $index => $resource) {
            $contact = $resource['relationships']['contact_information']['data']
                ?? $resource['relationships']['contactInformation']['data']
                ?? null;

            if (!is_array($contact)) {
                continue;
            }

            $contactType = (string) ($contact['type'] ?? '');
            $contactId = (string) ($contact['id'] ?? '');
            $includedContact = $lookup[$contactType . ':' . $contactId] ?? null;

            if (!is_array($includedContact)) {
                continue;
            }

            $contactAttributes = $includedContact['attributes'] ?? [];

            if (!is_array($contactAttributes)) {
                continue;
            }

            $attributes = $resource['attributes'] ?? [];
            $attributes = is_array($attributes) ? $attributes : [];

            foreach ($contactAttributes as $key => $value) {
                if (!array_key_exists((string) $key, $attributes)) {
                    $attributes[(string) $key] = $value;
                }
            }

            $resources[$index]['attributes'] = $attributes;
        }

        return $resources;
    }

    /** @param array<string, mixed> $response */
    private function nextLink(array $response): ?string
    {
        $next = $response['links']['next'] ?? $response['Links']['Next'] ?? null;

        if (is_string($next) && trim($next) !== '') {
            return $next;
        }

        if (is_array($next) && isset($next['href']) && is_string($next['href']) && trim($next['href']) !== '') {
            return $next['href'];
        }

        return null;
    }

    /** @param array<string, mixed> $response */
    private function pageInfo(array $response): array
    {
        $page = $response['meta']['page'] ?? $response['Meta']['Page'] ?? [];

        return is_array($page) ? $page : [];
    }

    private function dateForFilter(string $value): string
    {
        $timestamp = strtotime($value);

        return $timestamp === false ? $value : date('Y-m-d\TH:i:s', $timestamp);
    }
}
