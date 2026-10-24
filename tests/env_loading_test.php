<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Support\Env;

$file = tempnam(sys_get_temp_dir(), 'env');
file_put_contents($file, "\xEF\xBB\xBFENVTEST_FIRST=one\r\n"
    . "# comment\n"
    . "export ENVTEST_EXPORTED=two\n"
    . "ENVTEST_INLINE=three # explained here\n"
    . "ENVTEST_HASH=pa#ss\n"
    . "ENVTEST_QUOTED=\"with # inside\"\n"
    . "ENVTEST_EMPTY=\n");

Env::load($file);

assertSame('one', Env::get('ENVTEST_FIRST'), 'El BOM y el CRLF no deben acabar en la clave ni en el valor.');
assertSame('two', Env::get('ENVTEST_EXPORTED'), 'Las lineas con "export" deben leerse.');
assertSame('three', Env::get('ENVTEST_INLINE'), 'Un comentario tras un valor sin comillas debe quitarse.');
assertSame('pa#ss', Env::get('ENVTEST_HASH'), 'Un # sin espacio delante es parte del valor.');
assertSame('with # inside', Env::get('ENVTEST_QUOTED'), 'Un # entre comillas es parte del valor.');
assertSame('fallback', Env::get('ENVTEST_EMPTY', 'fallback'), 'Un valor vacio usa el valor por defecto.');

$status = Env::status();
assertTrue($status['found'] && $status['readable'], 'El estado debe indicar que el archivo se leyo.');
assertTrue(in_array('ENVTEST_EXPORTED', $status['keys'], true), 'El estado debe listar las claves leidas.');

unlink($file);
Env::load($file);
assertTrue(!Env::status()['found'], 'Un .env que no existe debe quedar reflejado en el estado.');

$_SERVER['APP_ENV_FILE'] = '/home/user/repositories/app/.env';
assertSame('/home/user/repositories/app/.env', Env::defaultPath('/base'), 'APP_ENV_FILE del servidor (SetEnv) debe respetarse.');
unset($_SERVER['APP_ENV_FILE'], $_ENV['APP_ENV_FILE']);
putenv('APP_ENV_FILE');
assertSame('/base/.env', Env::defaultPath('/base'), 'Sin APP_ENV_FILE se usa el .env de la carpeta de la app.');

echo "env_loading_test: OK\n";
