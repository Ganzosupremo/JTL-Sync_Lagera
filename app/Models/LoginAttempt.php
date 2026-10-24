<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

/**
 * Failed logins per username/email, used to lock that login for a while after too many
 * failures. Counted per login rather than per IP: behind the Cloudflare tunnel every request
 * arrives from the same address, so an IP limit would lock everyone out at once.
 */
final class LoginAttempt
{
    public const MAX_FAILURES = 5;

    public const WINDOW_MINUTES = 15;

    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    public function isLocked(string $login): bool
    {
        $login = $this->normalize($login);
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_MINUTES * 60);
        $statement = $this->connection()->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE login = ? AND attempted_at >= ?'
        );
        $statement->bind_param('ss', $login, $since);
        $statement->execute();
        $row = $statement->get_result()->fetch_row();

        return (int) ($row[0] ?? 0) >= self::MAX_FAILURES;
    }

    public function recordFailure(string $login): void
    {
        $login = $this->normalize($login);
        $now = date('Y-m-d H:i:s');
        $statement = $this->connection()->prepare('INSERT INTO login_attempts (login, attempted_at) VALUES (?, ?)');
        $statement->bind_param('ss', $login, $now);
        $statement->execute();

        $expired = date('Y-m-d H:i:s', time() - 86400);
        $statement = $this->connection()->prepare('DELETE FROM login_attempts WHERE attempted_at < ?');
        $statement->bind_param('s', $expired);
        $statement->execute();
    }

    public function clear(string $login): void
    {
        $login = $this->normalize($login);
        $statement = $this->connection()->prepare('DELETE FROM login_attempts WHERE login = ?');
        $statement->bind_param('s', $login);
        $statement->execute();
    }

    private function normalize(string $login): string
    {
        return mb_substr(strtolower(trim($login)), 0, 255);
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
