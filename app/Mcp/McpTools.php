<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Clients\JtlClient;
use App\Clients\PackiyoClient;
use App\Models\AppSyncState;
use App\Models\FulfillmentSync;
use App\Models\OrderMapping;
use App\Models\OrderSyncFailure;
use App\Models\SyncLog;
use App\Services\AutomationService;
use App\Services\FulfillmentSyncService;
use App\Services\OrderDetailService;
use App\Services\OrderSyncService;
use App\Support\Logger;
use App\Support\Setting;
use Closure;
use Throwable;

/**
 * The tools Claude sees through /mcp. Read tools only query the database (and JTL for one order's
 * detail); write tools run the same services as the dashboard buttons. Settings, users, the JTL
 * registration and bulk order corrections are deliberately left to the panel.
 */
final class McpTools
{
    private const MAX_LIMIT = 200;

    /** @var array<string, array{definition: array<string, mixed>, write: bool, handler: Closure}>|null */
    private ?array $tools = null;

    /**
     * @param string $caller Who is calling (user or token label), written to the log for every call.
     * @param array<string, Closure> $overrides Replaces tool handlers, for tests.
     */
    public function __construct(
        private readonly string $caller = 'mcp',
        private readonly array $overrides = [],
        private readonly ?Logger $logger = null
    ) {
    }

    public static function writesAllowed(): bool
    {
        return (bool) Setting::get('MCP_ALLOW_WRITES', true);
    }

    /** @return array<int, array<string, mixed>> */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->tools() as $tool) {
            if (!$tool['write'] || self::writesAllowed()) {
                $definitions[] = $tool['definition'];
            }
        }

        return $definitions;
    }

    public function has(string $name): bool
    {
        return isset($this->tools()[$name]);
    }

    /**
     * Runs a tool and wraps its result as MCP tool content. Failures come back as isError results,
     * not protocol errors, so Claude can read the message and react.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function call(string $name, array $arguments): array
    {
        $tool = $this->tools()[$name];

        if ($tool['write'] && !self::writesAllowed()) {
            return $this->errorResult('Las acciones por MCP estan desactivadas (MCP_ALLOW_WRITES = false en Ajustes).');
        }

        if ($tool['write']) {
            $this->log()->info('mcp', $this->caller . ' ejecuto ' . $name . ' ' . json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        try {
            $handler = $this->overrides[$name] ?? $tool['handler'];
            $data = $handler($arguments);
        } catch (Throwable $exception) {
            $this->log()->warning('mcp', $this->caller . ': ' . $name . ' fallo: ' . $exception->getMessage());

            return $this->errorResult($exception->getMessage());
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return [
            'content' => [['type' => 'text', 'text' => $json === false ? '{}' : $json]],
            'isError' => false,
        ];
    }

    /** @return array<string, array{definition: array<string, mixed>, write: bool, handler: Closure}> */
    private function tools(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $limit = ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'Cuantas filas devolver (por defecto 50).'];
        $customer = ['type' => 'string', 'description' => 'ID de cliente en Packiyo para filtrar (opcional).'];
        $reference = ['type' => 'string', 'description' => 'ID de la orden JTL, su numero de orden o el numero del marketplace.'];

        $this->tools = [];

        $this->read('estado_general', 'Resumen del panel: ordenes enviadas hoy, errores de hoy, ultima orden sincronizada, ultima corrida de la automatizacion, fallos de envio abiertos y fulfillments pendientes.', [], function (): array {
            $mappings = new OrderMapping();
            $states = new AppSyncState();

            return [
                'ahora' => date('Y-m-d H:i:s'),
                'ordenes_enviadas_hoy' => $mappings->countSyncedToday(),
                'errores_hoy' => (new SyncLog())->countErrorsToday(),
                'ultima_orden_sincronizada' => $mappings->lastSyncedAt(),
                'automatizacion' => [
                    'activa' => (bool) Setting::get('AUTOMATION_ENABLED', true),
                    'intervalo_minutos' => (int) Setting::get('AUTOMATION_INTERVAL_MINUTES', 360),
                    'ultima_corrida' => $states->get('automation'),
                ],
                'fallos_de_envio_abiertos' => (new OrderSyncFailure())->count(),
                'fulfillments_pendientes' => $mappings->countPendingFulfillment(),
                'conexiones' => $this->connections(),
            ];
        });

        $this->read('estado_conexiones', 'Si JTL y Packiyo estan configurados (credenciales y registro de la app en JTL).', [], fn (): array => $this->connections());

        $this->read('ver_logs', 'Ultimos logs de la app, del mas nuevo al mas viejo. Se pueden filtrar por nivel, origen y texto.', [
            'nivel' => ['type' => 'string', 'enum' => ['info', 'warning', 'error'], 'description' => 'Solo logs de este nivel.'],
            'origen' => ['type' => 'string', 'description' => 'Solo logs de este origen, por ejemplo order_sync, fulfillment, automation, jtl, packiyo, app, mcp.'],
            'contiene' => ['type' => 'string', 'description' => 'Solo logs cuyo mensaje contiene este texto, por ejemplo un numero de orden.'],
            'limite' => $limit,
        ], fn (array $args): array => (new SyncLog())->search(
            $this->stringArg($args, 'nivel'),
            $this->stringArg($args, 'origen'),
            $this->stringArg($args, 'contiene'),
            $this->limitArg($args)
        ));

        $this->read('ordenes_sincronizadas', 'Ordenes JTL ya enviadas a Packiyo, las mas recientes primero, con su estado de fulfillment.', [
            'cliente_packiyo' => $customer,
            'limite' => $limit,
        ], fn (array $args): array => (new OrderMapping())->recent($this->limitArg($args), $this->stringArg($args, 'cliente_packiyo')));

        $this->read('fallos_de_envio', 'Ordenes JTL que fallaron al enviarse a Packiyo y siguen sin enviarse: ultimo error, cuantas veces fallo y cuando.', [
            'limite' => $limit,
        ], fn (array $args): array => (new OrderSyncFailure())->recent($this->limitArg($args)));

        $this->read('detalle_orden', 'Detalle de una orden JTL tal como la prepara el panel: productos con su SKU de Packiyo, direcciones, cliente Packiyo, errores de validacion y si ya se envio. Consulta JTL en vivo.', [
            'referencia' => $reference,
        ], fn (array $args): array => $this->orderDetail($this->requiredArg($args, 'referencia')), ['referencia']);

        $this->read('fulfillment_pendiente', 'Ordenes enviadas a Packiyo cuyo tracking todavia no se confirmo en JTL.', [
            'cliente_packiyo' => $customer,
            'limite' => $limit,
        ], fn (array $args): array => (new OrderMapping())->pendingFulfillment($this->limitArg($args), $this->stringArg($args, 'cliente_packiyo')));

        $this->read('fulfillment_reciente', 'Ultimos trackings pasados de Packiyo a JTL, con transportista, numero de tracking, estado y error si lo hubo.', [
            'cliente_packiyo' => $customer,
            'limite' => $limit,
        ], fn (array $args): array => (new FulfillmentSync())->recent($this->limitArg($args), $this->stringArg($args, 'cliente_packiyo')));

        $this->write('sincronizar_ordenes', 'Corre la sincronizacion de ordenes JTL -> Packiyo (lo mismo que el boton Sincronizar). Crea ordenes reales en Packiyo.', [], fn (): array => (new OrderSyncService())->sync());

        $this->write('sincronizar_orden', 'Envia una sola orden JTL a Packiyo. Con forzar=true la reenvia aunque ya figure como enviada.', [
            'referencia' => $reference,
            'forzar' => ['type' => 'boolean', 'description' => 'Reenviar aunque ya figure como sincronizada (por defecto false).'],
            'reenviar_archivada' => ['type' => 'boolean', 'description' => 'Reenviar aunque la orden este archivada en Packiyo (por defecto false).'],
        ], fn (array $args): array => (new OrderSyncService())->syncOne(
            $this->requiredArg($args, 'referencia'),
            ($args['forzar'] ?? false) === true,
            ($args['reenviar_archivada'] ?? false) === true
        ), ['referencia'], idempotent: false);

        $this->write('sincronizar_fulfillment', 'Busca en Packiyo el tracking de las ordenes enviadas y lo pasa a JTL (lo mismo que el boton de fulfillment).', [
            'cliente_packiyo' => $customer,
            'limite' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Cuantas ordenes revisar como maximo (por defecto 200).'],
        ], function (array $args): array {
            $max = is_int($args['limite'] ?? null) ? max(1, min(500, $args['limite'])) : 200;

            return (new FulfillmentSyncService())->sync($max, $this->stringArg($args, 'cliente_packiyo'));
        });

        $this->write('ejecutar_automatizacion', 'Corre el ciclo completo del cron ahora: clientes (si esta activado), ordenes y fulfillment.', [], fn (): array => (new AutomationService())->run());

        return $this->tools;
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<int, string> $required
     */
    private function read(string $name, string $description, array $properties, Closure $handler, array $required = []): void
    {
        $this->register($name, $description, $properties, $handler, $required, false, [
            'readOnlyHint' => true,
            'openWorldHint' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<int, string> $required
     */
    private function write(string $name, string $description, array $properties, Closure $handler, array $required = [], bool $idempotent = true): void
    {
        $this->register($name, $description, $properties, $handler, $required, true, [
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => $idempotent,
            'openWorldHint' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<int, string> $required
     * @param array<string, bool> $annotations
     */
    private function register(string $name, string $description, array $properties, Closure $handler, array $required, bool $write, array $annotations): void
    {
        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        $this->tools[$name] = [
            'definition' => [
                'name' => $name,
                'description' => $description,
                'inputSchema' => $schema,
                'annotations' => $annotations,
            ],
            'write' => $write,
            'handler' => $handler,
        ];
    }

    /** @return array<string, mixed> */
    private function connections(): array
    {
        $status = static function (Closure $read): string {
            try {
                return $read();
            } catch (Throwable $exception) {
                return 'error: ' . $exception->getMessage();
            }
        };

        return [
            'jtl' => $status(static fn (): string => (new JtlClient())->status()),
            'packiyo' => $status(static fn (): string => (new PackiyoClient())->status()),
        ];
    }

    /** @return array<string, mixed> */
    private function orderDetail(string $reference): array
    {
        $detail = (new OrderDetailService())->load($reference);
        $failures = (new OrderSyncFailure())->findByJtlOrderIds([(string) $detail['id']]);

        // The catalog and the raw source copies are large and repeat what is already in 'order' and 'data'.
        unset($detail['catalog'], $detail['source'], $detail['source_items']);
        $detail['fallo_de_envio'] = $failures[(string) $detail['id']] ?? null;

        return $detail;
    }

    /** @param array<string, mixed> $args */
    private function stringArg(array $args, string $key): ?string
    {
        $value = $args[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /** @param array<string, mixed> $args */
    private function requiredArg(array $args, string $key): string
    {
        return $this->stringArg($args, $key) ?? throw new \InvalidArgumentException('Falta el argumento "' . $key . '".');
    }

    /** @param array<string, mixed> $args */
    private function limitArg(array $args): int
    {
        $limit = $args['limite'] ?? null;

        return is_int($limit) ? max(1, min(self::MAX_LIMIT, $limit)) : 50;
    }

    /** @return array<string, mixed> */
    private function errorResult(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }

    private function log(): Logger
    {
        return $this->logger ?? new Logger();
    }
}
