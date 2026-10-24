<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

/** Apps (for example Claude) that registered themselves to ask for MCP access. */
final class OAuthClient
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    /** @param array<int, string> $redirectUris */
    public function create(string $clientId, ?string $name, array $redirectUris): void
    {
        $statement = $this->connection()->prepare(
            'INSERT INTO oauth_clients (client_id, client_name, redirect_uris, created_at) VALUES (?, ?, ?, ?)'
        );
        $uris = json_encode(array_values($redirectUris), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $now = date('Y-m-d H:i:s');
        $statement->bind_param('ssss', $clientId, $name, $uris, $now);
        $statement->execute();
    }

    /** @return array{client_id: string, client_name: string|null, redirect_uris: array<int, string>}|null */
    public function find(string $clientId): ?array
    {
        $statement = $this->connection()->prepare('SELECT * FROM oauth_clients WHERE client_id = ? LIMIT 1');
        $statement->bind_param('s', $clientId);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();

        if (!is_array($row)) {
            return null;
        }

        $uris = json_decode((string) $row['redirect_uris'], true);

        return [
            'client_id' => (string) $row['client_id'],
            'client_name' => $row['client_name'] !== null ? (string) $row['client_name'] : null,
            'redirect_uris' => is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [],
        ];
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
