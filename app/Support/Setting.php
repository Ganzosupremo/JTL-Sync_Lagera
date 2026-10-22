<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AppSetting;
use Throwable;

final class Setting
{
    /** @var array<string, string>|null */
    private static ?array $settings = null;

    /** Settings stored outside the catalog that are secrets too. */
    private const EXTRA_SECRET_KEYS = ['JTL_API_KEY', 'JTL_PASSWORD', 'AUTH_PASSWORD'];

    /** @var array<int, string> Secret settings that exist but could not be decrypted. */
    private static array $unreadableSecrets = [];

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
        $stored = [];

        foreach ($updates as $key => $value) {
            $stored[$key] = self::isSecret($key) ? SecretBox::encrypt($value) : $value;
        }

        (new AppSetting())->upsertMany($stored);

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
            $stored = (new AppSetting())->all();
        } catch (Throwable) {
            return self::$settings = [];
        }

        self::$settings = [];
        self::$unreadableSecrets = [];

        foreach ($stored as $key => $value) {
            try {
                self::$settings[$key] = SecretBox::decrypt($value);
            } catch (Throwable) {
                self::$unreadableSecrets[] = $key;
                continue;
            }

            if (self::isSecret($key) && $value !== '' && !SecretBox::isEncrypted($value)) {
                self::encryptStored($key, $value);
            }
        }

        return self::$settings;
    }

    public static function isSecret(string $key): bool
    {
        static $catalog = null;
        $catalog ??= SettingsCatalog::fieldsByKey();

        return in_array($key, self::EXTRA_SECRET_KEYS, true) || !empty($catalog[$key]['secret']);
    }

    /** @return array<int, string> */
    public static function unreadableSecrets(): array
    {
        self::settings();

        return self::$unreadableSecrets;
    }

    /** Secrets saved before APP_KEY existed are encrypted in place the first time they are read with a key. */
    private static function encryptStored(string $key, string $plain): void
    {
        try {
            if (SecretBox::enabled()) {
                (new AppSetting())->upsert($key, SecretBox::encrypt($plain));
            }
        } catch (Throwable) {
            // Reading the setting must keep working even if the re-encryption can't be written.
        }
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
