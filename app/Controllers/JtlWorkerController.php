<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Clients\JtlClient;
use App\Support\Database;
use App\Support\Logger;

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

        $message = 'Iniciar abgleich desde la app fue desactivado. Deja JTL Worker 2.0 corriendo en JTL-Wawi; la app solo lee ordenes ya importadas y ejecuta /automation/run para Packiyo y tracking.';
        (new Logger())->warning('jtl_worker', $message);

        $this->redirectWithNotice($message);
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
                . ' Tip: la lectura Worker por API es solo diagnostico. Si JTL Worker 2.0 ya esta corriendo en JTL-Wawi, puedes dejar esta lectura desactivada.';
        }

        if (str_contains($message, 'HTTP 500') && str_contains($message, 'Unknown Internal Error')) {
            return $message
                . ' Tip: no uses la app para iniciar el marketplace abgleich. Dejalo configurado y corriendo desde JTL Worker 2.0.';
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
