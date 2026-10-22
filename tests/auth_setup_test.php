<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Models\AppUser;
use App\Support\Auth;

requireTestDatabase();
$_SESSION = [];

$auth = new Auth();
assertTrue($auth->enabled(), 'El login debe estar activo por defecto.');
assertTrue(!$auth->required(), 'Sin usuarios el login no puede exigirse todavia.');
assertTrue($auth->check(), 'Sin usuarios el panel queda abierto en modo configuracion.');

(new AppUser())->create('ada', 'ada@example.com', 'correct horse battery staple');
$auth = new Auth();
assertTrue($auth->required(), 'Con un usuario activo el login debe exigirse.');
assertTrue(!$auth->check(), 'Sin sesion iniciada el acceso debe denegarse.');
assertTrue(!$auth->attempt('ada', 'wrong password'), 'Un password incorrecto debe rechazarse.');
assertTrue($auth->attempt('ada', 'correct horse battery staple'), 'El password correcto debe aceptarse.');

$_SESSION['authenticated'] = true;
assertTrue($auth->check(), 'Una sesion autenticada debe tener acceso.');

$_SESSION = [];
useSettings(['AUTH_ENABLED' => 'false']);
$auth = new Auth();
assertTrue(!$auth->enabled() && $auth->check(), 'Con el login desactivado explicitamente el panel queda abierto.');

echo "auth_setup_test: OK\n";
