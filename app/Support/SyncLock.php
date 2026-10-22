<?php

declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * Named MySQL lock (GET_LOCK) shared by every PHP process using the same database, so the
 * cron, the "sync now" button and single-order syncs never send the same order twice.
 * MySQL releases the lock itself if the process dies, so a crash can't leave it stuck.
 */
final class SyncLock
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    /**
     * Runs $callback while holding $name. Returns null without running it when another
     * process still holds the lock after $timeoutSeconds.
     *
     * @template T
     * @param callable(): T $callback
     * @return T|null
     */
    public function run(string $name, int $timeoutSeconds, callable $callback): mixed
    {
        if (!$this->acquire($name, $timeoutSeconds)) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $this->release($name);
        }
    }

    public function acquire(string $name, int $timeoutSeconds): bool
    {
        $lockName = $this->lockName($name);
        $timeout = max(0, $timeoutSeconds);
        $statement = $this->connection()->prepare('SELECT GET_LOCK(?, ?)');
        $statement->bind_param('si', $lockName, $timeout);
        $statement->execute();
        $row = $statement->get_result()->fetch_row();

        return (int) ($row[0] ?? 0) === 1;
    }

    public function release(string $name): void
    {
        $lockName = $this->lockName($name);
        $statement = $this->connection()->prepare('SELECT RELEASE_LOCK(?)');
        $statement->bind_param('s', $lockName);
        $statement->execute();
    }

    private function lockName(string $name): string
    {
        // Scoped by database so two installs on one MySQL server don't block each other.
        $database = (string) Config::get('database.mysql.database', 'jtlsync');

        return substr($database . ':' . $name, 0, 64);
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
