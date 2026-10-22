<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Controllers\AuthController;

$auth = new AuthController();

foreach (['/', '/?tab=settings', '/jtl/orders?page=2'] as $target) {
    assertSame($target, callPrivate($auth, 'safeRedirect', $target), 'Una ruta local debe conservarse tras el login.');
}

foreach (['', 'https://evil.example', '//evil.example', 'evil', "/ok\r\nLocation: https://evil.example", "/ok\nX: y"] as $target) {
    assertSame('/', callPrivate($auth, 'safeRedirect', $target), 'Un redirect externo o con saltos de linea debe volver a /: ' . json_encode($target));
}

echo "auth_redirect_test: OK\n";
