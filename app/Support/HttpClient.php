<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class HttpClient
{
    /** @param array<string, string> $defaultHeaders */
    public function __construct(
        private readonly string $baseUrl,
        private readonly array $defaultHeaders = [],
        private readonly int $timeout = 30
    ) {
    }

    /** @param array<string, mixed> $options */
    public function get(string $path, array $options = []): array
    {
        return $this->request('GET', $path, $options);
    }

    /** @param array<string, mixed> $options */
    public function post(string $path, array $options = []): array
    {
        return $this->request('POST', $path, $options);
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $path, array $options = []): array
    {
        $headers = array_merge($this->defaultHeaders, $options['headers'] ?? []);
        $body = null;

        if (array_key_exists('json', $options)) {
            $body = json_encode($options['json'], JSON_THROW_ON_ERROR);
            $headers['Content-Type'] = 'application/json';
        } elseif (array_key_exists('body', $options)) {
            $body = (string) $options['body'];
        }

        $url = $this->buildUrl($path, $options['query'] ?? []);

        if (function_exists('curl_init')) {
            return $this->requestWithCurl($method, $url, $headers, $body);
        }

        return $this->requestWithStreams($method, $url, $headers, $body);
    }

    /** @param array<string, mixed> $query */
    private function buildUrl(string $path, array $query): string
    {
        $url = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        if ($this->baseUrl === '' && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            throw new RuntimeException('API base URL is not configured.');
        }

        if ($query !== []) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . http_build_query($query);
        }

        return $url;
    }

    /** @param array<string, string> $headers */
    private function requestWithCurl(string $method, string $url, array $headers, ?string $body): array
    {
        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
        ]);

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($curl);

        if ($response === false) {
            $message = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException($message);
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $responseBody = substr((string) $response, $headerSize);
        curl_close($curl);

        return $this->decodeResponse($status, $responseBody);
    }

    /** @param array<string, string> $headers */
    private function requestWithStreams(string $method, string $url, array $headers, ?string $body): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $this->formatHeaders($headers)),
                'content' => $body ?? '',
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = file_get_contents($url, false, $context);
        $status = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches) === 1) {
                $status = (int) $matches[1];
                break;
            }
        }

        if ($responseBody === false) {
            throw new RuntimeException('HTTP request failed.');
        }

        return $this->decodeResponse($status, $responseBody);
    }

    /** @param array<string, string> $headers */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];

        foreach ($headers as $name => $value) {
            if ($value === '') {
                continue;
            }

            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }

    private function decodeResponse(int $status, string $body): array
    {
        if ($status >= 400) {
            throw new RuntimeException('HTTP ' . $status . ': ' . $body);
        }

        if (trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['raw' => $body];
        }

        return is_array($decoded) ? $decoded : ['value' => $decoded];
    }
}
