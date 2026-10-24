<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Encrypts secrets stored in MySQL (API keys, tokens) with APP_KEY from the environment,
 * so a database dump alone doesn't expose them. Without APP_KEY values stay as they are,
 * and plain values written before encryption existed are still read as they are.
 */
final class SecretBox
{
    private const PREFIX = 'enc:v1:';

    public static function enabled(): bool
    {
        return self::key() !== null;
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    public static function encrypt(string $plain): string
    {
        $key = self::key();

        if ($key === null || $plain === '' || self::isEncrypted($plain)) {
            return $plain;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    /** @throws RuntimeException when the value is encrypted and APP_KEY is missing or wrong */
    public static function decrypt(string $value): string
    {
        if (!self::isEncrypted($value)) {
            return $value;
        }

        $key = self::key();

        if ($key === null) {
            throw new RuntimeException('Hay secretos cifrados en la base de datos pero APP_KEY no esta configurado.');
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Secreto cifrado ilegible.');
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key
        );

        if ($plain === false) {
            throw new RuntimeException('No se pudo descifrar un secreto: APP_KEY no coincide con la clave usada al guardarlo.');
        }

        return $plain;
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    private static function key(): ?string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            return null;
        }

        $configured = Env::get('APP_KEY');

        if (!is_string($configured) || trim($configured) === '') {
            return null;
        }

        $configured = trim($configured);
        $key = str_starts_with($configured, 'base64:')
            ? base64_decode(substr($configured, 7), true)
            : $configured;

        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('APP_KEY debe ser una clave de 32 bytes (genera una con php scripts/generate_app_key.php).');
        }

        return $key;
    }
}
