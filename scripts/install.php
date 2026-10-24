<?php

declare(strict_types=1);

use App\Support\Config;
use App\Support\Database;

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    // Forced, so it also repairs tables that went missing while the stored schema version stayed current.
    Database::migrate(force: true);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Install failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Database ready using driver: ' . Config::get('database.driver', 'mysql') . PHP_EOL;
