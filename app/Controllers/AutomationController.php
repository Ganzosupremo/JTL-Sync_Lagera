<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AutomationService;
use App\Support\Config;
use App\Support\Database;

final class AutomationController
{
    public function run(): void
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
        echo json_encode((new AutomationService())->run(), JSON_THROW_ON_ERROR);
    }

    private function authorized(): bool
    {
        $expected = (string) Config::get('automation.token', '');

        if ($expected === '') {
            return false;
        }

        $provided = $_SERVER['HTTP_X_AUTOMATION_TOKEN'] ?? $_GET['token'] ?? $_POST['token'] ?? '';

        return is_string($provided) && hash_equals($expected, $provided);
    }
}
