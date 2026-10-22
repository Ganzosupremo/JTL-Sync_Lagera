<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Controllers\AutomationController;

requireTestDatabase();

function automationAuthorized(array $server, array $get = []): bool
{
    unset($_SERVER['HTTP_X_AUTOMATION_TOKEN']);
    $_SERVER = array_merge($_SERVER, $server);
    $_GET = $get;
    $_POST = [];

    return (bool) callPrivate(new AutomationController(), 'authorized');
}

useSettings(['AUTOMATION_TOKEN' => '']);
assertTrue(!automationAuthorized(['HTTP_X_AUTOMATION_TOKEN' => '']), 'Sin AUTOMATION_TOKEN el cron HTTP debe quedar deshabilitado.');

useSettings(['AUTOMATION_TOKEN' => 'secret-token', 'AUTOMATION_ALLOW_QUERY_TOKEN' => 'false']);
assertTrue(automationAuthorized(['HTTP_X_AUTOMATION_TOKEN' => 'secret-token']), 'El token correcto en la cabecera debe aceptarse.');
assertTrue(!automationAuthorized(['HTTP_X_AUTOMATION_TOKEN' => 'wrong']), 'Un token incorrecto en la cabecera debe rechazarse.');
assertTrue(!automationAuthorized([], ['token' => 'secret-token']), 'El token en la URL debe rechazarse por defecto.');

useSettings(['AUTOMATION_ALLOW_QUERY_TOKEN' => 'true']);
assertTrue(automationAuthorized([], ['token' => 'secret-token']), 'El token en la URL debe aceptarse solo si se permite explicitamente.');
assertTrue(!automationAuthorized([], ['token' => 'wrong']), 'Un token incorrecto en la URL debe rechazarse.');

echo "automation_token_test: OK\n";
