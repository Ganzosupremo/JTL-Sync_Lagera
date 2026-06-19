<?php

declare(strict_types=1);

use App\Services\OrderSyncService;
use App\Support\Database;

require dirname(__DIR__) . '/app/bootstrap.php';

Database::migrate();

$summary = (new OrderSyncService())->sync();

echo json_encode($summary, JSON_THROW_ON_ERROR) . PHP_EOL;

exit($summary['failed'] > 0 ? 1 : 0);
