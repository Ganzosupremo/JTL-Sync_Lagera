<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

/**
 * Authorization codes, access tokens and refresh tokens for the MCP endpoint. Only a SHA-256 of
 * each token is stored, so a database dump can't be replayed against /mcp.
 */
final class OAuthToken
{
    public const KIND_CODE = 'code';
    public const KIND_ACCESS = 'access';
    public const KIND_REFRESH = 'refresh';

    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    /**
     * Stores a new token and returns its plain value, which is never stored.
     *
     * @param array{client_id: string, user_id: int|null, user_label: string, redirect_uri?: string|null, code_challenge?: string|null} $data
     */
    public function issue(string $kind, int $ttlSeconds, array $data): string
    {
        $token = bin2hex(random_bytes(32));
        $hash = self::hash($token);
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);
        $now = date('Y-m-d H:i:s');
        $redirectUri = $data['redirect_uri'] ?? null;
        $challenge = $data['code_challenge'] ?? null;
        $statement = $this->connection()->prepare(
            'INSERT INTO oauth_tokens (token_hash, kind, client_id, user_id, user_label, redirect_uri, code_challenge, expires_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->bind_param(
            'sssisssss',
            $hash,
            $kind,
            $data['client_id'],
            $data['user_id'],
            $data['user_label'],
            $redirectUri,
            $challenge,
            $expiresAt,
            $now
        );
        $statement->execute();

        return $token;
    }

    /**
     * A valid (unexpired, unused) token of the given kind, or null.
     *
     * @return array<string, mixed>|null
     */
    public function findValid(string $kind, string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $statement = $this->connection()->prepare(
            'SELECT * FROM oauth_tokens WHERE token_hash = ? AND kind = ? AND used_at IS NULL AND expires_at > ? LIMIT 1'
        );
        $hash = self::hash($token);
        $now = date('Y-m-d H:i:s');
        $statement->bind_param('sss', $hash, $kind, $now);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();

        return is_array($row) ? $row : null;
    }

    /** Marks a code or refresh token as spent. Returns false if someone else spent it first. */
    public function consume(int $id): bool
    {
        $statement = $this->connection()->prepare('UPDATE oauth_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL');
        $now = date('Y-m-d H:i:s');
        $statement->bind_param('si', $now, $id);
        $statement->execute();

        return $statement->affected_rows === 1;
    }

    public function pruneExpired(): void
    {
        $statement = $this->connection()->prepare('DELETE FROM oauth_tokens WHERE expires_at < ?');
        $cutoff = date('Y-m-d H:i:s', strtotime('-1 day'));
        $statement->bind_param('s', $cutoff);
        $statement->execute();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
