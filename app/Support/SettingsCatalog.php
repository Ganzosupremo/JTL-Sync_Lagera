<?php

declare(strict_types=1);

namespace App\Support;

final class SettingsCatalog
{
    /**
     * @return array<int, array{
     *     title: string,
     *     description: string,
     *     fields: array<int, array<string, mixed>>
     * }>
     */
    public static function sections(): array
    {
        return [
            [
                'title' => 'App',
                'description' => 'Configuracion general del dashboard y entorno.',
                'fields' => [
                    self::select('APP_ENV', 'Entorno', 'local', ['local', 'production']),
                    self::boolean('APP_DEBUG', 'Debug', false),
                    self::text('APP_TIMEZONE', 'Zona horaria', 'Europe/Berlin'),
                    self::text('APP_BASE_URL', 'URL base publica', ''),
                ],
            ],
            [
                'title' => 'Autenticacion',
                'description' => 'Protege el dashboard y las acciones manuales con usuario y password.',
                'fields' => [
                    self::boolean('AUTH_ENABLED', 'Requerir login', false),
                    self::text('AUTH_USERNAME', 'Usuario', 'admin'),
                    self::passwordHash('AUTH_PASSWORD_HASH', 'Nuevo password', ''),
                    self::text('AUTH_SESSION_NAME', 'Nombre de sesion', 'jtlsync_session'),
                ],
            ],
            [
                'title' => 'Automatizacion',
                'description' => 'Controla el cron que jala ordenes, envia a Packiyo y devuelve tracking a JTL.',
                'fields' => [
                    self::secret('AUTOMATION_TOKEN', 'Token del cron HTTP', ''),
                    self::boolean('AUTOMATION_SYNC_CUSTOMERS', 'Actualizar clientes en cada corrida', false),
                    self::number('AUTOMATION_FULFILLMENT_LIMIT', 'Limite de fulfillments por corrida', '200'),
                ],
            ],
            [
                'title' => 'JTL-Wawi API',
                'description' => 'Conexion, endpoints y scopes usados para leer ordenes y enviar tracking.',
                'fields' => [
                    self::text('JTL_BASE_URL', 'Base URL', 'https://127.0.0.1:5883'),
                    self::boolean('JTL_SSL_VERIFY', 'Verificar SSL', false),
                    self::text('JTL_API_VERSION', 'API version', '1.0'),
                    self::text('JTL_NEW_ORDERS_QUERY', 'Filtro de ordenes nuevas', 'status=new'),
                    self::number('JTL_TIMEOUT', 'Timeout', '30'),
                    self::text('JTL_ORDERS_ENDPOINT', 'Orders endpoint', '/api/eazybusiness/salesOrders'),
                    self::text('JTL_ORDER_ENDPOINT', 'Order endpoint', '/api/eazybusiness/salesOrders/{id}'),
                    self::text('JTL_ORDER_ITEMS_ENDPOINT', 'Order items endpoint', '/api/eazybusiness/salesOrders/{id}/lineItems'),
                    self::text('JTL_DELIVERY_NOTES_ENDPOINT', 'Delivery notes endpoint', '/api/eazybusiness/deliveryNotes'),
                    self::text('JTL_DELIVERY_NOTE_PACKAGES_ENDPOINT', 'Delivery note packages endpoint', '/api/eazybusiness/deliveryNotes/{id}/packages'),
                    self::textarea('JTL_MANDATORY_API_SCOPES', 'Scopes obligatorios', 'salesorders.read,salesorders.write,items.read,deliverynotes.read,deliverynotes.write'),
                    self::textarea('JTL_OPTIONAL_API_SCOPES', 'Scopes opcionales', ''),
                ],
            ],
            [
                'title' => 'Registro JTL',
                'description' => 'Datos que JTL muestra cuando apruebas la app.',
                'fields' => [
                    self::text('JTL_APP_ID', 'App ID', 'lagera-jtlsync'),
                    self::text('JTL_APP_VERSION', 'Version', '1.0.0'),
                    self::text('JTL_DISPLAY_NAME', 'Nombre visible', 'Lagera JTL Sync'),
                    self::text('JTL_DESCRIPTION', 'Descripcion', 'Synchronization between JTL and Packiyo'),
                    self::text('JTL_PROVIDER_NAME', 'Proveedor', 'Lagera 3PL Germany GmbH'),
                    self::text('JTL_PROVIDER_WEBSITE', 'Website proveedor', 'https://3plgermany.com'),
                    self::secret('JTL_CHALLENGE_CODE', 'Challenge code', 'lagera2026'),
                ],
            ],
            [
                'title' => 'Packiyo API',
                'description' => 'Credenciales, media type y endpoints JSON:API.',
                'fields' => [
                    self::text('PACKIYO_BASE_URL', 'Base URL', ''),
                    self::secret('PACKIYO_API_KEY', 'API key', ''),
                    self::text('PACKIYO_MEDIA_TYPE', 'Media type', 'application/vnd.api+json'),
                    self::text('PACKIYO_ORDER_CHANNEL_NAME', 'Canal de ordenes', 'JTL-Wawi'),
                    self::text('PACKIYO_CUSTOMER_ID', 'Customer ID default', ''),
                    self::boolean('PACKIYO_REQUIRE_CUSTOMER_MAPPING', 'Exigir mapeo de cliente', true),
                    self::number('PACKIYO_TIMEOUT', 'Timeout', '30'),
                    self::text('PACKIYO_ORDERS_ENDPOINT', 'Orders endpoint', '/orders'),
                    self::text('PACKIYO_ORDER_ENDPOINT', 'Order endpoint', '/orders/{id}'),
                    self::text('PACKIYO_FIND_ORDER_ENDPOINT', 'Find order endpoint', '/orders'),
                    self::text('PACKIYO_CUSTOMERS_ENDPOINT', 'Customers endpoint', '/customers'),
                ],
            ],
        ];
    }

    /** @return array<int, string> */
    public static function editableKeys(): array
    {
        $keys = [];

        foreach (self::sections() as $section) {
            foreach ($section['fields'] as $field) {
                $keys[] = (string) $field['key'];
            }
        }

        return $keys;
    }

    /** @return array<int, string> */
    public static function secretKeys(): array
    {
        $keys = [];

        foreach (self::sections() as $section) {
            foreach ($section['fields'] as $field) {
                if (!empty($field['secret'])) {
                    $keys[] = (string) $field['key'];
                }
            }
        }

        return $keys;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function fieldsByKey(): array
    {
        $fields = [];

        foreach (self::sections() as $section) {
            foreach ($section['fields'] as $field) {
                $fields[(string) $field['key']] = $field;
            }
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private static function text(string $key, string $label, string $default): array
    {
        return self::field($key, $label, 'text', $default);
    }

    /** @return array<string, mixed> */
    private static function secret(string $key, string $label, string $default): array
    {
        return self::field($key, $label, 'password', $default, ['secret' => true]);
    }

    /** @return array<string, mixed> */
    private static function passwordHash(string $key, string $label, string $default): array
    {
        return self::field($key, $label, 'password', $default, [
            'secret' => true,
            'hash_password' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private static function number(string $key, string $label, string $default): array
    {
        return self::field($key, $label, 'number', $default);
    }

    /** @return array<string, mixed> */
    private static function textarea(string $key, string $label, string $default): array
    {
        return self::field($key, $label, 'textarea', $default);
    }

    /**
     * @param array<int, string> $options
     * @return array<string, mixed>
     */
    private static function select(string $key, string $label, string $default, array $options): array
    {
        return self::field($key, $label, 'select', $default, ['options' => $options]);
    }

    /** @return array<string, mixed> */
    private static function boolean(string $key, string $label, bool $default): array
    {
        return self::field($key, $label, 'boolean', $default ? 'true' : 'false');
    }

    /** @return array<string, mixed> */
    private static function field(string $key, string $label, string $type, string $default, array $extra = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'default' => $default,
        ], $extra);
    }
}
