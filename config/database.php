<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'driver' => Env::get('DB_DRIVER', 'mysql'),
    'mysql' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::get('DB_PORT', '3306'),
        'database' => Env::get('DB_DATABASE', 'jtlsync'),
        'username' => Env::get('DB_USERNAME', 'root'),
        'password' => Env::get('DB_PASSWORD', ''),
        'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
        'collation' => Env::get('DB_COLLATION', 'utf8mb4_unicode_ci'),
    ],
];
