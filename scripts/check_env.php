<?php

declare(strict_types=1);

// Checks that the app finds and reads its .env, without printing any value.
// Usage (SSH or cPanel Terminal, from the app folder): php scripts/check_env.php

use App\Support\Config;
use App\Support\Database;
use App\Support\Env;
use App\Support\SecretBox;

require dirname(__DIR__) . '/app/bootstrap.php';

$problems = 0;
$line = static function (bool $ok, string $text) use (&$problems): void {
    $problems += $ok ? 0 : 1;
    echo ($ok ? '[OK]    ' : '[FALLA] ') . $text . PHP_EOL;
};

echo 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . '), usuario del proceso: ' . (function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? posix_geteuid()) : get_current_user()) . PHP_EOL;
echo 'Carpeta de la app (BASE_PATH): ' . BASE_PATH . PHP_EOL;

$status = Env::status() ?? ['path' => '', 'found' => false, 'readable' => false, 'keys' => []];
$line($status['found'], 'Archivo .env buscado en: ' . $status['path']);

if ($status['found']) {
    $perms = substr(sprintf('%o', (int) fileperms($status['path'])), -3);
    $line($status['readable'], 'El .env se puede leer (permisos ' . $perms . ')');

    if ($perms[2] !== '0') {
        echo '        Aviso: otros usuarios del servidor pueden leer el .env; usa chmod 600 .env' . PHP_EOL;
    }

    echo '        Claves leidas (' . count($status['keys']) . '): ' . implode(', ', $status['keys']) . PHP_EOL;
}

$disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
foreach (['getenv', 'putenv'] as $function) {
    if (in_array($function, $disabled, true)) {
        echo '        Aviso: ' . $function . ' esta deshabilitada en este PHP; la app usa $_ENV/$_SERVER y sigue funcionando.' . PHP_EOL;
    }
}

foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'APP_KEY'] as $key) {
    $value = Env::get($key);
    $line(is_scalar($value) && (string) $value !== '', $key . ' tiene valor');
}

try {
    $line(SecretBox::enabled(), 'APP_KEY valida para cifrar secretos');
} catch (Throwable $exception) {
    $line(false, 'APP_KEY: ' . $exception->getMessage());
}

try {
    Database::connection()->query('SELECT 1');
    $line(true, 'Conexion a MySQL (' . Config::get('database.mysql.database', '') . ')');
} catch (Throwable $exception) {
    $line(false, 'Conexion a MySQL: ' . $exception->getMessage());
}

echo PHP_EOL . ($problems === 0 ? 'Todo correcto.' : $problems . ' problema(s).') . PHP_EOL;
exit($problems === 0 ? 0 : 1);
