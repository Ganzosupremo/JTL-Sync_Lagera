<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AppSetting;
use Throwable;

final class Setting
{
    /** @var array<string, string>|null */
    private static ?array $settings = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $settings = self::settings();

        if (array_key_exists($key, $settings)) {
            return self::normalize($settings[$key]);
        }

        return Env::get($key, $default);
    }

    public static function configured(string $key): bool
    {
        $value = self::get($key, '');

        return is_scalar($value) && trim((string) $value) !== '';
    }

    /** @param array<string, string> $updates */
    public static function putMany(array $updates): void
    {
        (new AppSetting())->upsertMany($updates);

        if (self::$settings !== null) {
            foreach ($updates as $key => $value) {
                self::$settings[$key] = $value;
            }
        }
    }

    /** @return array<string, string> */
    private static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        try {
            self::$settings = (new AppSetting())->all();
        } catch (Throwable) {
            self::$settings = [];
        }

        return self::$settings;
    }

    private static function normalize(string $value): mixed
    {
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $value,
        };
    }
}
