<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

/**
 * Last error per JTL order whose sync to Packiyo failed, so failing orders stay visible in
 * the dashboard instead of only in the log. Rows are removed once the order syncs.
 */
final class OrderSyncFailure
{
    private const MAX_ERROR_LENGTH = 2000;

    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    public function record(string $jtlOrderId, ?string $jtlOrderNumber, string $error): void
    {
        $jtlOrderId = trim($jtlOrderId);

        if ($jtlOrderId === '') {
            return;
        }

        $error = mb_substr(trim($error) !== '' ? trim($error) : 'Error desconocido.', 0, self::MAX_ERROR_LENGTH);
        $now = date('Y-m-d H:i:s');
        $statement = $this->connection()->prepare(
            'INSERT INTO order_sync_failures (
                jtl_order_id,
                jtl_order_number,
                last_error,
                failure_count,
                first_failed_at,
                last_failed_at
            ) VALUES (?, ?, ?, 1, ?, ?)
            ON DUPLICATE KEY UPDATE
                jtl_order_number = VALUES(jtl_order_number),
                last_error = VALUES(last_error),
                failure_count = failure_count + 1,
                last_failed_at = VALUES(last_failed_at)'
        );
        $statement->bind_param('sssss', $jtlOrderId, $jtlOrderNumber, $error, $now, $now);
        $statement->execute();
    }

    public function clear(string $jtlOrderId): void
    {
        $statement = $this->connection()->prepare('DELETE FROM order_sync_failures WHERE jtl_order_id = ?');
        $statement->bind_param('s', $jtlOrderId);
        $statement->execute();
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        $statement = $this->connection()->prepare('SELECT * FROM order_sync_failures ORDER BY last_failed_at DESC, id DESC LIMIT ?');
        $statement->bind_param('i', $limit);
        $statement->execute();

        return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * @param array<int, string> $jtlOrderIds
     * @return array<string, array<string, mixed>>
     */
    public function findByJtlOrderIds(array $jtlOrderIds): array
    {
        $jtlOrderIds = array_values(array_unique(array_filter(
            array_map(static fn (string $id): string => trim($id), $jtlOrderIds),
            static fn (string $id): bool => $id !== ''
        )));

        if ($jtlOrderIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($jtlOrderIds), '?'));
        $statement = $this->connection()->prepare(
            'SELECT * FROM order_sync_failures WHERE jtl_order_id IN (' . $placeholders . ')'
        );
        $statement->bind_param(str_repeat('s', count($jtlOrderIds)), ...$jtlOrderIds);
        $statement->execute();

        $failures = [];
        $result = $statement->get_result();

        while ($row = $result->fetch_assoc()) {
            $failures[(string) $row['jtl_order_id']] = $row;
        }

        return $failures;
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
