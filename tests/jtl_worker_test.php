<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Clients\JtlClient;
use App\Support\HttpException;

requireTestDatabase();
useSettings([
    'JTL_BASE_URL' => 'https://jtl.test',
    'JTL_WORKERS_ENDPOINT' => '/api/eazybusiness/workers',
    'JTL_WORKER_STATUS_ENDPOINT' => '/api/eazybusiness/v1/workers/status',
    'JTL_WORKER_SYNC_ID' => 'abc',
]);

$notFound = static fn (string $path): HttpException => new HttpException(404, 'GET', 'https://jtl.test' . $path, 'Not Found');

// 1. A configured legacy path that 404s falls back to the canonical /v1 path.
$http = (new FakeHttpClient())
    ->on('GET', '/api/eazybusiness/workers', $notFound('/api/eazybusiness/workers'))
    ->on('GET', '/api/eazybusiness/v1/workers', [['id' => 's1', 'name' => 'BOL']]);
$syncs = (new JtlClient($http))->getWorkerSyncs();

assertSame([['id' => 's1', 'name' => 'BOL']], $syncs, 'Debe leerse la ruta canonica si la configurada no existe.');
assertSame(3, count($http->requests), 'Dos intentos en la ruta configurada y uno en la canonica.');

// 2. When everything 404s, the reads stop after four requests and report each attempt.
$http = (new FakeHttpClient())
    ->on('GET', '/api/eazybusiness/workers', $notFound('/api/eazybusiness/workers'))
    ->on('GET', '/api/eazybusiness/v1/workers', $notFound('/api/eazybusiness/v1/workers'));
$error = assertThrows(RuntimeException::class, fn () => (new JtlClient($http))->getWorkerSyncs(), 'Sin rutas disponibles debe fallar.');

assertSame(4, count($http->requests), 'Como maximo deben hacerse cuatro peticiones al Worker.');
assertContains('Tried:', $error->getMessage(), 'El error debe listar los intentos.');

// 3. Errors other than 404/405 or an api-version complaint stop at once.
$http = (new FakeHttpClient())
    ->on('GET', '/api/eazybusiness/workers', new HttpException(401, 'GET', 'https://jtl.test/api/eazybusiness/workers', 'Unauthorized'));
assertThrows(HttpException::class, fn () => (new JtlClient($http))->getWorkerSyncs(), 'Un 401 debe lanzarse tal cual.');
assertSame(1, count($http->requests), 'Un 401 no debe reintentarse en otras rutas.');

// 4. Status uses the configured sync id once, on the canonical path.
$http = (new FakeHttpClient())->on('GET', '/api/eazybusiness/v1/workers/status', ['running' => false], ['syncId' => 'abc']);
assertSame(['running' => false], (new JtlClient($http))->getWorkerStatus(), 'El estado del Worker debe leerse.');
assertSame(1, count($http->requests), 'El estado debe leerse con una sola peticion.');

echo "jtl_worker_test: OK\n";
