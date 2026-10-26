<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Clients\JtlClient;
use App\Clients\PackiyoClient;
use App\Models\OrderDraft;
use App\Models\OrderMapping;
use App\Models\OrderSyncFailure;
use App\Models\PackiyoCustomerMapping;
use App\Services\OrderSyncService;
use App\Services\ShippingAddressRepairService;
use App\Support\Config;
use App\Support\HttpException;
use App\Support\SyncLock;

$db = requireTestDatabase();
useSettings([
    'JTL_ORDERS_ENDPOINT' => '/salesOrders',
    'PACKIYO_ORDERS_ENDPOINT' => '/orders',
    'PACKIYO_FIND_ORDER_ENDPOINT' => '/orders',
    'PACKIYO_REQUIRE_CUSTOMER_MAPPING' => 'true',
]);

(new PackiyoCustomerMapping())->upsert([
    'match_type' => 'email',
    'match_value' => 'ada@example.com',
    'packiyo_customer_id' => '42',
    'packiyo_customer_name' => 'Ada Shop',
    'active' => true,
]);

function jtlOrder(string $id): array
{
    return [
        'Id' => $id,
        'SalesOrderNumber' => 'AU-' . $id,
        'customer' => ['email' => 'ada@example.com'],
        'ShippingAddress' => ['FirstName' => 'Ada', 'LastName' => 'Lovelace', 'Street' => 'Main St 1', 'PostalCode' => '10115', 'City' => 'Berlin', 'CountryIso' => 'DE'],
        'Items' => [['Id' => $id . '-line-1', 'SKU' => 'SKU-1', 'Name' => 'Widget', 'Quantity' => 1, 'SalesPriceGross' => 10]],
    ];
}

/** @param array<int, array<string, mixed>> $orders */
function syncService(array $orders, FakeHttpClient $packiyoHttp): OrderSyncService
{
    $jtlHttp = (new FakeHttpClient())->on('GET', '/salesOrders', ['Data' => $orders]);

    return new OrderSyncService(new JtlClient($jtlHttp), new PackiyoClient($packiyoHttp));
}

$notFound = ['data' => []];
$mappings = new OrderMapping();

// 1. A new order is created in Packiyo and its mapping is stored.
$packiyo = (new FakeHttpClient())
    ->on('GET', '/orders', $notFound)
    ->on('POST', '/orders', ['data' => ['type' => 'orders', 'id' => 'pk-1', 'attributes' => ['number' => 'AU-1']]]);
$summary = syncService([jtlOrder('1')], $packiyo)->sync();

assertSame(1, $summary['created'], 'Una orden nueva debe crearse en Packiyo.');
assertSame(0, $summary['failed'], 'La creacion no debe contar errores.');
assertSame(1, count($packiyo->requestsTo('POST', '/orders')), 'Debe enviarse exactamente un POST a Packiyo.');
assertSame('42', $packiyo->requestsTo('POST', '/orders')[0]['json']['data']['relationships']['customer']['data']['id'] ?? null, 'El POST debe ir al cliente mapeado.');
assertSame('pk-1', $mappings->findByJtlOrderId('1')['packiyo_order_id'] ?? null, 'El mapping JTL->Packiyo debe guardarse.');

// 2. Running again does not send the order a second time.
$packiyo = new FakeHttpClient();
$summary = syncService([jtlOrder('1')], $packiyo)->sync();

assertSame(1, $summary['already_synced'], 'Una orden ya mapeada debe omitirse.');
assertSame([], $packiyo->requests, 'Una orden ya mapeada no debe tocar Packiyo.');

// 3. An order that already exists in Packiyo is linked instead of duplicated.
$packiyo = (new FakeHttpClient())
    ->on('GET', '/orders', ['data' => [['type' => 'orders', 'id' => 'pk-2', 'attributes' => ['number' => 'AU-2']]]], ['filter[external_id]' => '2'])
    ->on('GET', '/orders', $notFound);
$summary = syncService([jtlOrder('2')], $packiyo)->sync();

assertSame(1, $summary['linked'], 'Una orden existente en Packiyo debe vincularse.');
assertSame([], $packiyo->requestsTo('POST', '/orders'), 'Una orden existente no debe crearse de nuevo.');
assertSame('pk-2', $mappings->findByJtlOrderId('2')['packiyo_order_id'] ?? null, 'La orden vinculada debe quedar mapeada.');

// 4. A duplicate error from Packiyo triggers a second lookup and links the order.
$lookups = 0;
$packiyo = (new FakeHttpClient())
    ->on('GET', '/orders', function () use (&$lookups, $notFound): array {
        $lookups++;
        // The lookups before the POST find nothing; the retry after the 422 finds the order.
        return $lookups > 4 ? ['data' => [['type' => 'orders', 'id' => 'pk-3']]] : $notFound;
    })
    ->on('POST', '/orders', new HttpException(422, 'POST', 'https://fake.test/orders', '{"errors":[{"detail":"external_id has already been taken"}]}'));
$summary = syncService([jtlOrder('3')], $packiyo)->sync();

assertSame(1, count($packiyo->requestsTo('POST', '/orders')), 'El POST debe intentarse una vez antes de buscar el duplicado.');
assertSame(1, $summary['linked'], 'Un 422 de duplicado debe terminar vinculando la orden existente.');
assertSame('pk-3', $mappings->findByJtlOrderId('3')['packiyo_order_id'] ?? null, 'La orden recuperada tras el 422 debe quedar mapeada.');

// 5. An unexpected Packiyo error is counted as failed and leaves no mapping.
$packiyo = (new FakeHttpClient())
    ->on('GET', '/orders', $notFound)
    ->on('POST', '/orders', new HttpException(500, 'POST', 'https://fake.test/orders', 'Internal Server Error'));
$summary = syncService([jtlOrder('4')], $packiyo)->sync();

assertSame(1, $summary['failed'], 'Un error de Packiyo debe contarse como fallido.');
assertSame(null, $mappings->findByJtlOrderId('4'), 'Una orden fallida no debe quedar mapeada.');

$failures = new OrderSyncFailure();
$failure = $failures->findByJtlOrderIds(['4'])['4'] ?? null;
assertTrue($failure !== null, 'Una orden fallida debe quedar registrada para mostrarse en el dashboard.');
assertContains('HTTP 500', (string) $failure['last_error'], 'El registro debe guardar el error real.');

syncService([jtlOrder('4')], $packiyo)->sync();
assertSame(2, (int) ($failures->findByJtlOrderIds(['4'])['4']['failure_count'] ?? 0), 'Cada reintento fallido debe sumar al contador.');

// 5b. Once the order syncs, its failure record disappears.
$packiyo = (new FakeHttpClient())
    ->on('GET', '/orders', $notFound)
    ->on('POST', '/orders', ['data' => ['type' => 'orders', 'id' => 'pk-4']]);
$summary = syncService([jtlOrder('4')], $packiyo)->sync();

assertSame(1, $summary['created'], 'La orden debe crearse en el reintento.');
assertSame([], $failures->findByJtlOrderIds(['4']), 'Una orden sincronizada no debe seguir marcada como fallida.');

// 6. Orders without a Packiyo customer mapping are skipped before any API call.
$unmapped = jtlOrder('5');
$unmapped['customer'] = ['email' => 'nobody@example.com'];
$packiyo = new FakeHttpClient();
$summary = syncService([$unmapped], $packiyo)->sync();

assertSame(1, $summary['unmapped'], 'Una orden sin cliente mapeado debe omitirse.');
assertSame([], $packiyo->requests, 'Una orden sin cliente mapeado no debe tocar Packiyo.');

// 7. JTL being unreachable is reported without throwing.
$jtlHttp = (new FakeHttpClient())->on('GET', '/salesOrders', new HttpException(530, 'GET', 'https://fake.test/salesOrders', 'error code: 1033'));
$summary = (new OrderSyncService(new JtlClient($jtlHttp), new PackiyoClient(new FakeHttpClient())))->sync();

assertSame(true, $summary['jtl_unreachable'], 'Un 530/1033 de Cloudflare debe marcar JTL como inaccesible.');
assertSame(1, $summary['failed'], 'JTL inaccesible debe contarse como fallo.');

// 8. While another process holds the sync lock, nothing is read or sent.
$otherProcess = new mysqli(
    (string) Config::get('database.mysql.host'),
    (string) Config::get('database.mysql.username'),
    (string) Config::get('database.mysql.password'),
    (string) Config::get('database.mysql.database'),
    (int) Config::get('database.mysql.port')
);
$otherLock = new SyncLock($otherProcess);
assertTrue($otherLock->acquire('order_sync', 0), 'El otro proceso debe poder tomar el lock.');

$jtlHttp = (new FakeHttpClient())->on('GET', '/salesOrders', ['Data' => [jtlOrder('6')]]);
$packiyo = new FakeHttpClient();
$summary = (new OrderSyncService(new JtlClient($jtlHttp), new PackiyoClient($packiyo)))->sync();

assertSame(true, $summary['locked'], 'Con otra sync en curso la corrida debe omitirse.');
assertSame(0, $summary['failed'], 'Una corrida omitida por el lock no es un fallo.');
assertSame([], $jtlHttp->requests, 'Con el lock tomado no debe leerse JTL.');
assertSame([], $packiyo->requests, 'Con el lock tomado no debe tocarse Packiyo.');

$otherLock->release('order_sync');
$summary = syncService([jtlOrder('6')], (new FakeHttpClient())
    ->on('GET', '/orders', $notFound)
    ->on('POST', '/orders', ['data' => ['type' => 'orders', 'id' => 'pk-6']]))->sync();
assertSame(1, $summary['created'], 'Liberado el lock, la sync debe correr normalmente.');

// 9. A forced resync replaces the mapping in one transaction: if saving fails, the old one survives.
$tooLongId = str_repeat('x', 150); // order_mappings.packiyo_order_id is VARCHAR(100)
$packiyo = (new FakeHttpClient())
    ->on('GET', '/orders', $notFound)
    ->on('POST', '/orders', ['data' => ['type' => 'orders', 'id' => $tooLongId]]);
$jtlHttp = (new FakeHttpClient())->on('GET', '/salesOrders', ['Data' => [jtlOrder('6')]]);
$summary = (new OrderSyncService(new JtlClient($jtlHttp), new PackiyoClient($packiyo)))->syncOne('6', true);

assertSame(1, $summary['failed'], 'Un error al guardar el mapping debe contarse como fallido.');
assertSame('pk-6', $mappings->findByJtlOrderId('6')['packiyo_order_id'] ?? null, 'Si el guardado falla, el mapping anterior debe conservarse.');

$packiyo = (new FakeHttpClient())
    ->on('GET', '/orders', $notFound)
    ->on('POST', '/orders', ['data' => ['type' => 'orders', 'id' => 'pk-6b']]);
$jtlHttp = (new FakeHttpClient())->on('GET', '/salesOrders', ['Data' => [jtlOrder('6')]]);
$summary = (new OrderSyncService(new JtlClient($jtlHttp), new PackiyoClient($packiyo)))->syncOne('6', true);

assertSame(1, $summary['created'], 'El resync forzado debe crear la orden de nuevo.');
assertSame('pk-6b', $mappings->findByJtlOrderId('6')['packiyo_order_id'] ?? null, 'El resync forzado debe reemplazar el mapping.');

// 10. A Kaufland order whose address JTL does not have yet (only the anonymized email) waits.
$withoutAddress = jtlOrder('7');
$withoutAddress['ShippingAddress'] = ['EmailAddress' => 'ca-1@kaufland-marktplatz.de'];
$withoutAddress['BillingAddress'] = ['EmailAddress' => 'ca-1@kaufland-marktplatz.de'];
$packiyo = (new FakeHttpClient())->on('GET', '/orders', $notFound);
$summary = syncService([$withoutAddress], $packiyo)->sync(null, null, true);

assertSame(1, $summary['awaiting_address'], 'Una orden sin direccion debe esperar.');
assertSame(0, $summary['failed'], 'Esperar la direccion no es un fallo.');
assertSame([], $packiyo->requestsTo('POST', '/orders'), 'Una orden sin direccion no debe crearse en Packiyo.');
assertSame(null, $mappings->findByJtlOrderId('7'), 'Una orden sin direccion no debe quedar mapeada.');

// 10b. The next run, once JTL has the address, sends it with that address.
$packiyo = (new FakeHttpClient())
    ->on('GET', '/orders', $notFound)
    ->on('POST', '/orders', ['data' => ['type' => 'orders', 'id' => 'pk-7']]);
$summary = syncService([jtlOrder('7')], $packiyo)->sync(null, null, true);

assertSame(1, $summary['created'], 'Con direccion la orden debe enviarse en la siguiente corrida.');
$contact = $packiyo->requestsTo('POST', '/orders')[0]['json']['data']['attributes']['shipping_contact_information_data'] ?? [];
assertSame('Ada Lovelace', $contact['name'] ?? null, 'El nombre de envio debe venir de JTL.');
assertSame('Main St 1', $contact['address'] ?? null, 'La calle debe venir de JTL.');
assertSame('10115', $contact['zip'] ?? null, 'El codigo postal debe venir de JTL.');

// 11. A draft saved before the address arrived does not hide the address JTL has now.
(new OrderDraft())->save('8', 'AU-8', '42', ['order' => []], [
    'shipping_address' => ['EmailAddress' => 'ca-2@kaufland-marktplatz.de'],
    'billing_address' => ['EmailAddress' => 'ca-2@kaufland-marktplatz.de'],
    'items' => [['external_id' => '8-line-1', 'name' => 'Widget', 'sku' => 'SKU-1', 'quantity' => 1, 'price' => 10]],
    'shipping' => 0,
]);
$packiyo = (new FakeHttpClient())
    ->on('GET', '/orders', $notFound)
    ->on('POST', '/orders', ['data' => ['type' => 'orders', 'id' => 'pk-8']]);
$summary = syncService([jtlOrder('8')], $packiyo)->sync();

assertSame(1, $summary['created'], 'La orden con borrador viejo debe enviarse.');
assertSame('Main St 1', $packiyo->requestsTo('POST', '/orders')[0]['json']['data']['attributes']['shipping_contact_information_data']['address'] ?? null, 'La direccion en vivo de JTL debe ganarle al borrador sin direccion.');

// 12. Orders already sent without an address get it written to Packiyo once JTL has it.
$db->query("UPDATE jtl_order_drafts SET sent_payload_json = JSON_SET(sent_payload_json, '$.data.attributes.shipping_contact_information_data.address', '') WHERE jtl_order_id = '8'");
$jtlHttp = (new FakeHttpClient())->on('GET', '/api/eazybusiness/salesOrders/8', jtlOrder('8'));
$packiyo = (new FakeHttpClient())->on('PATCH', '/orders/pk-8', ['data' => ['type' => 'orders', 'id' => 'pk-8']]);
$repair = new ShippingAddressRepairService(new JtlClient($jtlHttp), new PackiyoClient($packiyo));

$preview = $repair->repair(false);
assertSame(1, $preview['revisadas'], 'La vista previa debe encontrar la orden sin direccion.');
assertSame([], $packiyo->requests, 'La vista previa no debe escribir en Packiyo.');

$result = $repair->repair(true);
assertSame(1, $result['corregidas'], 'La orden debe corregirse.');
$patch = $packiyo->requestsTo('PATCH', '/orders/pk-8')[0]['json']['data'] ?? [];
assertSame('Main St 1', $patch['attributes']['shipping_contact_information_data']['address'] ?? null, 'El PATCH debe llevar la calle.');
assertSame(0, $repair->repair(false)['revisadas'], 'Una orden corregida no debe volver a aparecer.');

echo "order_sync_test: OK\n";
