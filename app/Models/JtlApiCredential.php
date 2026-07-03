<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

final class JtlApiCredential
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    /** @return array<string, mixed>|null */
    public function latest(): ?array
    {
        $result = $this->connection()->query(
            'SELECT * FROM jtl_api_credentials ORDER BY COALESCE(approved_at, requested_at, created_at) DESC, id DESC LIMIT 1'
        );
        $row = $result->fetch_assoc();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function latestPending(): ?array
    {
        $statement = $this->connection()->prepare(
            "SELECT * FROM jtl_api_credentials
            WHERE registration_request_id IS NOT NULL
            AND status = 'pending'
            ORDER BY COALESCE(approved_at, requested_at, created_at) DESC, id DESC
            LIMIT 1"
        );
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();

        return is_array($row) ? $row : null;
    }

    public function cancelLatestPending(): bool
    {
        $pending = $this->latestPending();

        if ($pending === null || empty($pending['id'])) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $status = 'cancelled';
        $id = (int) $pending['id'];
        $statement = $this->connection()->prepare(
            'UPDATE jtl_api_credentials
            SET status = ?, updated_at = ?
            WHERE id = ? AND status = ?'
        );
        $pendingStatus = 'pending';
        $statement->bind_param('ssis', $status, $now, $id, $pendingStatus);
        $statement->execute();

        return $statement->affected_rows > 0;
    }

    public function currentApiKey(): ?string
    {
        $statement = $this->connection()->prepare(
            "SELECT api_key FROM jtl_api_credentials
            WHERE status = 'approved'
            AND api_key IS NOT NULL
            AND api_key <> ''
            AND api_key <> 'Array'
            ORDER BY approved_at DESC, id DESC
            LIMIT 1"
        );
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $apiKey = $row['api_key'] ?? null;

        return $this->hasUsableApiKey(['api_key' => $apiKey]) ? (string) $apiKey : null;
    }

    public function createPending(
        string $registrationRequestId,
        ?string $authenticationEndpoint = null,
        ?string $apiVersion = null
    ): int
    {
        $now = date('Y-m-d H:i:s');
        $status = 'pending';
        $statement = $this->connection()->prepare(
            'INSERT INTO jtl_api_credentials (
                registration_request_id,
                authentication_endpoint,
                api_version,
                status,
                requested_at,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->bind_param(
            'sssssss',
            $registrationRequestId,
            $authenticationEndpoint,
            $apiVersion,
            $status,
            $now,
            $now,
            $now
        );
        $statement->execute();

        return (int) $this->connection()->insert_id;
    }

    /** @param array<int, string> $grantedScopes */
    public function markApproved(string $registrationRequestId, string $apiKey, array $grantedScopes): void
    {
        $now = date('Y-m-d H:i:s');
        $status = 'approved';
        $scopes = json_encode(array_values($grantedScopes), JSON_THROW_ON_ERROR);
        $statement = $this->connection()->prepare(
            'UPDATE jtl_api_credentials
            SET api_key = ?, granted_scopes = ?, status = ?, approved_at = ?, updated_at = ?
            WHERE registration_request_id = ?'
        );
        $statement->bind_param('ssssss', $apiKey, $scopes, $status, $now, $now, $registrationRequestId);
        $statement->execute();

        if ($statement->affected_rows > 0) {
            return;
        }

        $statement = $this->connection()->prepare(
            'INSERT INTO jtl_api_credentials (
                registration_request_id,
                api_key,
                granted_scopes,
                status,
                requested_at,
                approved_at,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->bind_param('ssssssss', $registrationRequestId, $apiKey, $scopes, $status, $now, $now, $now, $now);
        $statement->execute();
    }

    public function status(): string
    {
        $latest = $this->latest();

        if ($latest === null) {
            return 'missing_config';
        }

        if (($latest['status'] ?? '') === 'approved' && $this->hasUsableApiKey($latest)) {
            return 'configured';
        }

        if (($latest['status'] ?? '') === 'pending') {
            return 'registration_pending';
        }

        return 'missing_config';
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }

    /** @param array<string, mixed> $row */
    private function hasUsableApiKey(array $row): bool
    {
        $apiKey = $row['api_key'] ?? null;

        return is_string($apiKey) && trim($apiKey) !== '' && $apiKey !== 'Array';
    }
}
