<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

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
