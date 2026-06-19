<?php

declare(strict_types=1);

use App\Support\Config;
use App\Support\Env;

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = BASE_PATH . '/app/' . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

Env::load(BASE_PATH . '/.env');

$timezone = Config::get('app.timezone', 'UTC');
date_default_timezone_set(is_string($timezone) && $timezone !== '' ? $timezone : 'UTC');

foreach ([BASE_PATH . '/storage', BASE_PATH . '/storage/logs'] as $directory) {
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
}
