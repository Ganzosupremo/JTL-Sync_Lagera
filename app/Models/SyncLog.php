<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

final class SyncLog
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    public function create(string $level, string $source, string $message): int
    {
        $statement = $this->connection()->prepare(
            'INSERT INTO sync_logs (created_at, level, source, message)
            VALUES (?, ?, ?, ?)'
        );
        $createdAt = date('Y-m-d H:i:s');

        $statement->bind_param('ssss', $createdAt, $level, $source, $message);
        $statement->execute();

        return (int) $this->connection()->insert_id;
    }

    /**
     * Deletes logs older than $days in batches, so a large backlog never holds a long lock on the table.
     * Returns how many rows were removed; $days <= 0 keeps everything.
     */
    public function prune(int $days, int $batchSize = 5000): int
    {
        if ($days <= 0) {
            return 0;
        }

        $statement = $this->connection()->prepare('DELETE FROM sync_logs WHERE created_at < ? LIMIT ?');
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        $statement->bind_param('si', $cutoff, $batchSize);
        $deleted = 0;

        do {
            $statement->execute();
            $deleted += $statement->affected_rows;
        } while ($statement->affected_rows >= $batchSize);

        return $deleted;
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 100): array
    {
        $statement = $this->connection()->prepare('SELECT * FROM sync_logs ORDER BY created_at DESC, id DESC LIMIT ?');
        $statement->bind_param('i', $limit);
        $statement->execute();

        return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function countErrorsToday(): int
    {
        $statement = $this->connection()->prepare(
            "SELECT COUNT(*) AS total FROM sync_logs
            WHERE level = 'error' AND created_at >= ? AND created_at < ?"
        );
        $window = $this->todayWindow();
        $statement->bind_param('ss', $window['start'], $window['end']);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();

        return (int) ($row['total'] ?? 0);
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }

    /** @return array{start: string, end: string} */
    private function todayWindow(): array
    {
        return [
            'start' => date('Y-m-d 00:00:00'),
            'end' => date('Y-m-d 00:00:00', strtotime('+1 day')),
        ];
    }
}
