<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Mcp\McpServer;
use App\Mcp\McpTools;
use App\Services\OAuthService;
use App\Support\Setting;
use JsonException;

/**
 * Streamable HTTP transport for MCP at /mcp. Each POST carries one JSON-RPC message (or a batch)
 * and gets a plain JSON answer; there are no server-initiated messages, so GET has no stream.
 */
final class McpController extends Controller
{
    public function handle(): void
    {
        if (!(bool) Setting::get('MCP_ENABLED', true)) {
            $this->json(404, ['error' => 'MCP desactivado.']);
            return;
        }

        if (!$this->isPost()) {
            header('Allow: POST');
            $this->json(405, ['error' => 'Use POST.']);
            return;
        }

        if (!$this->originAllowed()) {
            $this->json(403, ['error' => 'Origin not allowed.']);
            return;
        }

        $caller = (new OAuthService())->authenticate($this->bearerToken());

        if ($caller === null) {
            header('WWW-Authenticate: Bearer resource_metadata="' . OAuthService::baseUrl() . '/.well-known/oauth-protected-resource"');
            $this->json(401, ['error' => 'invalid_token', 'error_description' => 'Falta el token o no es valido.']);
            return;
        }

        try {
            $payload = json_decode((string) file_get_contents('php://input'), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->json(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
            return;
        }

        $response = (new McpServer(new McpTools($caller['label'])))->handle($payload);

        if ($response === null) {
            http_response_code(202);
            return;
        }

        $this->json(200, $response);
    }

    /** Reads the bearer token; Apache on shared hosting often only passes it as REDIRECT_HTTP_AUTHORIZATION. */
    private function bearerToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $header = (string) $value;
                }
            }
        }

        return preg_match('/^Bearer\s+(\S+)$/i', trim((string) $header), $matches) === 1 ? $matches[1] : '';
    }

    /** Browsers send an Origin header; only our own is accepted, which blocks DNS rebinding from web pages. */
    private function originAllowed(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (!is_string($origin) || $origin === '') {
            return true;
        }

        return strcasecmp((string) parse_url($origin, PHP_URL_HOST), (string) parse_url(OAuthService::baseUrl(), PHP_URL_HOST)) === 0;
    }

    private function json(int $status, mixed $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
