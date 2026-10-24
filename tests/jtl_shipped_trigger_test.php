<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Clients\JtlClient;
use App\Support\HttpException;

requireTestDatabase();
useSettings([
    'JTL_DELIVERY_NOTE_WORKFLOW_TRIGGER_ENDPOINT' => '/api/eazybusiness/v1/deliveryNotes/{id}/workflow/{workflowEventId}',
]);

$versioned = '/api/eazybusiness/v1/deliveryNotes/62/workflow/3';
$unversioned = '/api/eazybusiness/deliveryNotes/62/workflow/3';
$notFound = static fn (string $path): HttpException => new HttpException(404, 'POST', 'https://jtl.test' . $path);

// 1. The /v1 route is tried first without the api-version header, which it rejects.
$http = (new FakeHttpClient())->on('POST', $versioned, []);
(new JtlClient($http))->triggerDeliveryNoteShippedWorkflowEvent('62');
assertSame(1, count($http->requests), 'Si la ruta /v1 responde, no deben probarse otras variantes.');
assertSame('', $http->requests[0]['headers']['api-version'] ?? null, 'La ruta /v1 debe ir sin header api-version.');

// 2. If /v1 answers 404, the unversioned route is tried with api-version 1.1.
$http = (new FakeHttpClient())
    ->on('POST', $versioned, $notFound($versioned))
    ->on('POST', $unversioned, []);
(new JtlClient($http))->triggerDeliveryNoteShippedWorkflowEvent('62');
$calls = $http->requestsTo('POST', $unversioned);
assertSame(1, count($calls), 'Debe probarse la ruta sin /v1.');
assertSame('1.1', $calls[0]['headers']['api-version'] ?? null, 'La ruta sin /v1 debe pedir api-version 1.1.');

// 3. When no variant exists, the error lists every attempt.
$http = (new FakeHttpClient())
    ->on('POST', $versioned, $notFound($versioned))
    ->on('POST', $unversioned, $notFound($unversioned));
$exception = assertThrows(RuntimeException::class, fn () => (new JtlClient($http))->triggerDeliveryNoteShippedWorkflowEvent('62'), 'Sin ruta valida debe fallar.');
assertSame(3, count($http->requests), 'Deben probarse las tres variantes.');
assertContains('api-version 1.1', $exception->getMessage(), 'El error debe nombrar las variantes probadas.');

// 4. Errors other than 404/405 are not hidden behind other variants.
$http = (new FakeHttpClient())->on('POST', $versioned, new HttpException(403, 'POST', 'https://jtl.test' . $versioned));
assertThrows(HttpException::class, fn () => (new JtlClient($http))->triggerDeliveryNoteShippedWorkflowEvent('62'), 'Un 403 debe propagarse.');
assertSame(1, count($http->requests), 'Un 403 no debe probar otras variantes.');
