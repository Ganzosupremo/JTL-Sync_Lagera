<?php

declare(strict_types=1);

namespace App\Mcp;

use Throwable;

/**
 * JSON-RPC side of the Model Context Protocol, without any transport: McpController feeds it the
 * decoded request body and sends back whatever it returns. The server is stateless (no session id),
 * which is all a request-per-process PHP host can offer and all the tools here need.
 */
final class McpServer
{
    public const LATEST_PROTOCOL_VERSION = '2025-06-18';

    private const SUPPORTED_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(private readonly McpTools $tools)
    {
    }

    /**
     * Handles one message or a batch. Returns the response(s), or null when every message was a
     * notification (the transport then answers 202 with no body).
     *
     * @return array<int|string, mixed>|null
     */
    public function handle(mixed $payload): ?array
    {
        if (is_array($payload) && array_is_list($payload) && $payload !== []) {
            $responses = array_values(array_filter(
                array_map(fn (mixed $message): ?array => $this->handleMessage($message), $payload),
                static fn (?array $response): bool => $response !== null
            ));

            return $responses === [] ? null : $responses;
        }

        return $this->handleMessage($payload);
    }

    /** @return array<string, mixed>|null */
    private function handleMessage(mixed $message): ?array
    {
        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || !is_string($message['method'] ?? null)) {
            // A response sent by the client (we never send requests) needs no answer either.
            if (is_array($message) && array_key_exists('id', $message) && !array_key_exists('method', $message)) {
                return null;
            }

            return $this->error($message['id'] ?? null, -32600, 'Invalid Request');
        }

        $isNotification = !array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if ($isNotification) {
            return null;
        }

        try {
            return match ($message['method']) {
                'initialize' => $this->result($id, $this->initialize($params)),
                'ping' => $this->result($id, new \stdClass()),
                'tools/list' => $this->result($id, ['tools' => $this->tools->definitions()]),
                'tools/call' => $this->callTool($id, $params),
                'resources/list' => $this->result($id, ['resources' => []]),
                'prompts/list' => $this->result($id, ['prompts' => []]),
                default => $this->error($id, -32601, 'Method not found: ' . $message['method']),
            };
        } catch (Throwable $exception) {
            return $this->error($id, -32603, 'Internal error: ' . $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => is_string($requested) && in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true)
                ? $requested
                : self::LATEST_PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'lagera-jtl-sync',
                'title' => 'Lagera JTL Sync',
                'version' => '1.0.0',
            ],
            'instructions' => 'Panel que sincroniza ordenes de JTL-Wawi con Packiyo y devuelve el tracking a JTL. '
                . 'Empieza con estado_general para ver como va todo; ver_logs y fallos_de_envio explican los errores. '
                . 'Las herramientas de sincronizar hacen cambios reales en Packiyo y JTL.',
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function callTool(mixed $id, array $params): array
    {
        $name = $params['name'] ?? null;

        if (!is_string($name) || !$this->tools->has($name)) {
            return $this->error($id, -32602, 'Unknown tool: ' . (is_string($name) ? $name : ''));
        }

        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        return $this->result($id, $this->tools->call($name, $arguments));
    }

    /** @return array<string, mixed> */
    private function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
