<?php

declare(strict_types=1);

namespace App\Support;

/** Lookups in API payloads whose keys come in several spellings (camelCase, PascalCase, snake_case). */
final class Arr
{
    /**
     * The first non-empty scalar under any of $keys, trimmed.
     *
     * @param array<string, mixed> $data
     * @param array<int, string> $keys
     */
    public static function firstScalar(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || !is_scalar($data[$key])) {
                continue;
            }

            $value = trim((string) $data[$key]);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The first array under any of $keys, or [].
     *
     * @param array<string, mixed> $data
     * @param array<int, string> $keys
     * @return array<mixed>
     */
    public static function firstArray(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        return [];
    }
}
