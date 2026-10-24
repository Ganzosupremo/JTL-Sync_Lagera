<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Models\AppSetting;
use App\Models\JtlApiCredential;
use App\Support\SecretBox;
use App\Support\Setting;

$db = requireTestDatabase();
$_ENV['APP_KEY'] = SecretBox::generateKey();
resetSettingsCache();

function storedSetting(mysqli $db, string $key): ?string
{
    $statement = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $statement->bind_param('s', $key);
    $statement->execute();
    $row = $statement->get_result()->fetch_row();

    return $row[0] ?? null;
}

// 1. Secret settings are stored encrypted and read back in clear; others stay as they are.
useSettings(['PACKIYO_API_KEY' => 'pk-secret', 'PACKIYO_BASE_URL' => 'https://packiyo.example']);

assertTrue(SecretBox::isEncrypted((string) storedSetting($db, 'PACKIYO_API_KEY')), 'La API key de Packiyo debe guardarse cifrada.');
assertSame('https://packiyo.example', storedSetting($db, 'PACKIYO_BASE_URL'), 'Un ajuste normal no debe cifrarse.');
assertSame('pk-secret', Setting::get('PACKIYO_API_KEY'), 'La API key debe leerse descifrada.');

// 2. A secret saved in clear before APP_KEY existed is encrypted the first time it is read.
(new AppSetting())->upsert('AUTOMATION_TOKEN', 'old-plain-token');
resetSettingsCache();

assertSame('old-plain-token', Setting::get('AUTOMATION_TOKEN'), 'Un secreto antiguo sin cifrar debe seguir leyendose.');
assertTrue(SecretBox::isEncrypted((string) storedSetting($db, 'AUTOMATION_TOKEN')), 'El secreto antiguo debe quedar cifrado tras leerlo.');

// 3. With a different APP_KEY the secret is reported as unreadable instead of breaking the app.
$_ENV['APP_KEY'] = SecretBox::generateKey();
resetSettingsCache();

assertSame('fallback', Setting::get('PACKIYO_API_KEY', 'fallback'), 'Un secreto ilegible debe tratarse como no configurado.');
assertTrue(in_array('PACKIYO_API_KEY', Setting::unreadableSecrets(), true), 'El secreto ilegible debe listarse para avisar en el dashboard.');
assertSame('https://packiyo.example', Setting::get('PACKIYO_BASE_URL'), 'Los ajustes normales deben seguir funcionando.');

// 4. The JTL API token is stored encrypted, and an old plain token is encrypted on first use.
$credentials = new JtlApiCredential();
$credentials->markApproved('request-1', 'jtl-token-1', ['salesorders.read']);
$statement = $db->query("SELECT api_key FROM jtl_api_credentials WHERE registration_request_id = 'request-1'");
assertTrue(SecretBox::isEncrypted((string) $statement->fetch_row()[0]), 'El token de JTL debe guardarse cifrado.');
assertSame('jtl-token-1', $credentials->currentApiKey(), 'El token de JTL debe leerse descifrado.');
assertSame('jtl-token-1', $credentials->latest()['api_key'] ?? null, 'El registro mas reciente debe exponer el token descifrado.');

$db->query("UPDATE jtl_api_credentials SET api_key = 'jtl-plain-token', approved_at = '2030-01-01 00:00:00' WHERE registration_request_id = 'request-1'");
assertSame('jtl-plain-token', $credentials->currentApiKey(), 'Un token antiguo sin cifrar debe seguir funcionando.');
$statement = $db->query("SELECT api_key FROM jtl_api_credentials WHERE registration_request_id = 'request-1'");
assertTrue(SecretBox::isEncrypted((string) $statement->fetch_row()[0]), 'El token antiguo debe quedar cifrado tras usarlo.');

echo "settings_encryption_test: OK\n";
