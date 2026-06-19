<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'name' => 'Lagera JTL Sync',
    'env' => Env::get('APP_ENV', 'local'),
    'debug' => Env::get('APP_DEBUG', false),
    'timezone' => Env::get('APP_TIMEZONE', 'Europe/Berlin'),
    'base_url' => Env::get('APP_BASE_URL', ''),
];
