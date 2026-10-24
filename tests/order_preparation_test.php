<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\MappingService;
use App\Services\OrderPreparationService;

$service = new OrderPreparationService();
$source = 'Apple USB-C Power Adapter 61W - zonder oplaadkabel';
$catalog = [
    ['id' => '1', 'sku' => 'APPLE-61W', 'name' => 'Apple USB-C Power Adapter 61 W – Schnellladegerät | USB-C, USB PD, Klappstecker, Weiß (Originalverpackt)'],
    ['id' => '2', 'sku' => 'APPLE-67W', 'name' => 'Apple USB-C Power Adapter 67 W Schnellladegerät'],
];
$matches = $service->matchCandidates($source, $catalog);

assertSame('APPLE-61W', $matches[0]['sku'] ?? null, 'El producto de 61 W debe ser el primer candidato.');
assertTrue((float) ($matches[0]['score'] ?? 0) >= 0.70, 'El ejemplo debe superar el umbral de alta confianza.');

$confidence = new ReflectionMethod(OrderPreparationService::class, 'isHighConfidence');
assertTrue($confidence->invoke($service, $source, $matches[0], $matches[1] ?? null), 'El ejemplo debe poder resolverse automaticamente.');

$ambiguous = $service->matchCandidates('Apple USB-C Adapter 61W', [
    ['id' => '1', 'sku' => 'A', 'name' => 'Apple USB-C Adapter 61W'],
    ['id' => '2', 'sku' => 'B', 'name' => 'Apple USB-C Adapter 61W'],
]);
assertTrue(!$confidence->invoke($service, 'Apple USB-C Adapter 61W', $ambiguous[0], $ambiguous[1]), 'Dos candidatos iguales deben requerir confirmacion.');

$errors = $service->validationErrors([
    ['external_id' => 'line-1', 'sku' => 'JTL-LINE-1', 'quantity' => 0, 'price' => -1],
    ['external_id' => 'line-1', 'sku' => 'VALID', 'quantity' => 1, 'price' => 0],
]);
assertSame(4, count($errors), 'Deben detectarse SKU provisional, cantidad, precio e ID duplicado.');
assertSame([], $service->validationErrors([
    ['external_id' => 'line-1', 'sku' => 'VALID', 'quantity' => 2, 'price' => 19.95],
]), 'Una linea valida no debe bloquear la orden.');

$appleCable = $service->applySavedNameMapping([
    'source_name' => 'Apple - Lightning Oplaadkabel - USB-C naar Lightning - 1m - Wit',
    'name' => 'Apple - Lightning Oplaadkabel - USB-C naar Lightning - 1m - Wit',
    'sku' => 'JTL-LINE-584',
    'resolution' => 'unresolved',
], [
    'packiyo_product_id' => 'apple-cable',
    'packiyo_sku' => '0190198531704',
    'packiyo_product_name' => 'Apple USB-C auf Lightning Kabel (1 m), weiß – Originalverpackt',
]);
assertSame('0190198531704', $appleCable['sku'], 'Un mapeo por nombre debe sustituir el SKU temporal de JTL.');
assertSame('Apple USB-C auf Lightning Kabel (1 m), weiß – Originalverpackt', $appleCable['name'], 'El nombre Packiyo confirmado debe viajar con el SKU resuelto.');
assertSame('saved_name', $appleCable['resolution'], 'El articulo debe marcarse como resuelto por un mapeo guardado.');
assertSame([], $service->validationErrors([[
    'external_id' => 'line-apple',
    'sku' => $appleCable['sku'],
    'quantity' => 1,
    'price' => 12.95,
]]), 'Un SKU resuelto por nombre debe ser elegible para enviar.');

$invalidMapping = $service->applySavedNameMapping([
    'source_name' => 'Articulo personalizado',
    'sku' => 'JTL-LINE-585',
    'resolution' => 'unresolved',
], [
    'packiyo_sku' => 'JTL-LINE-999',
    'packiyo_product_name' => 'Articulo invalido',
]);
assertSame('JTL-LINE-585', $invalidMapping['sku'], 'Un mapeo a otro SKU temporal no debe resolver una linea.');
assertSame('unresolved', $invalidMapping['resolution'], 'Un mapeo invalido debe permanecer en revision.');

$lineNormalizer = new ReflectionMethod(MappingService::class, 'normalizeLineItem');
$lineNormalizer->setAccessible(true);
$mapper = new MappingService();
assertThrows(
    static fn () => $lineNormalizer->invoke($mapper, ['name' => 'Articulo sin SKU', 'sku' => 'JTL-LINE-584'], null),
    'El creador de payload debe rechazar SKUs temporales aunque se llame directamente.'
);
assertThrows(
    static fn () => $lineNormalizer->invoke($mapper, ['name' => 'Articulo sin SKU'], null),
    'El creador de payload no debe inventar un SKU JTL-LINE para una linea sin SKU.'
);
assertSame('0190198531704', $lineNormalizer->invoke($mapper, [
    'name' => $appleCable['name'],
    'sku' => $appleCable['sku'],
    'quantity' => 1,
    'price' => 12.95,
    'external_id' => 'line-apple',
], null)['sku'], 'El payload debe conservar un SKU Packiyo resuelto.');

assertSame(4.95, $service->shippingAmount([
    ['Type' => 2, 'Name' => 'Versand', 'SalesPriceGross' => 4.95],
]), 'El costo de envio no debe perderse al separar las lineas de producto.');

echo "order_preparation_test: OK\n";

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Esperado=' . var_export($expected, true) . ' Actual=' . var_export($actual, true));
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}
