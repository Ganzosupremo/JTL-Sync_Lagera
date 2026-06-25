<?php

declare(strict_types=1);

namespace App\Services;

use App\Clients\JtlRegistrationClient;
use App\Models\JtlApiCredential;
use App\Support\Logger;
use RuntimeException;

final class JtlRegistrationService
{
    public function __construct(
        private readonly ?JtlRegistrationClient $client = null,
        private readonly ?JtlApiCredential $credentials = null,
        private readonly ?Logger $logger = null
    ) {
    }

    public function start(): string
    {
        $response = $this->registrationClient()->startRegistration();
        $registrationRequestId = $this->firstString($response, [
            'registrationRequestId',
            'RegistrationRequestId',
            'requestId',
            'id',
        ]) ?? $this->recursiveFirstString($response, [
            'registrationRequestId',
            'RegistrationRequestId',
            'requestId',
        ]);

        if ($registrationRequestId === null) {
            throw new RuntimeException('JTL did not return a registration request id.');
        }

        $authenticationEndpoint = $this->firstString($response, ['_authentication_endpoint']);
        $apiVersion = $this->firstString($response, ['_api_version']);
        $this->credentialModel()->createPending($registrationRequestId, $authenticationEndpoint, $apiVersion);
        $this->log()->info('jtl_registration', 'JTL registration request created: ' . $registrationRequestId);

        return $registrationRequestId;
    }

    /** @return array{registration_request_id: string, granted_scopes: array<int, string>} */
    public function complete(): array
    {
        $pending = $this->credentialModel()->latestPending();

        if ($pending === null || empty($pending['registration_request_id'])) {
            throw new RuntimeException('No pending JTL registration request was found.');
        }

        $registrationRequestId = (string) $pending['registration_request_id'];
        $authenticationEndpoint = isset($pending['authentication_endpoint']) ? (string) $pending['authentication_endpoint'] : null;
        $apiVersion = isset($pending['api_version']) ? (string) $pending['api_version'] : null;
        $response = $this->registrationClient()->fetchApiKey($registrationRequestId, $authenticationEndpoint, $apiVersion);
        $apiKey = $this->firstString($response, [
            'apiKey',
            'ApiKey',
            'api_key',
            'apiToken',
            'ApiToken',
            'token',
            'Token',
            'accessToken',
            'AccessToken',
            'wawiApiKey',
            'WawiApiKey',
        ]) ?? $this->recursiveFirstString($response, [
            'apiKey',
            'ApiKey',
            'api_key',
            'apiToken',
            'ApiToken',
            'token',
            'Token',
            'accessToken',
            'AccessToken',
            'wawiApiKey',
            'WawiApiKey',
        ]);

        if ($apiKey === null) {
            $encodedResponse = json_encode($this->publicResponse($response), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->log()->warning(
                'jtl_registration',
                'JTL did not return an API key for request ' . $registrationRequestId . '. Response: ' . $encodedResponse
            );

            throw new RuntimeException(
                'JTL did not return an API key yet. JTL response: ' . ($encodedResponse ?: '{}')
            );
        }

        $grantedScopes = $this->stringList($response['grantedScopes'] ?? $response['GrantedScopes'] ?? []);
        $this->credentialModel()->markApproved($registrationRequestId, $apiKey, $grantedScopes);
        $this->log()->info('jtl_registration', 'JTL API key stored for request: ' . $registrationRequestId);

        return [
            'registration_request_id' => $registrationRequestId,
            'granted_scopes' => $grantedScopes,
        ];
    }

    /** @param array<string, mixed> $data */
    private function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $this->stringFromValue($data[$key], $keys);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function recursiveFirstString(array $data, array $keys): ?string
    {
        foreach ($data as $key => $value) {
            if (in_array((string) $key, $keys, true) && $value !== null && $value !== '') {
                $found = $this->stringFromValue($value, $keys);

                if ($found !== null) {
                    return $found;
                }
            }

            if (is_array($value)) {
                $found = $this->recursiveFirstString($value, $keys);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function stringFromValue(mixed $value, array $keys): ?string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            $string = trim((string) $value);

            return $string !== '' && $string !== 'Array' ? $string : null;
        }

        if (!is_array($value)) {
            return null;
        }

        $priorityKeys = array_values(array_unique(array_merge($keys, [
            'value',
            'Value',
            'key',
            'Key',
            'token',
            'Token',
            'apiKey',
            'ApiKey',
            'api_key',
        ])));
        $found = $this->recursiveFirstString($value, $priorityKeys);

        if ($found !== null) {
            return $found;
        }

        if (array_is_list($value)) {
            foreach ($value as $item) {
                $found = $this->stringFromValue($item, $keys);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function publicResponse(array $data): array
    {
        $redacted = $data;

        foreach ($redacted as $key => $value) {
            if (is_array($value)) {
                $redacted[$key] = $this->publicResponse($value);
                continue;
            }

            if (preg_match('/(key|token|secret)/i', (string) $key) === 1 && is_string($value) && $value !== '') {
                $redacted[$key] = substr($value, 0, 6) . '...';
            }
        }

        return $redacted;
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_scalar($item) ? (string) $item : null,
            $value
        )));
    }

    private function registrationClient(): JtlRegistrationClient
    {
        return $this->client ?? new JtlRegistrationClient();
    }

    private function credentialModel(): JtlApiCredential
    {
        return $this->credentials ?? new JtlApiCredential();
    }

    private function log(): Logger
    {
        return $this->logger ?? new Logger();
    }
}
