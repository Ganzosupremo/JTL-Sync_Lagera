<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\OrderCorrectionService;
use App\Controllers\DashboardController;

$includedResponse = [
    'data' => [
        'type' => 'orders',
        'id' => 'order-1',
        'attributes' => ['number' => 'P-100', 'status' => 'pending'],
        'relationships' => [
            'order_items' => [
                'data' => [
                    ['type' => 'order_items', 'id' => 'line-1'],
                    ['type' => 'order_items', 'id' => 'line-2'],
                ],
            ],
        ],
    ],
    'included' => [
        [
            'type' => 'order_items',
            'id' => 'line-1',
            'attributes' => [
                'external_id' => 'jtl-position-1',
                'sku' => 'JTL-LINE-123',
                'name' => 'Cable USB',
                'quantity' => 2,
                'price' => 4.95,
            ],
            'relationships' => ['product' => ['data' => ['type' => 'products', 'id' => 'old-product']]],
        ],
        [
            'type' => 'order_items',
            'id' => 'line-2',
            'attributes' => [
                'external_id' => 'jtl-position-2',
                'sku' => 'CORRECT-SKU',
                'name' => 'Producto correcto',
                'quantity' => 1,
                'price' => 10,
            ],
        ],
    ],
];

$items = OrderCorrectionService::extractPackiyoLineItems($includedResponse);
assertSameCorrection(2, count($items), 'Debe extraer todas las relaciones order_items desde included.');
assertSameCorrection('JTL-LINE-123', $items[0]['sku'] ?? null, 'Debe conservar el SKU provisional.');
assertSameCorrection('line-1', $items[0]['id'] ?? null, 'Debe conservar el ID de la relacion incluida.');
assertSameCorrection('old-product', $items[0]['product_id'] ?? null, 'Debe normalizar la relacion de producto.');

$direct = OrderCorrectionService::extractPackiyoLineItems([
    'data' => [
        'type' => 'orders',
        'id' => 'order-2',
        'attributes' => [
            'order_item_data' => [
                ['external_id' => 'one', 'sku' => 'JTL-LINE', 'quantity' => 1, 'price' => 0],
                ['external_id' => 'two', 'sku' => 'JTL-LINE-2', 'quantity' => 3, 'price' => 2.5],
            ],
        ],
    ],
]);
assertSameCorrection(2, count($direct), 'Debe detectar multiples lineas provisionales en order_item_data.');
assertTrueCorrection(OrderCorrectionService::isPlaceholderSku('jtl-line-2'), 'La deteccion debe ignorar mayusculas.');
assertTrueCorrection(!OrderCorrectionService::isPlaceholderSku('JTL-LINER'), 'No debe aceptar prefijos parecidos.');

$correction = [[
    'line_index' => 0,
    'original_sku' => 'JTL-LINE-123',
    'proposed_product_id' => 'product-new',
    'proposed_sku' => 'SKU-NEW',
    'proposed_name' => 'Cable USB correcto',
    'quantity' => 2,
    'price' => 4.95,
]];
$payload = OrderCorrectionService::buildReplacementPayload('order-1', $includedResponse, $items, $correction);
$payloadItems = $payload['data']['attributes']['order_item_data'] ?? [];
assertSameCorrection('SKU-NEW', $payloadItems[0]['sku'] ?? null, 'La previsualizacion debe reemplazar solo el SKU afectado.');
assertSameCorrection('product-new', $payloadItems[0]['product_id'] ?? null, 'La previsualizacion debe asignar el producto destino.');
assertSameCorrection(2, $payloadItems[0]['quantity'] ?? null, 'La cantidad debe conservarse.');
assertSameCorrection(4.95, $payloadItems[0]['price'] ?? null, 'El precio debe conservarse.');
assertSameCorrection($items[1], $payloadItems[1] ?? null, 'Las lineas no seleccionadas deben permanecer identicas.');

$verify = new ReflectionMethod(OrderCorrectionService::class, 'verifyReplacement');
$verify->setAccessible(true);
$validAfter = $payloadItems;
$verify->invoke(null, $items, $validAfter, $correction);
$wrongAfter = $validAfter;
$wrongAfter[0]['sku'] = 'OTHER-SKU';
$verificationFailed = false;
try {
    $verify->invoke(null, $items, $wrongAfter, $correction);
} catch (Throwable) {
    $verificationFailed = true;
}
assertTrueCorrection($verificationFailed, 'La verificacion debe rechazar un producto distinto aunque ya no sea provisional.');
$wrongQuantity = $validAfter;
$wrongQuantity[0]['quantity'] = 99;
$verificationFailed = false;
try {
    $verify->invoke(null, $items, $wrongQuantity, $correction);
} catch (Throwable) {
    $verificationFailed = true;
}
assertTrueCorrection($verificationFailed, 'La verificacion debe rechazar cambios de cantidad o precio.');

$alreadyCorrected = new ReflectionMethod(OrderCorrectionService::class, 'alreadyCorrected');
$alreadyCorrected->setAccessible(true);
assertTrueCorrection((bool) $alreadyCorrected->invoke(null, $validAfter, $correction), 'La idempotencia debe reconocer el producto exacto.');
$wrongProduct = $validAfter;
$wrongProduct[0]['product_id'] = 'another-product';
assertTrueCorrection(!(bool) $alreadyCorrected->invoke(null, $wrongProduct, $correction), 'La idempotencia debe rechazar otro producto con el mismo SKU.');
$wrongPrice = $validAfter;
$wrongPrice[0]['price'] = 99;
assertTrueCorrection(!(bool) $alreadyCorrected->invoke(null, $wrongPrice, $correction), 'La idempotencia debe rechazar un precio modificado.');

$hash = OrderCorrectionService::snapshotHash($items);
assertSameCorrection($hash, OrderCorrectionService::snapshotHash($items), 'La huella debe ser estable para detectar concurrencia.');
$changed = $items;
$changed[0]['quantity'] = 9;
assertTrueCorrection($hash !== OrderCorrectionService::snapshotHash($changed), 'La huella debe cambiar cuando Packiyo cambia.');

foreach (['new', 'pending', 'open', 'draft', 'created', 'unfulfilled', 'on_hold'] as $status) {
    assertTrueCorrection(OrderCorrectionService::isEditableStatus($status), 'Debe permitir estado editable: ' . $status);
}
foreach (['picking', 'packing', 'shipped', 'fulfilled', 'cancelled', 'processed', ''] as $status) {
    assertTrueCorrection(!OrderCorrectionService::isEditableStatus($status), 'Debe bloquear estado no editable: ' . $status);
}

$render = new ReflectionMethod(DashboardController::class, 'renderOrderCorrections');
$render->setAccessible(true);
$html = (string) $render->invoke(new DashboardController(), [
    'job' => [
        'id' => 'job-1', 'status' => 'running', 'cursor_page' => 2, 'scanned_orders' => 25,
        'detected_lines' => 1, 'window_start' => '2026-03-01', 'updated_at' => '2026-08-31',
        'last_error' => null,
    ],
    'lines' => [[
        'id' => 1, 'packiyo_order_id' => 'order-1', 'packiyo_order_number' => 'P-100',
        'packiyo_customer_id' => 'customer-46', 'packiyo_status' => 'pending',
        'jtl_order_id' => 'jtl-1', 'jtl_order_number' => 'JTL-1', 'line_index' => 0,
        'original_external_id' => 'position-1', 'original_sku' => 'JTL-LINE-123',
        'original_name' => 'Cable', 'jtl_name' => 'Cable USB', 'jtl_sku' => 'JTL-CABLE',
        'jtl_source' => 'local_copy', 'quantity' => 2, 'price' => 4.95,
        'proposed_product_id' => '', 'suggestions' => [['sku' => 'SKU-NEW']],
        'result' => 'pending', 'error' => null,
    ]],
    'catalogs' => ['customer-46' => [['id' => 'product-new', 'sku' => 'SKU-NEW', 'name' => 'Cable USB correcto']]],
    'write_enabled' => false,
], null);
assertTrueCorrection(str_contains($html, 'Correccion de ordenes'), 'El dashboard debe mostrar la pestaña de correccion.');
assertTrueCorrection(str_contains($html, 'Modo lectura/simulacion'), 'La UI debe avisar que la escritura esta bloqueada.');
assertTrueCorrection(str_contains($html, 'Copia local no actualizada'), 'La copia local JTL debe mostrarse con advertencia.');
assertTrueCorrection(str_contains($html, 'disabled'), 'La ejecucion debe quedar deshabilitada por defecto.');
assertTrueCorrection(str_contains($html, 'Todas las coincidencias'), 'Debe ofrecer asignacion masiva confirmada.');
assertTrueCorrection(str_contains($html, 'Exportar CSV'), 'Debe ofrecer el reporte CSV.');

echo "order_correction_test: OK\n";

function assertSameCorrection(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrueCorrection(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}