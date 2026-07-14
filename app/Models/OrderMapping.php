<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

final class OrderMapping
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    public function existsByJtlOrderId(string $jtlOrderId): bool
    {
        $statement = $this->connection()->prepare('SELECT 1 FROM order_mappings WHERE jtl_order_id = ? LIMIT 1');
        $statement->bind_param('s', $jtlOrderId);
        $statement->execute();
        $result = $statement->get_result();

        return (bool) $result->fetch_row();
    }

    /** @return array<string, mixed>|null */
    public function findByJtlOrderId(string $jtlOrderId): ?array
    {
        $statement = $this->connection()->prepare('SELECT * FROM order_mappings WHERE jtl_order_id = ? LIMIT 1');
        $statement->bind_param('s', $jtlOrderId);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();

        return is_array($row) ? $row : null;
    }

    public function deleteByJtlOrderId(string $jtlOrderId): void
    {
        $statement = $this->connection()->prepare('DELETE FROM order_mappings WHERE jtl_order_id = ?');
        $statement->bind_param('s', $jtlOrderId);
        $statement->execute();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $statement = $this->connection()->prepare(
            'INSERT INTO order_mappings (
                jtl_order_id,
                jtl_order_number,
                packiyo_order_id,
                packiyo_order_number,
                packiyo_customer_id,
                packiyo_customer_name,
                synced_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $jtlOrderId = (string) $data['jtl_order_id'];
        $jtlOrderNumber = isset($data['jtl_order_number']) ? (string) $data['jtl_order_number'] : null;
        $packiyoOrderId = (string) $data['packiyo_order_id'];
        $packiyoOrderNumber = isset($data['packiyo_order_number']) ? (string) $data['packiyo_order_number'] : null;
        $packiyoCustomerId = isset($data['packiyo_customer_id']) ? (string) $data['packiyo_customer_id'] : null;
        $packiyoCustomerName = isset($data['packiyo_customer_name']) ? (string) $data['packiyo_customer_name'] : null;
        $syncedAt = (string) ($data['synced_at'] ?? date('Y-m-d H:i:s'));

        $statement->bind_param(
            'sssssss',
            $jtlOrderId,
            $jtlOrderNumber,
            $packiyoOrderId,
            $packiyoOrderNumber,
            $packiyoCustomerId,
            $packiyoCustomerName,
            $syncedAt
        );
        $statement->execute();

        return (int) $this->connection()->insert_id;
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        $statement = $this->connection()->prepare('SELECT * FROM order_mappings ORDER BY synced_at DESC, id DESC LIMIT ?');
        $statement->bind_param('i', $limit);
        $statement->execute();

        return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(int $limit = 500, ?string $packiyoCustomerId = null): array
    {
        $packiyoCustomerId = trim((string) $packiyoCustomerId);

        if ($packiyoCustomerId !== '') {
            $statement = $this->connection()->prepare(
                'SELECT * FROM order_mappings
                WHERE packiyo_customer_id = ?
                ORDER BY synced_at ASC, id ASC
                LIMIT ?'
            );
            $statement->bind_param('si', $packiyoCustomerId, $limit);
            $statement->execute();

            return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        $statement = $this->connection()->prepare('SELECT * FROM order_mappings ORDER BY synced_at ASC, id ASC LIMIT ?');
        $statement->bind_param('i', $limit);
        $statement->execute();

        return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function countSyncedToday(): int
    {
        $statement = $this->connection()->prepare(
            'SELECT COUNT(*) AS total FROM order_mappings WHERE synced_at >= ? AND synced_at < ?'
        );
        $window = $this->todayWindow();
        $statement->bind_param('ss', $window['start'], $window['end']);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();

        return (int) ($row['total'] ?? 0);
    }

    public function lastSyncedAt(): ?string
    {
        $result = $this->connection()->query('SELECT synced_at FROM order_mappings ORDER BY synced_at DESC, id DESC LIMIT 1');
        $row = $result->fetch_assoc();
        $value = $row['synced_at'] ?? null;

        return is_string($value) ? $value : null;
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
