<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Clients\JtlClient;
use App\Support\Arr;
use App\Support\Database;
use App\Support\Logger;
use Throwable;

final class JtlWorkflowController extends Controller
{
    public function salesOrderEvents(): void
    {
        if (!$this->isPost()) {
            $this->methodNotAllowed();
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
            $id = Arr::firstScalar($event, ['Id', 'id', 'ID']);
            $name = Arr::firstScalar($event, ['Name', 'name', 'DisplayName', 'displayName']) ?? '';

            if ($id === null) {
                $parts[] = $this->shortJson($event);
                continue;
            }

            $parts[] = trim($name . ' #' . $id);
        }

        return implode(', ', array_slice($parts, 0, 8));
    }

    private function redirectWithNotice(string $message): void
    {
        $this->startSession();
        $_SESSION['flash_notice'] = $this->compactNotice($message);

        header('Location: ' . $this->url('/') . '?tab=settings', true, 303);
        exit;
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
}
