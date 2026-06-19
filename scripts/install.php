<?php

declare(strict_types=1);

use App\Support\Config;
use App\Support\Database;

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    Database::migrate();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Install failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Database ready using driver: ' . Config::get('database.driver', 'mysql') . PHP_EOL;
