<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Clients\JtlClient;
use App\Support\Database;
use App\Support\Logger;
use RuntimeException;

final class JtlWorkerController
{
    public function start(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        Database::migrate();

        $syncId = $this->postedString('worker_sync_id');
        $manualSyncId = $this->postedString('worker_sync_id_manual');
        $syncName = $this->postedString('worker_sync_name');

        if ($syncId === '' && $manualSyncId !== '') {
            $syncId = $manualSyncId;
        }

        try {
            $jtl = new JtlClient();
            [$syncId, $syncName] = $this->resolveSync($jtl, $syncId, $syncName);
            $response = $jtl->startWorkerSync($syncId, $syncName);
            $message = 'JTL Worker abgleich iniciado.';

            if ($syncId !== '') {
                $message .= ' Sync #' . $syncId . '.';
            }

            (new Logger())->info('jtl_worker', $message . ' Response: ' . $this->shortJson($response));
        } catch (\Throwable $exception) {
            $message = 'No se pudo iniciar el JTL Worker abgleich: ' . $this->friendlyError($exception->getMessage());
            (new Logger())->error('jtl_worker', $message);
        }

        header(
            'Location: ' . $this->url('/') . '?tab=jtl-orders&notice=' . rawurlencode($message),
            true,
            303
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveSync(JtlClient $jtl, string $syncId, string $syncName): array
    {
        if ($syncId !== '') {
            return [$syncId, $syncName];
        }

        $syncs = $jtl->getWorkerSyncs();

        if (count($syncs) === 1) {
            $sync = $syncs[0];
            $resolvedId = $this->workerSyncId($sync);

            if ($resolvedId !== '') {
                return [$resolvedId, $this->workerSyncName($sync)];
            }
        }

        if ($syncs === []) {
            throw new RuntimeException('JTL did not return worker syncs. Revisa scopes worker.getworkersyncs/system.worker.read o el endpoint /workers.');
        }

        throw new RuntimeException('Selecciona un sync ID manual. Disponibles: ' . $this->syncSummary($syncs));
    }

    /** @param array<int, array<string, mixed>> $syncs */
    private function syncSummary(array $syncs): string
    {
        $parts = [];

        foreach ($syncs as $sync) {
            $id = $this->workerSyncId($sync);
            $name = $this->workerSyncName($sync);

            if ($id === '') {
                $parts[] = $this->shortJson($sync);
                continue;
            }

            $parts[] = trim($name . ' #' . $id);
        }

        return implode(', ', array_slice($parts, 0, 8));
    }

    /** @param array<string, mixed> $sync */
    private function workerSyncId(array $sync): string
    {
        return $this->firstScalar($sync, [
            'syncId',
            'SyncId',
            'workerSyncId',
            'WorkerSyncId',
            'id',
            'Id',
            'ID',
            'internalId',
            'InternalId',
            'number',
            'Number',
            'key',
            'Key',
            'value',
            'Value',
            'identifier',
            'Identifier',
            'guid',
            'Guid',
        ]) ?? '';
    }

    /** @param array<string, mixed> $sync */
    private function workerSyncName(array $sync): string
    {
        return $this->firstScalar($sync, [
            'name',
            'Name',
            'syncName',
            'SyncName',
            'displayName',
            'DisplayName',
            'description',
            'Description',
            'title',
            'Title',
            'platform',
            'Platform',
        ]) ?? '';
    }

    /** @param array<string, mixed> $data */
    private function firstScalar(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || !is_scalar($data[$key])) {
                continue;
            }

            $value = trim((string) $data[$key]);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function postedString(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function friendlyError(string $message): string
    {
        if (
            str_contains($message, 'FormatNotParsable')
            || str_contains($message, 'Guid string should only contain hexadecimal')
        ) {
            return $message
                . ' Tip: usa los endpoints versionados /api/eazybusiness/v1/workers, /api/eazybusiness/v1/workers/{id} y /api/eazybusiness/v1/workers/status en Ajustes.';
        }

        return $message;
    }

    /** @param array<string, mixed> $data */
    private function shortJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($json) || $json === '') {
            return '{}';
        }

        return strlen($json) > 500 ? substr($json, 0, 497) . '...' : $json;
    }

    private function url(string $path): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

        return $base . '/' . ltrim($path, '/');
    }
}
