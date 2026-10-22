<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Models\PackiyoCustomer;
use App\Models\PackiyoCustomerMapping;
use App\Models\ProductSkuAlias;
use App\Services\InactivePackiyoCustomerException;
use App\Services\MappingService;

requireTestDatabase();
useSettings(['PACKIYO_REQUIRE_CUSTOMER_MAPPING' => 'true', 'PACKIYO_ORDER_CHANNEL_NAME' => 'JTL-Wawi']);

(new PackiyoCustomerMapping())->upsert([
    'match_type' => 'email',
    'match_value' => 'Ada@Example.com',
    'packiyo_customer_id' => '42',
    'packiyo_customer_name' => 'Ada Shop',
    'active' => true,
]);
(new ProductSkuAlias())->upsert([
    'packiyo_customer_id' => '42',
    'original_sku' => 'PACKIYO-SKU-1',
    'alias_sku' => 'JTL-SKU-1',
    'active' => true,
]);

$order = [
    'Id' => 'jtl-100',
    'SalesOrderNumber' => 'AU-100',
    'SalesOrderDate' => '2026-08-25T10:30:00+00:00',
    'customer' => ['email' => 'ada@example.com'],
    'ShippingAddress' => [
        'FirstName' => 'Ada',
        'LastName' => 'Lovelace',
        'Street' => 'Main St 1',
        'PostalCode' => '10115',
        'City' => 'Berlin',
        'CountryIso' => 'DE',
    ],
];
$items = [
    ['Id' => 'line-1', 'SKU' => 'JTL-SKU-1', 'Name' => 'Widget', 'Quantity' => 2, 'SalesPriceGross' => 9.5],
    ['Id' => 'line-2', 'Name' => 'Versandkosten', 'Type' => 2, 'SalesPriceGross' => 4.9],
];

$mapper = new MappingService();
$payload = $mapper->toPackiyoPayload($order, $items);
$attributes = $payload['data']['attributes'];

assertSame('orders', $payload['data']['type'], 'El payload debe ser un recurso orders.');
assertSame('42', $payload['data']['relationships']['customer']['data']['id'] ?? null, 'El cliente Packiyo debe resolverse por email sin importar mayusculas.');
assertSame('AU-100', $attributes['number'], 'El numero Packiyo debe usar el numero de la orden JTL.');
assertSame('jtl-100', $attributes['external_id'], 'El external_id debe usar el id JTL cuando no hay numero de marketplace.');
assertSame('JTL-Wawi', $attributes['order_channel_name'], 'El canal debe salir de la configuracion.');
assertSame(1, count($attributes['order_item_data']), 'La linea de envio no debe enviarse como articulo.');
assertSame(4.9, $attributes['shipping'], 'El coste de envio debe salir de la linea de envio.');
assertSame('PACKIYO-SKU-1', $attributes['order_item_data'][0]['sku'], 'El SKU JTL debe traducirse con el alias del cliente.');
assertSame(2.0, $attributes['order_item_data'][0]['quantity'], 'La cantidad debe conservarse.');
assertSame('line-1', $attributes['order_item_data'][0]['external_id'], 'Cada linea debe conservar su id JTL.');
assertSame('Ada Lovelace', $attributes['shipping_contact_information_data']['name'], 'El nombre de envio debe componerse de nombre y apellido.');

$resend = $mapper->toPackiyoPayload($order, $items, 'AU-100-resend-R1', 'AU-100-R1', 'R1');
assertSame('AU-100-resend-R1', $resend['data']['attributes']['external_id'], 'El reenvio debe usar el external_id nuevo.');
assertSame('AU-100-R1', $resend['data']['attributes']['number'], 'El reenvio debe usar el numero nuevo.');
assertSame('line-1-R1', $resend['data']['attributes']['order_item_data'][0]['external_id'], 'El reenvio debe sufijar los ids de linea.');

$unmapped = $order;
$unmapped['customer'] = ['email' => 'nobody@example.com'];
assertThrows(RuntimeException::class, fn () => $mapper->toPackiyoPayload($unmapped, $items), 'Sin mapping de cliente la orden no debe enviarse.');

$provisional = [['Id' => 'line-9', 'SKU' => 'JTL-LINE-9', 'Name' => 'Sin SKU', 'Quantity' => 1]];
$exception = assertThrows(RuntimeException::class, fn () => $mapper->toPackiyoPayload($order, $provisional), 'Un SKU provisional JTL-LINE no debe enviarse a Packiyo.');
assertContains('no valid Packiyo SKU', $exception->getMessage(), 'El error debe explicar que falta el SKU.');

(new PackiyoCustomer())->upsertFromApi(['id' => '42', 'attributes' => ['name' => 'Ada Shop']]);
(new PackiyoCustomer())->setActive('42', false);
assertThrows(InactivePackiyoCustomerException::class, fn () => $mapper->toPackiyoPayload($order, $items), 'Un cliente Packiyo inactivo debe bloquear el envio.');

echo "mapping_service_test: OK\n";
