<?php

declare(strict_types=1);

namespace App\Clients;

use App\Support\Config;
use App\Support\HttpException;
use App\Support\HttpClient;

final class JtlRegistrationClient
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

    /** @return array<string, mixed> */
    public function startRegistration(): array
    {
        $tried = [];
        $lastException = null;

        foreach ($this->authenticationEndpoints() as $endpoint) {
            foreach ($this->apiVersions() as $apiVersion) {
                try {
                    $response = $this->http->post($endpoint, [
                        'headers' => $this->headers($apiVersion),
                        'json' => $this->registrationPayload(),
                    ]);
                    $response['_authentication_endpoint'] = $endpoint;
                    $response['_api_version'] = $apiVersion;

                    return $response;
                } catch (HttpException $exception) {
                    $lastException = $exception;
                    $tried[] = $exception->url() . ' api-version=' . $apiVersion;

                    if (!$this->shouldTryNextCandidate($exception)) {
                        throw $exception;
                    }
                }
            }
        }

        if ($lastException instanceof HttpException && $lastException->statusCode() !== 404) {
            throw $lastException;
        }

        throw new HttpException(
            404,
            'POST',
            implode(', ', $tried),
            'JTL authentication endpoint was not found in any configured candidate.'
        );
    }

    /** @return array<string, mixed> */
    public function fetchApiKey(
        string $registrationRequestId,
        ?string $authenticationEndpoint = null,
        ?string $apiVersion = null
    ): array {
        $tried = [];
        $lastException = null;
        $endpoints = $authenticationEndpoint !== null && $authenticationEndpoint !== ''
            ? [$authenticationEndpoint]
            : $this->authenticationEndpoints();
        $apiVersions = $apiVersion !== null && $apiVersion !== '' ? [$apiVersion] : $this->apiVersions();

        foreach ($endpoints as $endpoint) {
            $path = rtrim($endpoint, '/') . '/' . rawurlencode($registrationRequestId);

            foreach ($apiVersions as $candidateApiVersion) {
                try {
                    return $this->http->get($path, [
                        'headers' => $this->headers($candidateApiVersion),
                    ]);
                } catch (HttpException $exception) {
                    $lastException = $exception;
                    $tried[] = $exception->url() . ' api-version=' . $candidateApiVersion;

                    if (!$this->shouldTryNextCandidate($exception)) {
                        throw $exception;
                    }
                }
            }
        }

        if ($lastException instanceof HttpException && $lastException->statusCode() !== 404) {
            throw $lastException;
        }

        throw new HttpException(
            404,
            'GET',
            implode(', ', $tried),
            'JTL authentication endpoint was not found in any configured candidate.'
        );
    }

    /** @return array<string, string> */
    private function headers(?string $apiVersion = null): array
    {
        return [
            'Accept' => 'application/json',
            'x-appid' => (string) ($this->config['app_id'] ?? ''),
            'x-appversion' => (string) ($this->config['app_version'] ?? ''),
            'api-version' => $apiVersion ?? (string) ($this->config['api_version'] ?? '1.0'),
            'x-challengecode' => (string) ($this->config['challenge_code'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private function registrationPayload(): array
    {
        return [
            'AppId' => (string) ($this->config['app_id'] ?? ''),
            'DisplayName' => (string) ($this->config['display_name'] ?? 'Lagera JTL Sync'),
            'Description' => (string) ($this->config['description'] ?? ''),
            'Version' => (string) ($this->config['app_version'] ?? '1.0.0'),
            'AppIcon' => $this->appIcon(),
            'ProviderName' => (string) ($this->config['provider_name'] ?? ''),
            'ProviderWebsite' => (string) ($this->config['provider_website'] ?? ''),
            'MandatoryApiScopes' => $this->config['mandatory_scopes'] ?? [],
            'OptionalApiScopes' => $this->config['optional_scopes'] ?? [],
        ];
    }

    /** @return array<int, string> */
    private function authenticationEndpoints(): array
    {
        $endpoints = [(string) ($this->config['authentication_endpoint'] ?? '/authentication')];

        foreach (($this->config['authentication_endpoint_candidates'] ?? []) as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $endpoints[] = $candidate;
            }
        }

        return array_values(array_unique($endpoints));
    }

    /** @return array<int, string> */
    private function apiVersions(): array
    {
        $versions = [(string) ($this->config['api_version'] ?? '1.0')];

        foreach (($this->config['api_version_candidates'] ?? []) as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $versions[] = $candidate;
            }
        }

        return array_values(array_unique($versions));
    }

    private function shouldTryNextCandidate(HttpException $exception): bool
    {
        if ($exception->statusCode() === 404) {
            return true;
        }

        return $exception->statusCode() === 400
            && str_contains(strtolower($exception->getMessage()), 'unsupported api version');
    }

    private function appIcon(): string
    {
        $icon = trim((string) ($this->config['app_icon'] ?? ''));

        if (str_starts_with($icon, 'data:image/') && str_contains($icon, ',')) {
            return substr($icon, (int) strpos($icon, ',') + 1);
        }

        return $icon;
    }
}
