<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;
use mysqli_sql_exception;
use RuntimeException;

final class AppUser
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    public function hasActiveUsers(): bool
    {
        $result = $this->connection()->query('SELECT 1 FROM app_users WHERE active = 1 LIMIT 1');

        return (bool) $result->fetch_row();
    }

    /** @return array<string, mixed>|null */
    public function findByLogin(string $login): ?array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM app_users
            WHERE active = 1 AND (username = ? OR email = ?)
            LIMIT 1'
        );
        $statement->bind_param('ss', $login, $login);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->connection()->prepare('SELECT * FROM app_users WHERE id = ? LIMIT 1');
        $statement->bind_param('i', $id);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();

        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $result = $this->connection()->query(
            'SELECT id, username, email, active, last_login_at, created_at, updated_at
            FROM app_users
            ORDER BY active DESC, username ASC'
        );

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function create(string $username, string $email, string $password): int
    {
        $username = trim($username);
        $email = strtolower(trim($email));

        if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Usuario o email invalido.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('No se pudo hashear el password.');
        }

        $now = date('Y-m-d H:i:s');
        $statement = $this->connection()->prepare(
            'INSERT INTO app_users (
                username,
                email,
                password_hash,
                active,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, 1, ?, ?)'
        );
        $statement->bind_param('sssss', $username, $email, $hash, $now, $now);

        try {
            $statement->execute();
        } catch (mysqli_sql_exception $exception) {
            if ((int) $exception->getCode() === 1062) {
                throw new RuntimeException('Ya existe un usuario con ese username o email.', 0, $exception);
            }

            throw $exception;
        }

        return (int) $this->connection()->insert_id;
    }

    public function markLogin(int $id): void
    {
        $now = date('Y-m-d H:i:s');
        $statement = $this->connection()->prepare(
            'UPDATE app_users SET last_login_at = ?, updated_at = ? WHERE id = ?'
        );
        $statement->bind_param('ssi', $now, $now, $id);
        $statement->execute();
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
