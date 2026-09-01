<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\DashboardController;

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
assertContainsText("selected > 10", (string) $controllerSource, 'La interfaz debe respetar el maximo de diez ordenes por lote.');

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
