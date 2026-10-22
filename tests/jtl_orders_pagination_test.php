<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Clients\JtlClient;
use App\Models\PackiyoCustomerMapping;
use App\Services\OrderSyncService;
use App\Clients\PackiyoClient;

requireTestDatabase();
useSettings([
    'JTL_ORDERS_ENDPOINT' => '/salesOrders',
    'JTL_NEW_ORDERS_QUERY' => 'status=new',
    'JTL_ORDERS_MAX_PAGES' => '3',
    'JTL_ORDERS_MAX_AGE_DAYS' => '30',
    'PACKIYO_ORDERS_ENDPOINT' => '/orders',
    'PACKIYO_FIND_ORDER_ENDPOINT' => '/orders',
]);

/** @param array<int, string> $ids */
function page(array $ids, array $paging): array
{
    return array_merge(['items' => array_map(static fn (string $id): array => ['id' => $id], $ids)], $paging);
}

// 1. camelCase paging: follows nextPageNumber until hasNextPage is false.
$http = (new FakeHttpClient())
    ->on('GET', '/salesOrders', page(['1', '2'], ['pageNumber' => 1, 'hasNextPage' => true, 'nextPageNumber' => 2]), ['status' => 'new', 'pageSize' => 100, 'pageNumber' => 1])
    ->on('GET', '/salesOrders', page(['3'], ['pageNumber' => 2, 'hasNextPage' => false]), ['status' => 'new', 'pageSize' => 100, 'pageNumber' => 2]);
$orders = (new JtlClient($http))->getOrders();

assertSame(['1', '2', '3'], array_column($orders, 'id'), 'Deben leerse todas las paginas de ordenes JTL.');
assertSame(2, count($http->requests), 'Debe pedirse una vez cada pagina.');
assertSame('new', $http->requests[0]['query']['status'] ?? null, 'El filtro configurado debe mantenerse en cada pagina.');

// 2. PascalCase paging with TotalPages only.
$http = (new FakeHttpClient())
    ->on('GET', '/salesOrders', fn (array $request): array => page(
        ['p' . $request['query']['pageNumber']],
        ['PageNumber' => $request['query']['pageNumber'], 'TotalPages' => 2]
    ));
assertSame(['p1', 'p2'], array_column((new JtlClient($http))->getOrders(), 'id'), 'TotalPages tambien debe respetarse.');

// 3. A response without paging fields is the only page (legacy behaviour).
$http = (new FakeHttpClient())->on('GET', '/salesOrders', ['Data' => [['id' => 'a'], ['id' => 'b']]]);
assertSame(['a', 'b'], array_column((new JtlClient($http))->getOrders(), 'id'), 'Sin datos de paginacion debe leerse una sola pagina.');
assertSame(1, count($http->requests), 'Sin datos de paginacion no debe pedirse otra pagina.');

// 4. The page cap stops an endless or huge listing.
$http = (new FakeHttpClient())
    ->on('GET', '/salesOrders', fn (array $request): array => page(['x' . $request['query']['pageNumber']], ['hasNextPage' => true]));
$orders = (new JtlClient($http))->getOrders();
assertSame(3, count($http->requests), 'La lectura debe pararse en JTL_ORDERS_MAX_PAGES.');
assertSame(3, count($orders), 'Las ordenes leidas hasta el limite deben devolverse.');

// 5. The batch sync ignores orders older than JTL_ORDERS_MAX_AGE_DAYS.
(new PackiyoCustomerMapping())->upsert(['match_type' => 'default', 'packiyo_customer_id' => '42', 'active' => true]);
$order = static fn (string $id, string $date): array => [
    'Id' => $id,
    'SalesOrderDate' => $date,
    'Items' => [['Id' => $id . '-1', 'SKU' => 'SKU-1', 'Name' => 'Widget', 'Quantity' => 1, 'SalesPriceGross' => 10]],
];
$jtlHttp = (new FakeHttpClient())->on('GET', '/salesOrders', ['Data' => [
    $order('old', date('c', strtotime('-45 days'))),
    $order('new', date('c', strtotime('-2 days'))),
]]);
$packiyo = (new FakeHttpClient())
    ->on('GET', '/orders', ['data' => []])
    ->on('POST', '/orders', ['data' => ['type' => 'orders', 'id' => 'pk-new']]);
$summary = (new OrderSyncService(new JtlClient($jtlHttp), new PackiyoClient($packiyo)))->sync();

assertSame(1, $summary['too_old'], 'Una orden de hace 45 dias debe ignorarse en el sync por lotes.');
assertSame(1, $summary['created'], 'La orden reciente debe enviarse.');
assertSame(1, count($packiyo->requestsTo('POST', '/orders')), 'Solo la orden reciente debe llegar a Packiyo.');

echo "jtl_orders_pagination_test: OK\n";
