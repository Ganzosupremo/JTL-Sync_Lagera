<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

final class FulfillmentSync
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    public function exists(string $jtlOrderId, string $trackingNumber): bool
    {
        $statement = $this->connection()->prepare(
            "SELECT 1 FROM fulfillment_syncs
            WHERE jtl_order_id = ?
                AND tracking_number = ?
                AND status IN ('synced', 'already_present')
                AND jtl_delivery_note_id IS NOT NULL
            LIMIT 1"
        );
        $statement->bind_param('ss', $jtlOrderId, $trackingNumber);
        $statement->execute();

        return (bool) $statement->get_result()->fetch_row();
    }

    /** @param array<string, mixed> $data */
    public function upsert(array $data): void
    {
        $now = date('Y-m-d H:i:s');
        $statement = $this->connection()->prepare(
            'INSERT INTO fulfillment_syncs (
                jtl_order_id,
                jtl_order_number,
                packiyo_order_id,
                packiyo_customer_id,
                packiyo_customer_name,
                packiyo_shipment_id,
                packiyo_tracking_id,
                tracking_number,
                tracking_url,
                carrier,
                shipped_at,
                jtl_delivery_note_id,
                jtl_package_id,
                status,
                last_error,
                synced_at,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                packiyo_order_id = VALUES(packiyo_order_id),
                packiyo_customer_id = VALUES(packiyo_customer_id),
                packiyo_customer_name = VALUES(packiyo_customer_name),
                packiyo_shipment_id = VALUES(packiyo_shipment_id),
                packiyo_tracking_id = VALUES(packiyo_tracking_id),
                tracking_url = VALUES(tracking_url),
                carrier = VALUES(carrier),
                shipped_at = VALUES(shipped_at),
                jtl_delivery_note_id = VALUES(jtl_delivery_note_id),
                jtl_package_id = VALUES(jtl_package_id),
                status = VALUES(status),
                last_error = VALUES(last_error),
                synced_at = VALUES(synced_at),
                updated_at = VALUES(updated_at)'
        );

        $jtlOrderId = (string) $data['jtl_order_id'];
        $jtlOrderNumber = $this->nullableString($data['jtl_order_number'] ?? null);
        $packiyoOrderId = (string) $data['packiyo_order_id'];
        $packiyoCustomerId = $this->nullableString($data['packiyo_customer_id'] ?? null);
        $packiyoCustomerName = $this->nullableString($data['packiyo_customer_name'] ?? null);
        $packiyoShipmentId = $this->nullableString($data['packiyo_shipment_id'] ?? null);
        $packiyoTrackingId = $this->nullableString($data['packiyo_tracking_id'] ?? null);
        $trackingNumber = (string) $data['tracking_number'];
        $trackingUrl = $this->nullableString($data['tracking_url'] ?? null);
        $carrier = $this->nullableString($data['carrier'] ?? null);
        $shippedAt = $this->nullableString($data['shipped_at'] ?? null);
        $jtlDeliveryNoteId = $this->nullableString($data['jtl_delivery_note_id'] ?? null);
        $jtlPackageId = $this->nullableString($data['jtl_package_id'] ?? null);
        $status = (string) ($data['status'] ?? 'synced');
        $lastError = $this->nullableString($data['last_error'] ?? null);
        $syncedAt = (string) ($data['synced_at'] ?? $now);

        $statement->bind_param(
            'ssssssssssssssssss',
            $jtlOrderId,
            $jtlOrderNumber,
            $packiyoOrderId,
            $packiyoCustomerId,
            $packiyoCustomerName,
            $packiyoShipmentId,
            $packiyoTrackingId,
            $trackingNumber,
            $trackingUrl,
            $carrier,
            $shippedAt,
            $jtlDeliveryNoteId,
            $jtlPackageId,
            $status,
            $lastError,
            $syncedAt,
            $now,
            $now
        );
        $statement->execute();
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 50, ?string $packiyoCustomerId = null): array
    {
        $packiyoCustomerId = trim((string) $packiyoCustomerId);

        if ($packiyoCustomerId !== '') {
            $statement = $this->connection()->prepare(
                'SELECT * FROM fulfillment_syncs
                WHERE packiyo_customer_id = ?
                ORDER BY synced_at DESC, id DESC
                LIMIT ?'
            );
            $statement->bind_param('si', $packiyoCustomerId, $limit);
            $statement->execute();

            return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        $statement = $this->connection()->prepare('SELECT * FROM fulfillment_syncs ORDER BY synced_at DESC, id DESC LIMIT ?');
        $statement->bind_param('i', $limit);
        $statement->execute();

        return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
