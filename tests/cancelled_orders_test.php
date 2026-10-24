<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Clients\JtlClient;
use App\Models\OrderMapping;
use App\Services\FulfillmentSyncService;
use App\Services\JtlOrderCancelledDuringFulfillmentException;
use App\Services\OrderSyncService;

requireTestDatabase();
useSettings([
    'JTL_ORDER_ENDPOINT' => '/salesOrders/{id}',
    'JTL_DELIVERY_NOTES_ENDPOINT' => '/deliveryNotes',
    'JTL_AUTO_CREATE_DELIVERY_NOTE' => '1',
    'JTL_DELIVERY_NOTE_WORKFLOW_EVENT_ID' => '7',
]);

// 1. A cancelled JTL order without delivery note stops the fulfillment, without triggering auto-create.
$http = (new FakeHttpClient())
    ->on('GET', '/deliveryNotes', ['items' => []])
    ->on('GET', '/salesOrders/181', ['Id' => 181, 'IsCancelled' => true]);
$service = new FulfillmentSyncService(jtl: new JtlClient($http));
assertThrows(
    JtlOrderCancelledDuringFulfillmentException::class,
    fn () => callPrivate($service, 'deliveryNoteForOrder', '181', 'AU-10180'),
    'Una orden cancelada en JTL no debe buscar ni crear delivery note.'
);
assertSame([], $http->requestsTo('POST', '/salesOrders/181/workflowEvents'), 'No debe dispararse el auto-create en una orden cancelada.');

// 2. A cancelled order marked jtl_cancelled is left out of later fulfillment runs.
$orders = new OrderMapping();
$orders->create(['jtl_order_id' => '181', 'jtl_order_number' => 'AU-10180', 'packiyo_order_id' => 'p1']);
$orders->create(['jtl_order_id' => '182', 'jtl_order_number' => 'AU-10181', 'packiyo_order_id' => 'p2']);
$orders->markFulfillmentChecked('181', 'jtl_cancelled', 'cancelada');
assertSame(['182'], array_column($orders->pendingFulfillment(), 'jtl_order_id'), 'Las ordenes canceladas en JTL no deben revisarse de nuevo.');

// 3. The order sync skips orders that JTL lists as cancelled.
assertSame('cancelled', callPrivate(new OrderSyncService(), 'preSyncSkipReason', ['Id' => 900, 'IsCancelled' => true]), 'Una orden cancelada no debe enviarse a Packiyo.');
