<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Support\SecretBox;

unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
putenv('APP_KEY');

assertTrue(!SecretBox::enabled(), 'Sin APP_KEY el cifrado debe estar desactivado.');
assertSame('plain-value', SecretBox::encrypt('plain-value'), 'Sin APP_KEY los valores deben guardarse tal cual.');
assertSame('plain-value', SecretBox::decrypt('plain-value'), 'Un valor sin cifrar debe leerse tal cual.');

$_ENV['APP_KEY'] = SecretBox::generateKey();
assertTrue(SecretBox::enabled(), 'Con APP_KEY el cifrado debe activarse.');

$encrypted = SecretBox::encrypt('packiyo-api-key');
assertTrue(SecretBox::isEncrypted($encrypted), 'El valor cifrado debe llevar el prefijo de version.');
assertTrue(!str_contains($encrypted, 'packiyo-api-key'), 'El valor cifrado no debe contener el secreto.');
assertSame('packiyo-api-key', SecretBox::decrypt($encrypted), 'El secreto debe descifrarse con la misma clave.');
assertTrue(SecretBox::encrypt('packiyo-api-key') !== $encrypted, 'Cada cifrado debe usar un nonce distinto.');
assertSame($encrypted, SecretBox::encrypt($encrypted), 'Un valor ya cifrado no debe cifrarse dos veces.');
assertSame('', SecretBox::encrypt(''), 'Un valor vacio debe quedarse vacio.');

$_ENV['APP_KEY'] = SecretBox::generateKey();
assertThrows(RuntimeException::class, fn () => SecretBox::decrypt($encrypted), 'Una clave distinta no debe poder descifrar el secreto.');

unset($_ENV['APP_KEY']);
assertThrows(RuntimeException::class, fn () => SecretBox::decrypt($encrypted), 'Sin APP_KEY un secreto cifrado debe dar un error claro.');

$_ENV['APP_KEY'] = 'too-short';
assertThrows(RuntimeException::class, fn () => SecretBox::enabled(), 'Una APP_KEY mal formada debe rechazarse.');

echo "secret_box_test: OK\n";
