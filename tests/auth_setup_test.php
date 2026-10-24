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

// Roles: users from before roles existed (and the default) are admins; operators are not.
$_SESSION['auth_user_id'] = (new AppUser())->findByLogin('ada')['id'];
assertTrue($auth->isAdmin(), 'Un usuario admin debe poder cambiar ajustes.');

$operatorId = (new AppUser())->create('bob', 'bob@example.com', 'another long password', AppUser::ROLE_OPERATOR);
$_SESSION['auth_user_id'] = $operatorId;
assertTrue($auth->check(), 'Un operador autenticado debe poder usar el panel.');
assertTrue(!$auth->isAdmin(), 'Un operador no debe poder cambiar ajustes ni usuarios.');
assertThrows(RuntimeException::class, fn () => (new AppUser())->create('eve', 'eve@example.com', 'long enough password', 'root'), 'Un rol desconocido debe rechazarse.');

// Login throttling: after 5 failures the login is locked, even with the right password.
for ($i = 0; $i < App\Models\LoginAttempt::MAX_FAILURES; $i++) {
    assertTrue(!$auth->attempt('bob', 'wrong password ' . $i), 'Un password incorrecto debe rechazarse.');
}
assertTrue($auth->locked('BOB'), 'Tras 5 fallos el usuario debe quedar bloqueado, sin importar mayusculas.');
assertTrue(!$auth->attempt('bob', 'another long password'), 'Un usuario bloqueado no debe poder entrar ni con el password correcto.');
assertTrue(!$auth->locked('ada'), 'El bloqueo de un usuario no debe afectar a otros.');
(new App\Models\LoginAttempt())->clear('bob');
assertTrue($auth->attempt('bob', 'another long password'), 'Tras expirar el bloqueo el password correcto debe aceptarse.');

// A plain-text AUTH_PASSWORD from .env no longer works once real users exist.
useSettings(['AUTH_USERNAME' => 'bootstrap', 'AUTH_PASSWORD' => 'plain-bootstrap-password']);
$auth = new Auth();
assertTrue(!$auth->attempt('bootstrap', 'plain-bootstrap-password'), 'Con usuarios creados el password en claro de .env debe ignorarse.');

$_SESSION = [];
useSettings(['AUTH_ENABLED' => 'false']);
$auth = new Auth();
assertTrue(!$auth->enabled() && $auth->check(), 'Con el login desactivado explicitamente el panel queda abierto.');
assertTrue($auth->isAdmin(), 'Con el login desactivado todos actuan como admin, como antes de los roles.');

echo "auth_setup_test: OK\n";
