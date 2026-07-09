<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

final class AutomationOrderSkip
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    /**
     * @param array<int, string> $jtlOrderIds
     * @return array<string, string>
     */
    public function findReasonsForOrderIds(array $jtlOrderIds): array
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
            'SELECT jtl_order_id, reason FROM automation_order_skips WHERE jtl_order_id IN (' . $placeholders . ')'
        );

        $types = str_repeat('s', count($jtlOrderIds));
        $refs = [];

        foreach ($jtlOrderIds as $index => $value) {
            $refs[$index] = &$jtlOrderIds[$index];
        }

        $statement->bind_param($types, ...$refs);
        $statement->execute();

        $reasons = [];
        $result = $statement->get_result();

        while ($row = $result->fetch_assoc()) {
            $reasons[(string) $row['jtl_order_id']] = (string) $row['reason'];
        }

        return $reasons;
    }

    public function remember(string $jtlOrderId, ?string $jtlOrderNumber, string $reason): void
    {
        $jtlOrderId = trim($jtlOrderId);
        $reason = trim($reason);

        if ($jtlOrderId === '' || $reason === '') {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $statement = $this->connection()->prepare(
            'INSERT INTO automation_order_skips (
                jtl_order_id,
                jtl_order_number,
                reason,
                first_seen_at,
                last_seen_at,
                skip_count,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, 1, ?, ?)
            ON DUPLICATE KEY UPDATE
                jtl_order_number = VALUES(jtl_order_number),
                reason = VALUES(reason),
                last_seen_at = VALUES(last_seen_at),
                skip_count = skip_count + 1,
                updated_at = VALUES(updated_at)'
        );
        $statement->bind_param('sssssss', $jtlOrderId, $jtlOrderNumber, $reason, $now, $now, $now, $now);
        $statement->execute();
    }

    public function deleteByReason(string $reason): void
    {
        $statement = $this->connection()->prepare('DELETE FROM automation_order_skips WHERE reason = ?');
        $statement->bind_param('s', $reason);
        $statement->execute();
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
