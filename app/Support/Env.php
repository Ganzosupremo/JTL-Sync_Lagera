<?php

declare(strict_types=1);

namespace App\Support;

final class Env
{
    /** @var array{path: string, found: bool, readable: bool, keys: array<int, string>}|null */
    private static ?array $status = null;

    public static function load(string $path): void
    {
        self::$status = ['path' => $path, 'found' => is_file($path), 'readable' => false, 'keys' => []];

        if (!self::$status['found']) {
            return;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        self::$status['readable'] = true;

        foreach ($lines as $index => $line) {
            if ($index === 0) {
                // Editors on Windows (and the cPanel file editor) may save a BOM that would end up in the first key.
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
            }

            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim(preg_replace('/^export\s+/', '', trim($key)) ?? $key);
            $value = self::normalize(trim($value));

            if ($key === '') {
                continue;
            }

            // Shared hosts often list putenv/getenv in disable_functions; $_ENV/$_SERVER are enough for get().
            if (function_exists('getenv') && function_exists('putenv') && getenv($key) === false) {
                putenv($key . '=' . (string) $value);
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            self::$status['keys'][] = $key;
        }
    }

    /**
     * Which .env file the app tried to load and which keys it read (never the values).
     *
     * @return array{path: string, found: bool, readable: bool, keys: array<int, string>}|null
     */
    public static function status(): ?array
    {
        return self::$status;
    }

    /** The .env to load: APP_ENV_FILE (from the hosting, e.g. SetEnv in .htaccess) or .env in the app root. */
    public static function defaultPath(string $basePath): string
    {
        $configured = $_SERVER['APP_ENV_FILE'] ?? $_ENV['APP_ENV_FILE'] ?? (function_exists('getenv') ? getenv('APP_ENV_FILE') : false);

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : $basePath . '/.env';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? (function_exists('getenv') ? getenv($key) : false);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $value,
        };
    }

    private static function normalize(string $value): string
    {
        // KEY=value # comment: only for unquoted values, so a "#" inside quotes (or a password) is kept.
        if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
            $value = rtrim((string) preg_replace('/\s+#.*$/', '', $value));
        }

        if (
            strlen($value) >= 2
            && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))
        ) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
