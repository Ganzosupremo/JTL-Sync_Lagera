<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\DashboardController;
use App\Clients\JtlClient;
use App\Services\FulfillmentSyncService;
use App\Support\HttpException;

$controller = new DashboardController();

$renderJtlOrders = new ReflectionMethod(DashboardController::class, 'renderJtlOrders');
$renderJtlOrders->setAccessible(true);
$jtlHtml = (string) $renderJtlOrders->invoke(
    $controller,
    [[
        'id' => 'jtl-100',
        'number' => 'JTL-100',
        'reference' => 'jtl-100',
        'ordered_at' => '2026-08-25T10:30:00+00:00',
        'contact' => 'Ada Lovelace',
        'source' => 'Temu',
        'source_type' => 'Marketplace',
        'mapped' => true,
        'packiyo_customer_id' => '42',
        'packiyo_customer' => 'Ada Shop #42',
        'sync_state' => 'confirmed',
        'packiyo_order_id' => 'packiyo-100',
    ]],
    null,
    [],
    null,
    null,
    [],
    '',
    ''
);

assertContainsText('<div class="scroll-table order-table-scroll">', $jtlHtml, 'La tabla de ordenes JTL debe estar dentro de un contenedor desplazable.');
assertContainsText('data-sort-table="jtl-orders"', $jtlHtml, 'La tabla de ordenes JTL debe habilitar ordenamiento local.');
assertContainsText('data-sort-key="order"', $jtlHtml, 'La orden JTL debe ser ordenable.');
assertContainsText('data-sort-key="date" data-sort-type="date"', $jtlHtml, 'La fecha JTL debe ordenarse como fecha.');
assertContainsText('data-sort-key="customer"', $jtlHtml, 'El cliente de la orden debe ser ordenable.');
assertContainsText('data-sort-key="channel"', $jtlHtml, 'El canal JTL debe ser ordenable.');
assertContainsText('data-sort-key="packiyo-customer"', $jtlHtml, 'El cliente Packiyo debe ser ordenable.');
assertContainsText('data-sort-key="status"', $jtlHtml, 'El estado JTL debe ser ordenable.');
assertContainsText('data-sort-value="2026-08-25T10:30:00+00:00"', $jtlHtml, 'La fecha JTL debe conservar un valor de ordenamiento.');
assertContainsText('<th scope="col" aria-sort="none"><button class="table-sort"', $jtlHtml, 'El estado de ordenamiento debe pertenecer al encabezado de columna.');
assertNotContainsText('data-sort-type="text" aria-sort=', $jtlHtml, 'Los botones de ordenamiento no deben declarar aria-sort.');
assertContainsText('data-client-filter-table="jtl-orders"', $jtlHtml, 'El filtro JTL debe estar conectado al filtrado local.');
assertContainsText('data-jtl-contact="Ada Lovelace"', $jtlHtml, 'Cada orden debe exponer el cliente para el filtro local.');
assertContainsText('data-jtl-mapped-customer="42"', $jtlHtml, 'Cada orden debe exponer el cliente Packiyo para el filtro local.');
assertContainsText('data-clear-jtl-filters', $jtlHtml, 'El filtro local JTL debe poder limpiarse sin recargar.');

$renderFulfillment = new ReflectionMethod(DashboardController::class, 'renderFulfillment');
$renderFulfillment->setAccessible(true);
$fulfillmentHtml = (string) $renderFulfillment->invoke(
    $controller,
    [[
        'jtl_order_id' => 'jtl-100',
        'jtl_order_number' => 'JTL-100',
        'packiyo_order_id' => 'packiyo-100',
        'tracking_number' => 'TRACK-100',
        'tracking_url' => 'https://tracking.example/TRACK-100',
        'carrier' => 'DHL',
        'jtl_delivery_note_id' => 'delivery-100',
        'jtl_package_id' => 'package-100',
        'status' => 'synced',
        'synced_at' => '2026-08-25T11:30:00+00:00',
        'fulfillment_last_checked_at' => '2026-08-25T11:30:00+00:00',
    ], [
        'jtl_order_id' => 'jtl-101',
        'jtl_order_number' => 'JTL-101',
        'packiyo_order_id' => 'packiyo-101',
        'packiyo_order_number' => 'C000TEST101',
        'tracking_number' => null,
        'status' => 'waiting_tracking',
        'synced_at' => '2026-08-25T12:30:00+00:00',
        'fulfillment_last_checked_at' => '2026-08-25T12:30:00+00:00',
    ]],
    ['last_success_at' => '2026-08-25T11:30:00+00:00', 'last_synced_at' => '2026-08-25T11:30:00+00:00', 'last_message' => 'OK'],
    [],
    ''
);

assertContainsText('<div class="scroll-table order-table-scroll">', $fulfillmentHtml, 'La tabla Fulfillment debe estar dentro de un contenedor desplazable.');
assertContainsText('data-sort-table="fulfillment"', $fulfillmentHtml, 'La tabla Fulfillment debe habilitar ordenamiento local.');
assertContainsText('data-sort-key="jtl-order"', $fulfillmentHtml, 'La orden JTL de Fulfillment debe ser ordenable.');
assertContainsText('data-sort-key="packiyo-order"', $fulfillmentHtml, 'La orden Packiyo debe ser ordenable.');
assertContainsText('data-sort-key="tracking"', $fulfillmentHtml, 'El tracking debe ser ordenable.');
assertContainsText('data-sort-key="carrier"', $fulfillmentHtml, 'El carrier debe ser ordenable.');
assertContainsText('data-sort-key="delivery-note"', $fulfillmentHtml, 'El delivery note debe ser ordenable.');
assertContainsText('data-sort-key="status"', $fulfillmentHtml, 'El estado Fulfillment debe ser ordenable.');
assertContainsText('data-sort-key="date" data-sort-type="date"', $fulfillmentHtml, 'La fecha Fulfillment debe ordenarse como fecha.');
assertContainsText('data-sort-value="2026-08-25T11:30:00+00:00"', $fulfillmentHtml, 'La fecha Fulfillment debe conservar un valor de ordenamiento.');
assertContainsText('<th scope="col" aria-sort="none"><button class="table-sort"', $fulfillmentHtml, 'Fulfillment debe declarar el estado de ordenamiento en los encabezados.');
assertContainsText('C000TEST101', $fulfillmentHtml, 'Fulfillment debe mostrar el numero legible de Packiyo.');
assertContainsText('Esperando tracking', $fulfillmentHtml, 'Fulfillment debe mostrar mappings que aun esperan tracking.');
assertContainsText('Revisado 2026-08-25T12:30:00+00:00', $fulfillmentHtml, 'Fulfillment debe mostrar el ultimo checkpoint por orden.');

$controllerSource = file_get_contents(dirname(__DIR__) . '/app/Controllers/DashboardController.php');
assertContainsText("document.querySelectorAll('[data-sort-table]')", (string) $controllerSource, 'El dashboard debe inicializar el ordenamiento local.');
assertContainsText("direction === 1 ? '↑' : '↓'", (string) $controllerSource, 'El dashboard debe mostrar la direccion de ordenamiento activa.');
assertContainsText('.scroll-table > table', (string) $controllerSource, 'En movil, el contenedor desplazable debe seguir siendo el unico contexto de scroll de la tabla.');
assertContainsText("document.querySelectorAll('table')", (string) $controllerSource, 'Todas las tablas deben habilitar busqueda local.');
assertContainsText(".normalize('NFD')", (string) $controllerSource, 'La busqueda debe ignorar acentos.');
assertContainsText("tokens.every((token) => text.includes(token))", (string) $controllerSource, 'La busqueda debe aceptar varias palabras en cualquier columna.');
assertContainsText("jtlFilterForm.addEventListener('submit', (event) =>", (string) $controllerSource, 'El filtro JTL debe interceptar el envio para evitar recargar la pagina.');
assertContainsText("event.preventDefault();", (string) $controllerSource, 'Los filtros locales no deben enviar peticiones al servidor.');
assertContainsText("control.value ?? ''", (string) $controllerSource, 'La busqueda debe incluir IDs y valores guardados en controles ocultos.');
assertContainsText('Sin coincidencias para esta búsqueda.', (string) $controllerSource, 'La busqueda debe informar cuando no encuentra filas.');
assertContainsText('data-correction-selected-form', (string) $controllerSource, 'La correccion debe ofrecer acciones para las ordenes seleccionadas.');
assertContainsText('name="order_ids[]"', (string) $controllerSource, 'Cada orden debe poder seleccionarse para previsualizarla o corregirla.');
assertContainsText('Corregir seleccionadas en Packiyo', (string) $controllerSource, 'La accion remota debe indicar claramente que solo afecta la seleccion.');
assertContainsText("selected === 0", (string) $controllerSource, 'Una seleccion vacia no debe ejecutar todas las ordenes por accidente.');
assertContainsText("selected > maxOrders", (string) $controllerSource, 'La interfaz debe respetar el limite del modo de prueba o lote.');
assertContainsText('Modo prueba: una orden', (string) $controllerSource, 'La primera escritura debe estar limitada a una orden.');
foreach (['Pendiente de revision', 'Esperando tracking', 'Error leyendo Packiyo', 'Enviado a JTL', 'Ya presente en JTL', 'Tracking enviado; Shipped pendiente', 'Error enviando a JTL'] as $statusLabel) {
    assertContainsText($statusLabel, (string) $controllerSource, 'Fulfillment debe traducir todos los estados operativos.');
}

$correctionSource = file_get_contents(dirname(__DIR__) . '/app/Services/OrderCorrectionService.php');
assertContainsText("'shipping_method_name'", (string) $correctionSource, 'La correccion debe incluir un nombre de metodo de envio cuando Packiyo no devuelve un ID.');
assertContainsText("'Generic'", (string) $correctionSource, 'Generic debe ser el metodo de envio fallback aceptado por Packiyo.');
assertContainsText('isFinalCorrectionResult', (string) $correctionSource, 'Las correcciones terminadas deben excluirse de nuevas ejecuciones.');
assertContainsText('Esta orden ya fue corregida', (string) $controllerSource, 'La interfaz debe explicar por que una orden corregida no puede seleccionarse.');
assertContainsText('Enviar siguiente batch a Packiyo (max. 10)', (string) $controllerSource, 'La correccion debe permitir ejecutar el siguiente lote de diez ordenes pendientes.');
assertContainsText('data-correction-batch-form', (string) $controllerSource, 'El lote debe solicitar confirmacion antes de escribir en Packiyo.');
assertContainsText('isCancelledLineItem', (string) $correctionSource, 'Las lineas JTL-LINE canceladas deben excluirse del flujo de correccion.');
assertContainsText('ignored_cancelled', (string) $correctionSource, 'Las lineas canceladas detectadas en trabajos existentes deben quedar marcadas como omitidas.');

$cloudflare = new HttpException(530, 'GET', 'https://jtl.example/deliveryNotes', 'error code: 1033');
$other530 = new HttpException(530, 'GET', 'https://jtl.example/deliveryNotes', 'unrelated upstream error');
$jtl = new JtlClient();
assertSameValue('error code: 1033', $cloudflare->body(), 'HttpException debe conservar el body.');
assertTrueValue($jtl->isReachabilityException($cloudflare), 'Cloudflare 530/1033 debe ser conectividad.');
assertTrueValue(!$jtl->isReachabilityException($other530), 'Un HTTP 530 sin evidencia de 1033 no debe reclasificarse.');
assertContainsText('cloudflared', $jtl->friendlyReachabilityMessage($cloudflare), 'El mensaje 1033 debe indicar revisar cloudflared.');

$service = new FulfillmentSyncService();
$shipmentResources = new ReflectionMethod(FulfillmentSyncService::class, 'shipmentResources');
$shipmentResources->setAccessible(true);
$trackingResources = new ReflectionMethod(FulfillmentSyncService::class, 'trackingResources');
$trackingResources->setAccessible(true);
$included = [
    'shipments:15749' => [
        'type' => 'shipments',
        'id' => '15749',
        'relationships' => ['shipment_trackings' => ['data' => [
            ['type' => 'shipment_trackings', 'id' => '15838'],
            ['type' => 'shipment_trackings', 'id' => '15839'],
        ]]],
    ],
    'shipment_trackings:15838' => ['type' => 'shipment_trackings', 'id' => '15838', 'attributes' => ['tracking_number' => 'LF743910246DE']],
    'shipment_trackings:15839' => ['type' => 'shipment_trackings', 'id' => '15839', 'attributes' => ['tracking_number' => 'SECOND-TRACKING']],
];
$order = ['relationships' => ['shipments' => ['data' => [['type' => 'shipments', 'id' => '15749']]]]];
$shipments = $shipmentResources->invoke($service, $order, $included);
$trackings = $trackingResources->invoke($service, $shipments[0], $included);
assertSameValue(1, count($shipments), 'Debe resolver el shipment incluido.');
assertSameValue(2, count($trackings), 'Debe resolver varios trackings del mismo shipment.');
assertSameValue('LF743910246DE', $trackings[0]['attributes']['tracking_number'] ?? null, 'Debe conservar el tracking de aceptacion.');

$mappingSource = file_get_contents(dirname(__DIR__) . '/app/Models/OrderMapping.php');
$syncSource = file_get_contents(dirname(__DIR__) . '/app/Services/FulfillmentSyncService.php');
$databaseSource = file_get_contents(dirname(__DIR__) . '/app/Support/Database.php');
$packiyoSource = file_get_contents(dirname(__DIR__) . '/app/Clients/PackiyoClient.php');
assertContainsText('(fulfillment_last_checked_at IS NULL) DESC', (string) $mappingSource, 'La cola debe priorizar mappings nunca revisados.');
assertContainsText('CASE WHEN fulfillment_last_checked_at IS NULL THEN synced_at END DESC', (string) $mappingSource, 'Los mappings nuevos deben ir primero.');
assertContainsText('fulfillment_last_checked_at ASC', (string) $mappingSource, 'La cola debe rotar por el chequeo mas antiguo.');
assertContainsText('operationalFulfillmentRows', (string) $mappingSource, 'Debe existir la consulta operativa de Fulfillment.');
assertContainsText('repairPackiyoIdentity', (string) $syncSource, 'El fallback debe reparar el ID Packiyo guardado.');
assertContainsText('findOrderByNumberForFulfillment', (string) $syncSource, 'El fallback debe buscar por numero Packiyo.');
assertContainsText("'lookup_failed'", (string) $syncSource, 'Una orden inexistente debe quedar como lookup_failed.');
assertContainsText("'waiting_tracking'", (string) $syncSource, 'Una orden sin shipments debe quedar esperando tracking.');
assertContainsText('fulfillment_last_checked_at DATETIME NULL', (string) $databaseSource, 'La migracion debe agregar el checkpoint de cola.');
assertContainsText('getOrderForFulfillment', (string) $packiyoSource, 'La lectura de fulfillment debe tener un include dedicado.');

echo "order_tables_test: OK\n";

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

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
