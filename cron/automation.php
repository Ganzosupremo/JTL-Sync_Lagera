<?php

declare(strict_types=1);

use App\Services\AutomationService;
use App\Support\Database;

require dirname(__DIR__) . '/app/bootstrap.php';

Database::migrate();

$summary = (new AutomationService())->run();

echo json_encode($summary, JSON_THROW_ON_ERROR) . PHP_EOL;

exit(((int) ($summary['failed'] ?? 0)) > 0 ? 1 : 0);
