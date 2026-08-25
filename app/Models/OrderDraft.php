<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

final class OrderDraft
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $jtlOrderId): ?array
    {
        $statement = $this->connection()->prepare('SELECT * FROM jtl_order_drafts WHERE jtl_order_id = ? LIMIT 1');
        $statement->bind_param('s', $jtlOrderId);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();

        if (!is_array($row)) {
            return null;
        }

        foreach (['source_json', 'data_json', 'sent_payload_json'] as $key) {
            $decoded = isset($row[$key]) && is_string($row[$key]) ? json_decode($row[$key], true) : null;
            $row[str_replace('_json', '', $key)] = is_array($decoded) ? $decoded : null;
        }

        return $row;
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $data */
    public function save(string $jtlOrderId, ?string $number, ?string $customerId, array $source, array $data): void
    {
        $now = date('Y-m-d H:i:s');
        $sourceJson = json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $dataJson = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $status = 'draft';
        $statement = $this->connection()->prepare(
            'INSERT INTO jtl_order_drafts (
                jtl_order_id, jtl_order_number, packiyo_customer_id, status,
                source_json, data_json, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                jtl_order_number = VALUES(jtl_order_number),
                packiyo_customer_id = VALUES(packiyo_customer_id),
                status = VALUES(status),
                source_json = VALUES(source_json),
                data_json = VALUES(data_json),
                sent_payload_json = NULL,
                sent_at = NULL,
                updated_at = VALUES(updated_at)'
        );
        $statement->bind_param('ssssssss', $jtlOrderId, $number, $customerId, $status, $sourceJson, $dataJson, $now, $now);
        $statement->execute();
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $data @param array<string, mixed> $payload */
    public function markSent(string $jtlOrderId, ?string $number, ?string $customerId, array $source, array $data, array $payload): void
    {
        $this->save($jtlOrderId, $number, $customerId, $source, $data);
        $now = date('Y-m-d H:i:s');
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $status = 'sent';
        $statement = $this->connection()->prepare(
            'UPDATE jtl_order_drafts SET status = ?, sent_payload_json = ?, sent_at = ?, updated_at = ? WHERE jtl_order_id = ?'
        );
        $statement->bind_param('sssss', $status, $payloadJson, $now, $now, $jtlOrderId);
        $statement->execute();
    }

    public function delete(string $jtlOrderId): void
    {
        $statement = $this->connection()->prepare('DELETE FROM jtl_order_drafts WHERE jtl_order_id = ? AND status <> ?');
        $sent = 'sent';
        $statement->bind_param('ss', $jtlOrderId, $sent);
        $statement->execute();
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
