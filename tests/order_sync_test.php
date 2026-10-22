<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Clients\JtlClient;
use App\Clients\PackiyoClient;
use App\Models\OrderMapping;
use App\Models\PackiyoCustomerMapping;
use App\Services\OrderSyncService;
use App\Support\HttpException;

requireTestDatabase();
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
        'ShippingAddress' => ['FirstName' => 'Ada', 'LastName' => 'Lovelace', 'City' => 'Berlin', 'CountryIso' => 'DE'],
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

echo "order_sync_test: OK\n";
