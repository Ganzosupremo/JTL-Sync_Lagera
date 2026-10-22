<?php

declare(strict_types=1);

use App\Support\HttpClient;

/**
 * In-memory HttpClient for tests: answers from registered routes and records every request.
 * A request with no matching route fails loudly, so tests notice unexpected API calls.
 */
final class FakeHttpClient extends HttpClient
{
    /** @var array<int, array{method: string, path: string, query: array<string, mixed>, json: mixed}> */
    public array $requests = [];

    /** @var array<int, array{method: string, path: string, query: array<string, mixed>|null, response: mixed}> */
    private array $routes = [];

    public function __construct()
    {
        parent::__construct('https://fake.test');
    }

    /**
     * Registers a response for METHOD + path (and optionally an exact query).
     * $response may be an array, a Throwable to throw, or a callable receiving the request.
     *
     * @param array<string, mixed>|null $query
     */
    public function on(string $method, string $path, mixed $response, ?array $query = null): self
    {
        $this->routes[] = ['method' => strtoupper($method), 'path' => $path, 'query' => $query, 'response' => $response];

        return $this;
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $path, array $options = []): array
    {
        $request = [
            'method' => strtoupper($method),
            'path' => $path,
            'query' => is_array($options['query'] ?? null) ? $options['query'] : [],
            'json' => $options['json'] ?? null,
        ];
        $this->requests[] = $request;

        foreach ($this->routes as $route) {
            if ($route['method'] !== $request['method'] || $route['path'] !== $path) {
                continue;
            }

            if ($route['query'] !== null && $route['query'] != $request['query']) {
                continue;
            }

            $response = $route['response'];

            if (is_callable($response)) {
                $response = $response($request);
            }

            if ($response instanceof Throwable) {
                throw $response;
            }

            return $response;
        }

        throw new RuntimeException('Unexpected request in test: ' . $request['method'] . ' ' . $path . ' ' . json_encode($request['query']));
    }

    /** @return array<int, array{method: string, path: string, query: array<string, mixed>, json: mixed}> */
    public function requestsTo(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => $request['method'] === strtoupper($method) && $request['path'] === $path
        ));
    }
}
