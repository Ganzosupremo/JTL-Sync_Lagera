<?php

declare(strict_types=1);

// Shared setup for every *_test.php file. Tests never read the app's .env, so a
// production database configured there can't be touched by accident.

$testDefaults = [
    'APP_ENV_FILE' => dirname(__DIR__) . '/.env.testing',
    'APP_ENV' => 'testing',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'jtlsync_test',
    'DB_USERNAME' => 'root',
    'DB_PASSWORD' => '',
];

foreach ($testDefaults as $key => $value) {
    if (getenv($key) === false) {
        putenv($key . '=' . $value);
    }
}

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require __DIR__ . '/assertions.php';
require __DIR__ . '/FakeHttpClient.php';

const TEST_SKIPPED_EXIT_CODE = 77;

/**
 * Connects to the test database and empties every table, so each test file starts clean.
 * Without MySQL the test is skipped, unless TESTS_REQUIRE_DB=1 (as in CI), where it fails.
 */
function requireTestDatabase(): mysqli
{
    $database = (string) App\Support\Config::get('database.mysql.database', '');

    if (!str_ends_with($database, '_test')) {
        fwrite(STDERR, 'Refusing to run database tests against "' . $database . '": DB_DATABASE must end with _test.' . PHP_EOL);
        exit(1);
    }

    try {
        $db = App\Support\Database::connection();
        App\Support\Database::migrate();
    } catch (Throwable $exception) {
        if (getenv('TESTS_REQUIRE_DB') === '1') {
            throw $exception;
        }

        echo 'SKIP (MySQL not available: ' . $exception->getMessage() . ')' . PHP_EOL;
        exit(TEST_SKIPPED_EXIT_CODE);
    }

    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $tables = $db->query('SHOW TABLES');

    while ($row = $tables->fetch_row()) {
        $db->query('TRUNCATE TABLE `' . str_replace('`', '``', (string) $row[0]) . '`');
    }

    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    resetSettingsCache();

    return $db;
}

/**
 * Stores settings in the test database and drops the cached config so the next
 * client or service built in this process picks them up.
 *
 * @param array<string, string> $settings
 */
function useSettings(array $settings): void
{
    App\Support\Setting::putMany($settings);
    resetSettingsCache();
}

function resetSettingsCache(): void
{
    foreach ([App\Support\Setting::class => 'settings', App\Support\Config::class => 'items'] as $class => $property) {
        $reflection = new ReflectionProperty($class, $property);
        $reflection->setValue(null, $property === 'items' ? [] : null);
    }
}

function callPrivate(object $object, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod($object, $method);

    return $reflection->invoke($object, ...$arguments);
}
