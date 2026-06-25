<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

final class JtlOrderSource
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $result = $this->connection()->query(
            'SELECT * FROM jtl_order_sources
            ORDER BY source_type ASC, source_value ASC'
        );

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * @param array<string, array{
     *     source_type: string,
     *     source_value: string,
     *     source_path: string|null,
     *     order_count: int,
     *     sample_order_id: string|null,
     *     sample_order_number: string|null
     * }> $sources
     */
    public function upsertDetected(array $sources): void
    {
        $now = date('Y-m-d H:i:s');
        $this->connection()->query('DELETE FROM jtl_order_sources');

        foreach ($sources as $source) {
            $type = trim((string) $source['source_type']);
            $value = trim((string) $source['source_value']);
            $path = trim((string) ($source['source_path'] ?? ''));
            $path = $path !== '' ? $path : null;

            if ($type === '' || $value === '') {
                continue;
            }

            $orderCount = (int) $source['order_count'];
            $sampleOrderId = $source['sample_order_id'];
            $sampleOrderNumber = $source['sample_order_number'];

            $statement = $this->connection()->prepare(
                'INSERT INTO jtl_order_sources (
                    source_type,
                    source_value,
                    source_path,
                    order_count,
                    sample_order_id,
                    sample_order_number,
                    last_seen_at,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    source_path = VALUES(source_path),
                    order_count = VALUES(order_count),
                    sample_order_id = VALUES(sample_order_id),
                    sample_order_number = VALUES(sample_order_number),
                    last_seen_at = VALUES(last_seen_at),
                    updated_at = VALUES(updated_at)'
            );
            $statement->bind_param(
                'sssisssss',
                $type,
                $value,
                $path,
                $orderCount,
                $sampleOrderId,
                $sampleOrderNumber,
                $now,
                $now,
                $now
            );
            $statement->execute();
        }
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
