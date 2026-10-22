<?php

declare(strict_types=1);

namespace App\Support;

final class Csrf
{
    public const FIELD = '_csrf';

    private const SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        $token = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    public static function valid(mixed $provided): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($expected)
            && $expected !== ''
            && is_string($provided)
            && hash_equals($expected, $provided);
    }

    /** Token sent with the current request, from the form field or the X-CSRF-Token header. */
    public static function fromRequest(): mixed
    {
        return $_POST[self::FIELD] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    }

    /** Adds the hidden token field to every POST form in an HTML response. */
    public static function injectIntoForms(string $html): string
    {
        if (stripos($html, '<form') === false) {
            return $html;
        }

        $field = '<input type="hidden" name="' . self::FIELD . '" value="' . self::token() . '">';

        return (string) preg_replace(
            '/<form\b(?=[^>]*\bmethod\s*=\s*["\']?post\b)[^>]*>/i',
            '$0' . $field,
            $html
        );
    }

    /** @param array<int, string> $headers */
    public static function isHtmlResponse(array $headers): bool
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                return stripos($header, 'text/html') !== false;
            }
        }

        return true;
    }
}
