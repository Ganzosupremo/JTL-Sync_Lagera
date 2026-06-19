<?php

declare(strict_types=1);

namespace App\Support;

final class Config
{
    /** @var array<string, array<string, mixed>> */
    private static array $items = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $file = array_shift($parts);

        if ($file === null || $file === '') {
            return $default;
        }

        $value = self::load($file);

        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }

            $value = $value[$part];
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public static function load(string $name): array
    {
        if (array_key_exists($name, self::$items)) {
            return self::$items[$name];
        }

        $path = BASE_PATH . '/config/' . $name . '.php';

        if (!is_file($path)) {
            self::$items[$name] = [];
            return [];
        }

        $config = require $path;
        self::$items[$name] = is_array($config) ? $config : [];

        return self::$items[$name];
    }
}
