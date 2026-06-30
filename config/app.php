<?php

declare(strict_types=1);

use App\Support\Setting;

return [
    'name' => 'Lagera JTL Sync',
    'env' => Setting::get('APP_ENV', 'local'),
    'debug' => Setting::get('APP_DEBUG', false),
    'timezone' => Setting::get('APP_TIMEZONE', 'Europe/Berlin'),
    'base_url' => Setting::get('APP_BASE_URL', ''),
];
