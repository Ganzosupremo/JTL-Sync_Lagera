<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Clients\JtlClient;
use App\Services\FulfillmentSyncService;
use App\Support\HttpException;

requireTestDatabase();
useSettings([
    'JTL_DELIVERY_NOTES_ENDPOINT' => '/deliveryNotes',
    'JTL_DELIVERY_NOTE_WORKFLOW_TRIGGER_ENDPOINT' => '/deliveryNotes/{id}/workflow/{workflowEventId}',
    'JTL_MARK_DELIVERY_NOTE_SHIPPED' => '1',
    'JTL_DELIVERY_NOTE_CREATE_RETRY_DELAY_SECONDS' => '0',
]);

$notFound = static fn (string $noteId): HttpException => new HttpException(404, 'POST', 'https://jtl.test/deliveryNotes/' . $noteId . '/workflow/3');
$service = static fn (FakeHttpClient $http): FulfillmentSyncService => new FulfillmentSyncService(jtl: new JtlClient($http));

// 1. A transient 404 is retried and the Shipped event goes through on the second attempt.
$calls = 0;
$http = (new FakeHttpClient())
    ->on('GET', '/deliveryNotes', ['items' => [['Id' => 62]]])
    ->on('POST', '/deliveryNotes/62/workflow/3', function () use (&$calls, $notFound): mixed {
        return ++$calls === 1 ? $notFound('62') : [];
    });
$error = callPrivate($service($http), 'markDeliveryNoteShipped', '62', '1001', 'AU-1', 'AU-1');
assertSame(null, $error, 'Un 404 pasajero debe reintentarse y marcar Shipped.');
assertSame(2, count($http->requestsTo('POST', '/deliveryNotes/62/workflow/3')), 'Debe reintentarse una vez tras el 404.');

// 2. When JTL replaced the delivery note, the retry targets the note's current id.
$http = (new FakeHttpClient())
    ->on('GET', '/deliveryNotes', ['items' => [['Id' => 63]]])
    ->on('POST', '/deliveryNotes/62/workflow/3', $notFound('62'))
    ->on('POST', '/deliveryNotes/63/workflow/3', []);
$error = callPrivate($service($http), 'markDeliveryNoteShipped', '62', '1001', 'AU-1', 'AU-1');
assertSame(null, $error, 'Debe marcarse Shipped el delivery note actual de la orden.');
assertSame(1, count($http->requestsTo('POST', '/deliveryNotes/63/workflow/3')), 'El nuevo delivery note debe recibir el evento Shipped.');

// 3. A persistent 404 stops after a few attempts and returns a message for the next run's retry.
$http = (new FakeHttpClient())
    ->on('GET', '/deliveryNotes', ['items' => [['Id' => 62]]])
    ->on('POST', '/deliveryNotes/62/workflow/3', $notFound('62'));
$error = callPrivate($service($http), 'markDeliveryNoteShipped', '62', '1001', 'AU-1', 'AU-1');
assertTrue(is_string($error) && str_contains($error, 'HTTP 404'), 'Un 404 persistente debe devolver el error para reintentar despues.');
assertSame(3, count($http->requestsTo('POST', '/deliveryNotes/62/workflow/3')), 'Debe limitarse el numero de intentos.');

// 4. Other errors are not retried.
$http = (new FakeHttpClient())
    ->on('POST', '/deliveryNotes/62/workflow/3', new HttpException(400, 'POST', 'https://jtl.test/deliveryNotes/62/workflow/3', '{"ErrorMessage":"x"}'));
$error = callPrivate($service($http), 'markDeliveryNoteShipped', '62', '1001', 'AU-1', 'AU-1');
assertTrue(is_string($error) && str_contains($error, 'HTTP 400'), 'Un 400 debe devolverse sin reintentos.');
assertSame(1, count($http->requests), 'Un 400 no debe reintentarse.');
