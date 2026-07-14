<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Clients\JtlClient;
use App\Support\Database;
use App\Support\Logger;
use Throwable;

final class JtlWorkflowController
{
    public function salesOrderEvents(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        Database::migrate();
        $this->startSession();

        try {
            $events = (new JtlClient())->getSalesOrderWorkflowEvents();
            $_SESSION['jtl_sales_order_workflow_events'] = $events;
            $_SESSION['jtl_sales_order_workflow_events_read_at'] = date('Y-m-d H:i:s');

            $message = 'Workflow events de Aufträge leidos correctamente. Eventos encontrados: ' . count($events) . '.';

            if ($events !== []) {
                $message .= ' ' . $this->eventSummary($events);
            }

            (new Logger())->info('jtl_workflow', $message . ' Response: ' . $this->shortJson(['items' => $events]));
        } catch (Throwable $exception) {
            $message = 'No se pudieron leer los workflow events de Aufträge: ' . $this->friendlyError($exception->getMessage());
            (new Logger())->error('jtl_workflow', $message);
        }

        $this->redirectWithNotice($message);
    }

    /** @param array<int, array<string, mixed>> $events */
    private function eventSummary(array $events): string
    {
        $parts = [];

        foreach ($events as $event) {
            $id = $this->firstScalar($event, ['Id', 'id', 'ID']);
            $name = $this->firstScalar($event, ['Name', 'name', 'DisplayName', 'displayName']) ?? '';

            if ($id === null) {
                $parts[] = $this->shortJson($event);
                continue;
            }

            $parts[] = trim($name . ' #' . $id);
        }

        return implode(', ', array_slice($parts, 0, 8));
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

        header('Location: ' . $this->url('/') . '?tab=settings', true, 303);
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
        if (str_contains($message, 'HTTP 403') || str_contains($message, 'HTTP 401')) {
            return $message
                . ' Tip: registra la app de nuevo en JTL-Wawi y aprueba los scopes salesorder.querysalesorderworkflowevents, salesorder.triggersalesorderworkflowevent y salesorder.triggersalesorderworkflow.';
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
