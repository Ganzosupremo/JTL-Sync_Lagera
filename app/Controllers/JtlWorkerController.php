<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Clients\JtlClient;
use App\Support\Config;
use App\Support\Database;
use App\Support\Logger;
use RuntimeException;

final class JtlWorkerController
{
    public function discover(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        Database::migrate();
        $this->startSession();

        try {
            $syncs = (new JtlClient())->getWorkerSyncs();
            $_SESSION['jtl_worker_syncs'] = $syncs;
            $_SESSION['jtl_worker_syncs_read_at'] = date('Y-m-d H:i:s');

            $message = 'GET /workers leido correctamente. Syncs encontrados: ' . count($syncs) . '.';

            if ($syncs !== []) {
                $message .= ' ' . $this->syncSummary($syncs);
            }

            (new Logger())->info('jtl_worker', $message . ' Response: ' . $this->shortJson(['items' => $syncs]));
        } catch (\Throwable $exception) {
            $message = 'No se pudo leer GET /workers: ' . $this->friendlyError($exception->getMessage());
            (new Logger())->error('jtl_worker', $message);
        }

        $this->redirectWithNotice($message);
    }

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
            $syncId = $this->normalizeWorkerSyncId($syncId);
            (new Logger())->info('jtl_worker', 'Worker control debug: ' . $this->shortJson($jtl->workerControlDebugInfo($syncId)));
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

        $this->redirectWithNotice($message);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveSync(JtlClient $jtl, string $syncId, string $syncName): array
    {
        if ($syncId !== '') {
            return [$syncId, $syncName];
        }

        $configuredSyncId = trim((string) Config::get('jtl.worker_sync_id', ''));

        if ($configuredSyncId !== '') {
            return [$configuredSyncId, trim((string) Config::get('jtl.worker_sync_name', ''))];
        }

        if (!(bool) Config::get('jtl.worker_discovery_enabled', false)) {
            throw new RuntimeException('Ingresa un Sync ID manual o guarda JTL_WORKER_SYNC_ID en Ajustes. Puede ser UUID o ID numerico, segun lo que espere tu JTL API.');
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
            'identifier',
            'Identifier',
            'guid',
            'Guid',
            'syncId',
            'SyncId',
            'workerSyncId',
            'WorkerSyncId',
            'key',
            'Key',
            'value',
            'Value',
            'id',
            'Id',
            'ID',
            'internalId',
            'InternalId',
            'number',
            'Number',
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

    private function startSession(): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_start();
    }

    private function redirectWithNotice(string $message): void
    {
        $this->startSession();
        $_SESSION['flash_notice'] = $this->compactNotice($message);

        header('Location: ' . $this->url('/') . '?tab=jtl-orders', true, 303);
        exit;
    }

    private function compactNotice(string $message): string
    {
        $limit = 1600;

        if (strlen($message) <= $limit) {
            return $message;
        }

        return substr($message, 0, $limit - 70)
            . '... Detalle completo guardado en Logs.';
    }

    private function friendlyError(string $message): string
    {
        if (str_contains($message, 'HTTP 401')) {
            return $message
                . ' Tip: Swagger suele mandar Authorization sin el prefijo Wawi. Este boton usa el token guardado por la app; si tambien da 401, registra la app de nuevo con scopes worker.getworkersyncs/system.worker.read y obten un token nuevo.';
        }

        if (
            str_contains($message, 'FormatNotParsable')
            || str_contains($message, 'Guid string should only contain hexadecimal')
            || str_contains($message, 'Key must from Type int')
        ) {
            return $message
                . ' Tip: esta JTL API espera un Sync ID numerico. Usa el kZiel/kShop de Worker.tTarget; para Temu EsSo vimos 2 en SQL.';
        }

        return $message;
    }

    private function normalizeWorkerSyncId(string $syncId): string
    {
        $syncId = trim($syncId, "{} \t\n\r\0\x0B");

        if ($syncId === '') {
            throw new RuntimeException('Ingresa el Identifier UUID del WorkerSyncItem.');
        }

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $syncId)) {
            if (preg_match('/^\d+$/', $syncId) === 1) {
                return $syncId;
            }

            throw new RuntimeException('El Sync ID de Worker debe ser el Identifier UUID del WorkerSyncItem o el ID numerico que espera esta JTL API. Para Temu EsSo, segun tu SQL, prueba 2.');
        }

        return strtolower($syncId);
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
