<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;
use RuntimeException;

final class UserInvitation
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    public function create(string $email, ?int $createdByUserId, int $ttlHours): string
    {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email invalido para la invitacion.');
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = $this->hashToken($token);
        $ttlHours = max(1, min($ttlHours, 720));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $ttlHours . ' hours'));
        $now = date('Y-m-d H:i:s');
        $statement = $this->connection()->prepare(
            'INSERT INTO app_user_invitations (
                email,
                token_hash,
                created_by_user_id,
                expires_at,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->bind_param('ssisss', $email, $tokenHash, $createdByUserId, $expiresAt, $now, $now);
        $statement->execute();

        return $token;
    }

    /** @return array<string, mixed>|null */
    public function findValidByToken(string $token): ?array
    {
        $tokenHash = $this->hashToken($token);
        $now = date('Y-m-d H:i:s');
        $statement = $this->connection()->prepare(
            'SELECT * FROM app_user_invitations
            WHERE token_hash = ?
                AND accepted_at IS NULL
                AND revoked_at IS NULL
                AND expires_at > ?
            LIMIT 1'
        );
        $statement->bind_param('ss', $tokenHash, $now);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();

        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        $statement = $this->connection()->prepare(
            'SELECT i.*, creator.username AS created_by_username, accepted.username AS accepted_by_username
            FROM app_user_invitations i
            LEFT JOIN app_users creator ON creator.id = i.created_by_user_id
            LEFT JOIN app_users accepted ON accepted.id = i.accepted_by_user_id
            ORDER BY i.created_at DESC, i.id DESC
            LIMIT ?'
        );
        $statement->bind_param('i', $limit);
        $statement->execute();

        return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function accept(int $id, int $userId): void
    {
        $now = date('Y-m-d H:i:s');
        $statement = $this->connection()->prepare(
            'UPDATE app_user_invitations
            SET accepted_by_user_id = ?, accepted_at = ?, updated_at = ?
            WHERE id = ? AND accepted_at IS NULL AND revoked_at IS NULL'
        );
        $statement->bind_param('issi', $userId, $now, $now, $id);
        $statement->execute();
    }

    public function revoke(int $id): void
    {
        $now = date('Y-m-d H:i:s');
        $statement = $this->connection()->prepare(
            'UPDATE app_user_invitations
            SET revoked_at = ?, updated_at = ?
            WHERE id = ? AND accepted_at IS NULL AND revoked_at IS NULL'
        );
        $statement->bind_param('ssi', $now, $now, $id);
        $statement->execute();
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
