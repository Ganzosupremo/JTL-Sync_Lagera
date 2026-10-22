<?php

declare(strict_types=1);

// Prints a new APP_KEY for encrypting secrets in MySQL. Put it in .env as APP_KEY=...
// and keep a copy somewhere safe: without it, encrypted settings must be entered again.

require dirname(__DIR__) . '/app/bootstrap.php';

echo App\Support\SecretBox::generateKey() . PHP_EOL;
