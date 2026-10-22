<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Support\Csrf;

$_SESSION = [];

assertTrue(!Csrf::valid('anything'), 'Sin token en sesion ninguna peticion debe ser valida.');

$token = Csrf::token();
assertSame(64, strlen($token), 'El token debe tener 32 bytes en hexadecimal.');
assertSame($token, Csrf::token(), 'El token debe mantenerse durante la sesion.');
assertTrue(Csrf::valid($token), 'El token de la sesion debe ser valido.');
assertTrue(!Csrf::valid(strrev($token)), 'Un token distinto debe rechazarse.');
assertTrue(!Csrf::valid(null), 'Una peticion sin token debe rechazarse.');
assertTrue(!Csrf::valid(['array']), 'Un token que no es texto debe rechazarse.');

$html = '<form action="/sync" method="post"><button>Go</button></form>'
    . '<form method="get" action="/"><input name="q"></form>'
    . "<FORM class='x' METHOD='POST' action='/logout'></FORM>"
    . '<form data-x="1" action="/a" method=post>';
$injected = Csrf::injectIntoForms($html);
$field = '<input type="hidden" name="_csrf" value="' . $token . '">';

assertSame(3, substr_count($injected, $field), 'Cada formulario POST debe recibir el token, sin importar mayusculas o comillas.');
assertContains('<form action="/sync" method="post">' . $field, $injected, 'El token debe ir justo despues de la etiqueta form.');
assertContains('<form method="get" action="/"><input name="q">', $injected, 'Los formularios GET no deben llevar token.');
assertSame('{"ok":true}', Csrf::injectIntoForms('{"ok":true}'), 'Las respuestas sin formularios no deben cambiar.');

assertTrue(Csrf::isHtmlResponse([]), 'Sin Content-Type la respuesta se trata como HTML.');
assertTrue(Csrf::isHtmlResponse(['Content-Type: text/html; charset=UTF-8']), 'text/html debe tratarse como HTML.');
assertTrue(!Csrf::isHtmlResponse(['Content-Type: text/csv; charset=UTF-8']), 'Las exportaciones CSV no deben modificarse.');
assertTrue(!Csrf::isHtmlResponse(['Content-Type: application/json; charset=UTF-8']), 'Las respuestas JSON no deben modificarse.');

echo "csrf_test: OK\n";
