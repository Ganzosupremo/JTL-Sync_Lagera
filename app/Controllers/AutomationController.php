<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AutomationService;
use App\Support\Config;
use App\Support\Database;

final class AutomationController extends Controller
{
    public function run(): void
    {
        $this->handle(force: true);
    }

    public function tick(): void
    {
        $this->handle(force: false);
    }

    public function manual(): void
    {
        if (!$this->isPost()) {
            $this->methodNotAllowed();
            return;
        }

        Database::migrate();

        $summary = (new AutomationService())->run();
        $message = is_string($summary['message'] ?? null) ? $summary['message'] : 'Automation finished.';

        header('Location: ' . $this->url('/') . '?notice=' . rawurlencode($message), true, 303);
    }

    private function handle(bool $force): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        if (!$this->authorized()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'error' => 'Automation token missing or invalid.',
            ], JSON_THROW_ON_ERROR);
            return;
        }

        Database::migrate();

        header('Content-Type: application/json; charset=UTF-8');
        $service = new AutomationService();
        echo json_encode($force ? $service->run() : $service->runIfDue(), JSON_THROW_ON_ERROR);
    }

    private function authorized(): bool
    {
        $expected = (string) Config::get('automation.token', '');

        if ($expected === '') {
            return false;
        }

        $provided = $_SERVER['HTTP_X_AUTOMATION_TOKEN'] ?? null;

        // A token in the URL ends up in access and proxy logs, so it is only accepted when explicitly allowed.
        if ($provided === null && (bool) Config::get('automation.allow_query_token', false)) {
            $provided = $_GET['token'] ?? $_POST['token'] ?? null;
        }

        return is_string($provided) && hash_equals($expected, $provided);
    }
}
