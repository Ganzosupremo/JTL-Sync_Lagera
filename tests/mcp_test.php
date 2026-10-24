<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Controllers\McpController;
use App\Mcp\McpServer;
use App\Mcp\McpTools;
use App\Models\AppUser;
use App\Models\SyncLog;
use App\Services\OAuthException;
use App\Services\OAuthService;

$db = requireTestDatabase();

function mcpCall(McpServer $server, string $method, array $params = [], int $id = 1): array
{
    $response = $server->handle(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
    assertTrue(is_array($response), 'Una peticion con id siempre debe tener respuesta.');

    return $response;
}

function toolNames(McpServer $server): array
{
    return array_column(mcpCall($server, 'tools/list')['result']['tools'], 'name');
}

// --- Protocolo ---
$writeCalls = [];
$server = new McpServer(new McpTools('test', [
    'sincronizar_ordenes' => function () use (&$writeCalls): array {
        $writeCalls[] = 'sincronizar_ordenes';
        return ['created' => 2];
    },
]));

$init = mcpCall($server, 'initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 't', 'version' => '1']]);
assertSame('2025-03-26', $init['result']['protocolVersion'], 'Debe responder con la version que pidio el cliente si la soporta.');
assertTrue(isset($init['result']['capabilities']['tools']), 'Debe anunciar la capacidad tools.');

$init = mcpCall($server, 'initialize', ['protocolVersion' => '1999-01-01']);
assertSame(McpServer::LATEST_PROTOCOL_VERSION, $init['result']['protocolVersion'], 'Una version desconocida recibe la ultima soportada.');

assertSame(null, $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']), 'Las notificaciones no llevan respuesta.');
assertSame(-32601, mcpCall($server, 'foo/bar')['error']['code'], 'Un metodo desconocido devuelve -32601.');
assertSame(-32600, $server->handle(['id' => 3, 'method' => 'ping'])['error']['code'], 'Sin jsonrpc 2.0 la peticion es invalida.');
assertSame(-32602, mcpCall($server, 'tools/call', ['name' => 'no_existe'])['error']['code'], 'Una herramienta desconocida devuelve -32602.');

$batch = $server->handle([
    ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
    ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
]);
assertSame(1, count($batch), 'Un lote devuelve solo las respuestas de las peticiones con id.');

// --- Herramientas de lectura ---
useSettings(['MCP_ALLOW_WRITES' => 'true']);
$names = toolNames($server);
assertTrue(in_array('ver_logs', $names, true) && in_array('sincronizar_ordenes', $names, true), 'Con acciones permitidas se listan lectura y escritura.');

$logs = new SyncLog();
$logs->create('info', 'order_sync', 'Orden 1001 enviada');
$logs->create('error', 'order_sync', 'Orden 1002 fallo: SKU 50%_off no existe');
$logs->create('error', 'fulfillment', 'Tracking 1003 rechazado');

$result = mcpCall($server, 'tools/call', ['name' => 'ver_logs', 'arguments' => ['nivel' => 'error', 'origen' => 'order_sync']])['result'];
assertSame(false, $result['isError'], 'ver_logs no debe fallar.');
$rows = json_decode($result['content'][0]['text'], true);
assertSame(1, count($rows), 'ver_logs filtra por nivel y origen.');
assertContains('1002', $rows[0]['message'], 'ver_logs devuelve el log filtrado.');

$rows = json_decode(mcpCall($server, 'tools/call', ['name' => 'ver_logs', 'arguments' => ['contiene' => '50%_off']])['result']['content'][0]['text'], true);
assertSame(1, count($rows), 'El filtro de texto trata % y _ como texto literal.');

$rows = json_decode(mcpCall($server, 'tools/call', ['name' => 'ver_logs', 'arguments' => ['limite' => 2]])['result']['content'][0]['text'], true);
assertSame(2, count($rows), 'ver_logs respeta el limite.');

$status = json_decode(mcpCall($server, 'tools/call', ['name' => 'estado_general'])['result']['content'][0]['text'], true);
assertSame(2, $status['errores_hoy'], 'estado_general cuenta los errores de hoy.');

$detail = mcpCall($server, 'tools/call', ['name' => 'detalle_orden', 'arguments' => []])['result'];
assertSame(true, $detail['isError'], 'Un argumento obligatorio que falta vuelve como error de la herramienta.');

// --- Herramientas de escritura ---
$result = mcpCall($server, 'tools/call', ['name' => 'sincronizar_ordenes'])['result'];
assertSame(['sincronizar_ordenes'], $writeCalls, 'La herramienta de escritura se ejecuta.');
assertSame(1, (int) $db->query("SELECT COUNT(*) FROM sync_logs WHERE source = 'mcp' AND message LIKE 'test ejecuto sincronizar_ordenes%'")->fetch_row()[0], 'Cada accion por MCP queda en los logs con quien la hizo.');

useSettings(['MCP_ALLOW_WRITES' => 'false']);
assertTrue(!in_array('sincronizar_ordenes', toolNames($server), true), 'Con acciones desactivadas no se listan las de escritura.');
assertTrue(in_array('ver_logs', toolNames($server), true), 'Con acciones desactivadas siguen las de lectura.');
$result = mcpCall($server, 'tools/call', ['name' => 'sincronizar_ordenes'])['result'];
assertSame(true, $result['isError'], 'Con acciones desactivadas la escritura se rechaza.');
assertSame(1, count($writeCalls), 'Con acciones desactivadas la escritura no se ejecuta.');

// --- OAuth ---
$oauth = new OAuthService();
assertThrows(OAuthException::class, fn () => $oauth->register(['redirect_uris' => ['http://evil.example/cb']]), 'Solo se aceptan redirect_uris https o localhost.');
assertThrows(OAuthException::class, fn () => $oauth->register([]), 'redirect_uris es obligatorio.');

$client = $oauth->register(['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']]);
$clientId = $client['client_id'];
$verifier = str_repeat('a', 50);
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$authorization = [
    'response_type' => 'code',
    'client_id' => $clientId,
    'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
    'state' => 'xyz',
];

$request = $oauth->validateAuthorizationRequest($authorization);
assertSame('xyz', $request['state'], 'La peticion de autorizacion valida conserva el state.');
assertThrows(OAuthException::class, fn () => $oauth->validateAuthorizationRequest(['redirect_uri' => 'https://otro.example/cb'] + $authorization), 'Una redirect_uri distinta a la registrada se rechaza.');
assertThrows(OAuthException::class, fn () => $oauth->validateAuthorizationRequest(['code_challenge_method' => 'plain'] + $authorization), 'PKCE plain se rechaza.');

$adminId = (new AppUser())->create('admin', 'admin@example.com', 'secret-password');
$code = $oauth->issueCode($clientId, $authorization['redirect_uri'], $challenge, $adminId, 'admin');
$exchange = ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $clientId, 'redirect_uri' => $authorization['redirect_uri']];

assertThrows(OAuthException::class, fn () => $oauth->token($exchange + ['code_verifier' => str_repeat('b', 50)]), 'Un code_verifier incorrecto se rechaza.');
$tokens = $oauth->token($exchange + ['code_verifier' => $verifier]);
assertSame('Bearer', $tokens['token_type'], 'El intercambio del codigo entrega un token Bearer.');
assertThrows(OAuthException::class, fn () => $oauth->token($exchange + ['code_verifier' => $verifier]), 'Un codigo no se puede usar dos veces.');

assertSame('admin', $oauth->authenticate($tokens['access_token'])['label'] ?? null, 'El access token autentica al admin.');
assertSame(null, $oauth->authenticate($tokens['refresh_token']), 'El refresh token no sirve como access token.');
assertSame(null, $oauth->authenticate('nope'), 'Un token inventado no autentica.');

$refreshed = $oauth->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId]);
assertTrue($refreshed['access_token'] !== $tokens['access_token'], 'El refresh entrega un access token nuevo.');
assertThrows(OAuthException::class, fn () => $oauth->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId]), 'El refresh token rota y no se puede reutilizar.');

$db->query("UPDATE app_users SET role = 'operator' WHERE id = " . $adminId);
assertSame(null, $oauth->authenticate($refreshed['access_token']), 'Si el usuario deja de ser admin su token deja de servir.');

useSettings(['MCP_TOKEN' => 'static-secret']);
assertSame('token MCP', $oauth->authenticate('static-secret')['label'] ?? null, 'El token fijo MCP_TOKEN autentica.');
assertSame(null, $oauth->authenticate('static-secre'), 'Un token fijo incorrecto no autentica.');

// --- Transporte ---
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123';
assertSame('abc123', callPrivate(new McpController(), 'bearerToken'), 'Lee el token de la cabecera Authorization.');
unset($_SERVER['HTTP_AUTHORIZATION']);
$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'bearer xyz';
assertSame('xyz', callPrivate(new McpController(), 'bearerToken'), 'Lee el token que Apache deja en REDIRECT_HTTP_AUTHORIZATION.');

$_SERVER['HTTP_HOST'] = 'sync.example.com';
$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
assertTrue(!callPrivate(new McpController(), 'originAllowed'), 'Un Origin ajeno se rechaza.');
$_SERVER['HTTP_ORIGIN'] = 'https://sync.example.com';
assertTrue(callPrivate(new McpController(), 'originAllowed'), 'El Origin propio se acepta.');

echo "mcp_test: OK\n";
