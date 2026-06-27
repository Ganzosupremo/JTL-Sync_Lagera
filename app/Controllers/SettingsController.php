<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AppUser;
use App\Support\Env;
use App\Support\EnvFile;
use App\Support\SettingsCatalog;

final class SettingsController
{
    public function save(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        $updates = [];

        foreach (SettingsCatalog::fieldsByKey() as $key => $field) {
            $value = $this->postedValue($key, $field);

            if ($value === null) {
                continue;
            }

            $updates[$key] = $value;
        }

        if (!$this->authWillBeConfigured($updates)) {
            $this->redirect('/?tab=settings&notice=' . rawurlencode('Configura usuario y password antes de activar autenticacion.'));
        }

        (new EnvFile(BASE_PATH . '/.env'))->update($updates);

        $this->redirect('/?tab=settings&notice=' . rawurlencode('Configuracion guardada.'));
    }

    /** @param array<string, mixed> $field */
    private function postedValue(string $key, array $field): ?string
    {
        $type = (string) ($field['type'] ?? 'text');
        $default = (string) ($field['default'] ?? '');
        $raw = $_POST[$key] ?? null;

        if (!is_scalar($raw)) {
            $raw = '';
        }

        $value = trim((string) $raw);

        if (!empty($field['secret']) && $value === '') {
            return null;
        }

        if (!empty($field['hash_password'])) {
            return password_hash($value, PASSWORD_DEFAULT);
        }

        if ($type === 'boolean') {
            return $value === 'true' ? 'true' : 'false';
        }

        if ($type === 'select') {
            $options = is_array($field['options'] ?? null) ? $field['options'] : [];

            return in_array($value, $options, true) ? $value : $default;
        }

        if ($type === 'number') {
            return ctype_digit($value) ? $value : $default;
        }

        return $value;
    }

    /** @param array<string, string> $updates */
    private function authWillBeConfigured(array $updates): bool
    {
        $authEnabled = $updates['AUTH_ENABLED'] ?? Env::get('AUTH_ENABLED', 'false');

        if ($authEnabled !== 'true') {
            return true;
        }

        $username = trim((string) ($updates['AUTH_USERNAME'] ?? Env::get('AUTH_USERNAME', '')));
        $hash = trim((string) ($updates['AUTH_PASSWORD_HASH'] ?? Env::get('AUTH_PASSWORD_HASH', '')));
        $plainPassword = trim((string) Env::get('AUTH_PASSWORD', ''));

        return (new AppUser())->hasActiveUsers() || ($username !== '' && ($hash !== '' || $plainPassword !== ''));
    }

    private function redirect(string $path): void
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

        header('Location: ' . $base . '/' . ltrim($path, '/'));
        exit;
    }
}
