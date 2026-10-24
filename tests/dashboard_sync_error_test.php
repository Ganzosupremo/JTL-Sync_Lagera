<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Views\Dashboard\JtlOrdersView;

$html = (string) callPrivate(
    new JtlOrdersView(),
    'render',
    [[
        'id' => 'jtl-200',
        'number' => 'JTL-200',
        'reference' => 'jtl-200',
        'ordered_at' => '2026-10-22T10:00:00+00:00',
        'contact' => 'Ada Lovelace',
        'source' => 'Temu',
        'source_type' => 'Marketplace',
        'mapped' => true,
        'packiyo_customer_id' => '42',
        'packiyo_customer' => 'Ada Shop #42',
        'sync_state' => 'pending',
        'sync_error' => 'HTTP 500 from POST https://packiyo.example/orders: <boom>',
        'sync_error_at' => '2026-10-22 10:05:00',
    ]],
    null,
    [],
    null,
    null,
    [],
    '',
    ''
);

assertContains('<span class="status failed">error_envio</span>', $html, 'Una orden con error de envio debe mostrarse como fallida.');
assertContains('HTTP 500 from POST', $html, 'El dashboard debe mostrar el error real.');
assertContains('&lt;boom&gt;', $html, 'El error debe escaparse en el HTML.');
assertContains('Ultimo intento 2026-10-22 10:05:00', $html, 'El dashboard debe mostrar cuando fallo por ultima vez.');

echo "dashboard_sync_error_test: OK\n";
