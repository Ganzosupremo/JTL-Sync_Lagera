<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use mysqli;

final class OrderCorrection
{
    public function __construct(private readonly ?mysqli $db = null)
    {
    }

    /** @param array<int, string> $customerIds @return array<string, mixed> */
    public function createJob(string $windowStart, array $customerIds, ?int $userId): array
    {
        $id = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');
        $customerIdsJson = $this->json(array_values($customerIds));
        $statement = $this->connection()->prepare(
            'INSERT INTO order_correction_jobs
             (id, status, cursor_page, scanned_orders, detected_lines, window_start, window_end, customer_ids_json, created_by_user_id, created_at, updated_at)
             VALUES (?, ?, 1, 0, 0, ?, ?, ?, ?, ?, ?)'
        );
        $status = 'running';
        $statement->bind_param('sssssiss', $id, $status, $windowStart, $now, $customerIdsJson, $userId, $now, $now);
        $statement->execute();

        return $this->job($id) ?? [];
    }

    /** @return array<string, mixed>|null */
    public function job(string $id): ?array
    {
        $statement = $this->connection()->prepare('SELECT * FROM order_correction_jobs WHERE id = ? LIMIT 1');
        $statement->bind_param('s', $id);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function latestJob(): ?array
    {
        $result = $this->connection()->query(
            'SELECT * FROM order_correction_jobs ORDER BY updated_at DESC, created_at DESC LIMIT 1'
        );
        $row = $result->fetch_assoc();
        return is_array($row) ? $row : null;
    }

    public function advanceJob(string $id, int $page, int $offset, int $scanned, int $detected, string $status, ?string $error = null): void
    {
        $now = date('Y-m-d H:i:s');
        $statement = $this->connection()->prepare(
            'UPDATE order_correction_jobs
             SET cursor_page = ?, cursor_offset = ?, scanned_orders = scanned_orders + ?, detected_lines = detected_lines + ?,
                 status = ?, last_error = ?, updated_at = ?
             WHERE id = ?'
        );
        $statement->bind_param('iiiissss', $page, $offset, $scanned, $detected, $status, $error, $now, $id);
        $statement->execute();
    }

    /** @param array<string, mixed> $data */
    public function upsertLine(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $snapshot = $this->json($data['current_snapshot'] ?? []);
        $jtlSnapshot = $this->nullableJson($data['jtl_snapshot'] ?? null);
        $suggestions = $this->nullableJson($data['suggestions'] ?? []);
        $statement = $this->connection()->prepare(
            'INSERT INTO order_correction_lines (
                job_id, packiyo_order_id, packiyo_order_number, packiyo_customer_id, packiyo_status,
                jtl_order_id, jtl_order_number, jtl_source, line_index, original_external_id,
                original_sku, original_name, jtl_name, jtl_sku, quantity, price,
                current_snapshot_json, jtl_snapshot_json, suggestions_json,
                proposed_product_id, proposed_sku, proposed_name, assignment_scope, assignment_key,
                selected, result, error, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                packiyo_order_number = VALUES(packiyo_order_number),
                packiyo_customer_id = VALUES(packiyo_customer_id),
                packiyo_status = VALUES(packiyo_status),
                jtl_order_id = VALUES(jtl_order_id),
                jtl_order_number = VALUES(jtl_order_number),
                jtl_source = VALUES(jtl_source),
                original_external_id = VALUES(original_external_id),
                original_sku = VALUES(original_sku),
                original_name = VALUES(original_name),
                jtl_name = VALUES(jtl_name),
                jtl_sku = VALUES(jtl_sku),
                quantity = VALUES(quantity),
                price = VALUES(price),
                current_snapshot_json = VALUES(current_snapshot_json),
                jtl_snapshot_json = VALUES(jtl_snapshot_json),
                suggestions_json = VALUES(suggestions_json),
                updated_at = VALUES(updated_at)'
        );
        $selected = !empty($data['selected']) ? 1 : 0;
        $result = (string) ($data['result'] ?? 'pending');
        $values = [
            (string) $data['job_id'], (string) $data['packiyo_order_id'],
            (string) ($data['packiyo_order_number'] ?? ''), (string) ($data['packiyo_customer_id'] ?? ''),
            (string) ($data['packiyo_status'] ?? ''), (string) ($data['jtl_order_id'] ?? ''),
            (string) ($data['jtl_order_number'] ?? ''), (string) ($data['jtl_source'] ?? 'unavailable'),
            (int) $data['line_index'], (string) ($data['original_external_id'] ?? ''),
            (string) $data['original_sku'], (string) ($data['original_name'] ?? ''),
            (string) ($data['jtl_name'] ?? ''), (string) ($data['jtl_sku'] ?? ''),
            (float) ($data['quantity'] ?? 0), (float) ($data['price'] ?? 0),
            $snapshot, $jtlSnapshot, $suggestions,
            (string) ($data['proposed_product_id'] ?? ''), (string) ($data['proposed_sku'] ?? ''),
            (string) ($data['proposed_name'] ?? ''), (string) ($data['assignment_scope'] ?? ''),
            (string) ($data['assignment_key'] ?? ''), $selected, $result,
            isset($data['error']) ? (string) $data['error'] : null, $now, $now,
        ];
        $statement->bind_param(
            'ssssssssisssssddssssssssissss',
            ...$values
        );
        $statement->execute();

        if ($this->connection()->insert_id > 0) {
            return (int) $this->connection()->insert_id;
        }
        $existing = $this->lineByNaturalKey((string) $data['job_id'], (string) $data['packiyo_order_id'], (int) $data['line_index']);
        return (int) ($existing['id'] ?? 0);
    }

    /** @return array<int, array<string, mixed>> */
    public function lines(string $jobId, array $filters = [], int $limit = 1000): array
    {
        $sql = 'SELECT * FROM order_correction_lines WHERE job_id = ?';
        $types = 's';
        $params = [$jobId];
        foreach (['packiyo_customer_id' => 'customer', 'packiyo_status' => 'status', 'jtl_source' => 'source', 'result' => 'result'] as $column => $filter) {
            $value = trim((string) ($filters[$filter] ?? ''));
            if ($value !== '') {
                $sql .= ' AND ' . $column . ' = ?';
                $types .= 's';
                $params[] = $value;
            }
        }
        $sql .= ' ORDER BY packiyo_order_number ASC, line_index ASC LIMIT ?';
        $types .= 'i';
        $params[] = max(1, min(5000, $limit));
        $statement = $this->connection()->prepare($sql);
        $statement->bind_param($types, ...$params);
        $statement->execute();
        return array_map([$this, 'decodeLine'], $statement->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    /** @return array{jtl_matches:int,packiyo_assignments:int} */
    public function summary(string $jobId): array
    {
        $statement = $this->connection()->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN TRIM(COALESCE(jtl_name, '')) <> '' THEN 1 ELSE 0 END), 0) AS jtl_matches,
                COALESCE(SUM(CASE WHEN TRIM(COALESCE(proposed_product_id, '')) <> '' THEN 1 ELSE 0 END), 0) AS packiyo_assignments
             FROM order_correction_lines WHERE job_id = ?"
        );
        $statement->bind_param('s', $jobId);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        return [
            'jtl_matches' => (int) ($row['jtl_matches'] ?? 0),
            'packiyo_assignments' => (int) ($row['packiyo_assignments'] ?? 0),
        ];
    }

    /** @return array<string, mixed>|null */
    public function line(int $id): ?array
    {
        $statement = $this->connection()->prepare('SELECT * FROM order_correction_lines WHERE id = ? LIMIT 1');
        $statement->bind_param('i', $id);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        return is_array($row) ? $this->decodeLine($row) : null;
    }

    /** @param array<int, int> $ids @return array<int, array<string, mixed>> */
    public function selectedLines(string $jobId, array $ids = []): array
    {
        $lines = $this->lines($jobId, [], 5000);
        if ($ids !== []) {
            $wanted = array_fill_keys(array_map('intval', $ids), true);
            return array_values(array_filter($lines, static fn (array $line): bool => isset($wanted[(int) $line['id']])));
        }
        return array_values(array_filter($lines, static fn (array $line): bool => !empty($line['selected'])));
    }

    /** @param array<int, int> $lineIds */
    public function select(array $lineIds, bool $selected): void
    {
        if ($lineIds === []) {
            return;
        }
        $ids = array_values(array_unique(array_map('intval', $lineIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$selected ? 1 : 0, date('Y-m-d H:i:s')], $ids);
        $this->connection()->execute_query(
            'UPDATE order_correction_lines SET selected = ?, updated_at = ? WHERE id IN (' . $placeholders . ')',
            $params
        );
    }

    public function assignLine(int $lineId, array $product, string $scope, string $key): bool
    {
        $current = $this->line($lineId);
        if ($current === null) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $selected = 1;
        $result = 'assigned';
        $id = (string) $product['id'];
        $sku = (string) $product['sku'];
        $name = (string) $product['name'];
        $sameAssignment = (string) ($current['proposed_product_id'] ?? '') === $id
            && (string) ($current['proposed_sku'] ?? '') === $sku;
        if ($sameAssignment && in_array((string) ($current['result'] ?? ''), ['corrected', 'already_corrected', 'ignored_cancelled'], true)) {
            return false;
        }
        $statement = $this->connection()->prepare(
            'UPDATE order_correction_lines SET proposed_product_id = ?, proposed_sku = ?, proposed_name = ?,
             assignment_scope = ?, assignment_key = ?, selected = ?, result = ?, error = NULL,
             preview_hash = NULL, previewed_at = NULL, updated_at = ? WHERE id = ?'
        );
        $statement->bind_param('sssssissi', $id, $sku, $name, $scope, $key, $selected, $result, $now, $lineId);
        $statement->execute();
        return $statement->affected_rows > 0;
    }

    public function saveGroupAssignment(string $customerId, string $sourceName, array $product): void
    {
        $normalized = \App\Services\OrderPreparationService::normalizeName($sourceName);
        $now = date('Y-m-d H:i:s');
        $scope = 'group';
        $active = 1;
        $orderId = '';
        $this->connection()->execute_query(
            'INSERT INTO order_correction_assignments (
                packiyo_customer_id, normalized_jtl_name, source_name, packiyo_product_id,
                packiyo_sku, packiyo_product_name, scope, packiyo_order_id, active, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE source_name = VALUES(source_name), packiyo_product_id = VALUES(packiyo_product_id),
                packiyo_sku = VALUES(packiyo_sku), packiyo_product_name = VALUES(packiyo_product_name),
                active = 1, updated_at = VALUES(updated_at)'
            ,
            [$customerId, $normalized, $sourceName, (string) $product['id'], (string) $product['sku'],
                (string) $product['name'], $scope, '', $active, $now, $now]
        );
    }

    /** @return array<string, mixed>|null */
    public function groupAssignment(string $customerId, string $sourceName): ?array
    {
        $normalized = \App\Services\OrderPreparationService::normalizeName($sourceName);
        $scope = 'group';
        $statement = $this->connection()->prepare(
            'SELECT * FROM order_correction_assignments
             WHERE packiyo_customer_id = ? AND normalized_jtl_name = ? AND scope = ? AND active = 1 LIMIT 1'
        );
        $statement->bind_param('sss', $customerId, $normalized, $scope);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        return is_array($row) ? $row : null;
    }

    public function markPreview(int $lineId, string $hash): void
    {
        $now = date('Y-m-d H:i:s');
        $result = 'previewed';
        $statement = $this->connection()->prepare(
            'UPDATE order_correction_lines SET preview_hash = ?, previewed_at = ?, result = ?, updated_at = ? WHERE id = ?'
        );
        $statement->bind_param('ssssi', $hash, $now, $result, $now, $lineId);
        $statement->execute();
    }

    public function markResult(int $lineId, string $result, ?string $error = null): void
    {
        $now = date('Y-m-d H:i:s');
        $statement = $this->connection()->prepare(
            'UPDATE order_correction_lines SET result = ?, error = ?, updated_at = ? WHERE id = ?'
        );
        $statement->bind_param('sssi', $result, $error, $now, $lineId);
        $statement->execute();
    }

    public function addAttempt(string $jobId, ?int $lineId, string $orderId, string $action, mixed $before, mixed $after, string $status, ?string $error, ?int $userId): void
    {
        $this->connection()->execute_query(
            'INSERT INTO order_correction_attempts
             (job_id, line_id, packiyo_order_id, action, before_json, after_json, status, error, created_by_user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            ,
            [$jobId, $lineId, $orderId, $action, $this->nullableJson($before), $this->nullableJson($after),
                $status, $error, $userId, date('Y-m-d H:i:s')]
        );
    }

    /** @return array<string, mixed>|null */
    private function lineByNaturalKey(string $jobId, string $orderId, int $index): ?array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM order_correction_lines WHERE job_id = ? AND packiyo_order_id = ? AND line_index = ? LIMIT 1'
        );
        $statement->bind_param('ssi', $jobId, $orderId, $index);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        return is_array($row) ? $this->decodeLine($row) : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function decodeLine(array $row): array
    {
        foreach (['current_snapshot_json' => 'current_snapshot', 'jtl_snapshot_json' => 'jtl_snapshot', 'suggestions_json' => 'suggestions'] as $column => $key) {
            $decoded = isset($row[$column]) ? json_decode((string) $row[$column], true) : null;
            $row[$key] = is_array($decoded) ? $decoded : [];
        }
        return $row;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function nullableJson(mixed $value): ?string
    {
        return $value === null ? null : $this->json($value);
    }

    private function connection(): mysqli
    {
        return $this->db ?? Database::connection();
    }
}
