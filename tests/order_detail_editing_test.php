<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\DashboardController;
use App\Controllers\OrderDraftController;

$renderOrderDetail = new ReflectionMethod(DashboardController::class, 'renderOrderDetail');
$renderOrderDetail->setAccessible(true);

$editableDetail = [
    'id' => 'jtl-123',
    'number' => 'JTL-123',
    'customer_id' => 'customer-46',
    'customer_name' => 'EsSo',
    'readonly' => false,
    'draft' => null,
    'catalog_error' => null,
    'errors' => ['Linea 1: selecciona un SKU valido.'],
    'catalog' => [[
        'id' => 'packiyo-product-1',
        'sku' => '0190198531704',
        'name' => 'Apple USB-C auf Lightning Kabel',
    ]],
    'data' => [
        'shipping_address' => [],
        'billing_address' => [],
        'items' => [[
            'external_id' => 'line-1',
            'source_name' => 'Apple Lightning cable',
            'name' => 'Apple Lightning cable',
            'sku' => 'JTL-LINE-584',
            'packiyo_product_id' => '',
            'resolution' => 'unresolved',
            'quantity' => 1,
            'price' => 12.95,
            'suggestions' => [],
        ]],
    ],
];

$controller = new DashboardController();
$editableHtml = (string) $renderOrderDetail->invoke($controller, $editableDetail, null);

assertContainsText('Esta orden se puede editar.', $editableHtml, 'Una orden pendiente debe explicar que se puede editar.');
assertContainsText('class="product-picker"', $editableHtml, 'Una orden pendiente debe mostrar el selector Packiyo.');
assertContainsText('data-id="packiyo-product-1"', $editableHtml, 'El selector debe conservar el ID del producto Packiyo.');
assertContainsText('name="items[0][packiyo_product_id]"', $editableHtml, 'El formulario debe enviar el ID del producto seleccionado.');
assertContainsText('class="line-resolution"', $editableHtml, 'El formulario debe enviar el estado de resolucion de la linea.');
assertContainsText("line.querySelector('.line-resolution').value = 'manual';", $editableHtml, 'Seleccionar un producto debe marcar la linea como correccion manual.');
assertContainsText('Guardar borrador', $editableHtml, 'Una orden pendiente debe mostrar el control para guardar.');
assertNotContainsText('class="product-picker" disabled', $editableHtml, 'El selector de una orden pendiente no debe estar deshabilitado.');

$parseItems = new ReflectionMethod(OrderDraftController::class, 'items');
$parseItems->setAccessible(true);
$parsedItems = $parseItems->invoke(new OrderDraftController(), [[
    'external_id' => 'line-1',
    'source_name' => 'Apple Lightning cable',
    'name' => 'Apple USB-C auf Lightning Kabel',
    'sku' => '0190198531704',
    'packiyo_product_id' => 'packiyo-product-1',
    'resolution' => 'manual',
    'quantity' => '1',
    'price' => '12.95',
    'remember' => '1',
]]);
assertSameValue('Apple USB-C auf Lightning Kabel', $parsedItems[0]['name'] ?? null, 'El nombre del producto seleccionado debe conservarse al guardar.');
assertSameValue('0190198531704', $parsedItems[0]['sku'] ?? null, 'El SKU del producto seleccionado debe conservarse al guardar.');
assertSameValue('packiyo-product-1', $parsedItems[0]['packiyo_product_id'] ?? null, 'El ID Packiyo del producto seleccionado debe conservarse al guardar.');
assertSameValue('manual', $parsedItems[0]['resolution'] ?? null, 'Una seleccion manual debe conservar su resolucion al guardar.');

$sentDetail = $editableDetail;
$sentDetail['readonly'] = true;
$sentHtml = (string) $renderOrderDetail->invoke($controller, $sentDetail, null);

assertContainsText('Esta orden ya fue enviada a Packiyo, pero puedes editar su borrador local', $sentHtml, 'Una orden enviada debe explicar que la edicion local no cambia Packiyo.');
assertNotContainsText('class="product-picker" disabled', $sentHtml, 'El selector de una orden enviada debe seguir habilitado para remapeo local.');
assertContainsText('Guardar borrador', $sentHtml, 'Una orden enviada debe mostrar controles de guardado local.');

echo "order_detail_editing_test: OK\n";

function assertContainsText(string $expected, string $actual, string $message): void
{
    if (!str_contains($actual, $expected)) {
        throw new RuntimeException($message . ' Falta=' . $expected);
    }
}

function assertNotContainsText(string $unexpected, string $actual, string $message): void
{
    if (str_contains($actual, $unexpected)) {
        throw new RuntimeException($message . ' Encontrado=' . $unexpected);
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Esperado=' . var_export($expected, true) . ' Actual=' . var_export($actual, true));
    }
}