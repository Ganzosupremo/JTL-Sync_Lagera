<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\OAuthException;
use App\Services\OAuthService;
use App\Support\Auth;
use JsonException;

/**
 * OAuth endpoints that let Claude (or another MCP client) connect to /mcp: discovery documents,
 * client registration, the consent page an admin approves, and the token endpoint.
 */
final class OAuthController extends Controller
{
    public function protectedResource(): void
    {
        $this->json(200, (new OAuthService())->protectedResourceMetadata());
    }

    public function authorizationServer(): void
    {
        $this->json(200, (new OAuthService())->authorizationServerMetadata());
    }

    public function register(): void
    {
        if (!$this->isPost()) {
            $this->json(405, ['error' => 'invalid_request', 'error_description' => 'Use POST.']);
            return;
        }

        try {
            $request = json_decode((string) file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
            $this->json(201, (new OAuthService())->register(is_array($request) ? $request : []));
        } catch (JsonException) {
            $this->json(400, ['error' => 'invalid_client_metadata', 'error_description' => 'El cuerpo debe ser JSON.']);
        } catch (OAuthException $exception) {
            $this->json(400, ['error' => $exception->error, 'error_description' => $exception->getMessage()]);
        }
    }

    public function token(): void
    {
        if (!$this->isPost()) {
            $this->json(405, ['error' => 'invalid_request', 'error_description' => 'Use POST.']);
            return;
        }

        try {
            $this->json(200, (new OAuthService())->token($_POST));
        } catch (OAuthException $exception) {
            $this->json(400, ['error' => $exception->error, 'error_description' => $exception->getMessage()]);
        }
    }

    /** GET shows the consent page; POST is the admin's answer. index.php already made them log in. */
    public function authorize(): void
    {
        $auth = new Auth();

        if (!$auth->required()) {
            $this->page(403, 'Primero crea un usuario administrador en el panel. Sin login activo no se puede dar acceso por MCP.');
            return;
        }

        if (!$auth->isAdmin()) {
            $this->page(403, 'Solo un administrador puede conectar Claude u otra aplicacion a este panel.');
            return;
        }

        $service = new OAuthService();
        $params = $this->isPost() ? $_POST : $_GET;

        try {
            $request = $service->validateAuthorizationRequest($params);
        } catch (OAuthException $exception) {
            $this->page(400, $exception->getMessage());
            return;
        }

        if (!$this->isPost()) {
            $this->consentPage($request, $params, $auth->currentUsername() ?? 'admin');
            return;
        }

        $query = ['iss' => OAuthService::baseUrl()];

        if (($_POST['decision'] ?? '') === 'approve') {
            $query['code'] = $service->issueCode(
                (string) $request['client']['client_id'],
                $request['redirect_uri'],
                $request['code_challenge'],
                $auth->currentUserId(),
                $auth->currentUsername() ?? 'admin'
            );
        } else {
            $query['error'] = 'access_denied';
        }

        if ($request['state'] !== null) {
            $query['state'] = $request['state'];
        }

        $separator = str_contains($request['redirect_uri'], '?') ? '&' : '?';
        header('Location: ' . $request['redirect_uri'] . $separator . http_build_query($query), true, 302);
    }

    /**
     * @param array{client: array<string, mixed>, redirect_uri: string, code_challenge: string, state: string|null} $request
     * @param array<string, mixed> $params
     */
    private function consentPage(array $request, array $params, string $username): void
    {
        $fields = '';

        foreach (['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'] as $key) {
            if (is_string($params[$key] ?? null)) {
                $fields .= '<input type="hidden" name="' . $key . '" value="' . $this->e($params[$key]) . '">';
            }
        }

        $clientName = (string) ($request['client']['client_name'] ?? '') !== '' ? (string) $request['client']['client_name'] : 'Una aplicacion';
        $host = (string) parse_url($request['redirect_uri'], PHP_URL_HOST);
        $body = '<h1>Conectar con Lagera JTL Sync</h1>'
            . '<p><strong>' . $this->e($clientName) . '</strong> (de <strong>' . $this->e($host) . '</strong>) quiere acceder al panel como <strong>' . $this->e($username) . '</strong>.</p>'
            . '<p>Podra ver logs, ordenes y fulfillment, y ejecutar sincronizaciones de ordenes y tracking. No podra cambiar ajustes ni usuarios.</p>'
            . '<p class="muted">Si no iniciaste tu esta conexion, rechazala.</p>'
            . '<form method="post" action="' . $this->e($this->url('/oauth/authorize')) . '">' . $fields
            . '<button type="submit" name="decision" value="approve">Permitir acceso</button> '
            . '<button type="submit" name="decision" value="deny" class="secondary">Rechazar</button>'
            . '</form>';

        $this->html(200, $body);
    }

    private function page(int $status, string $message): void
    {
        $this->html($status, '<h1>No se puede conectar</h1><p>' . $this->e($message) . '</p><p><a href="' . $this->e($this->url('/')) . '">Volver al panel</a></p>');
    }

    private function html(int $status, string $body): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        // The consent page must never be framed, or a page could trick an admin into clicking "Permitir".
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'");
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Conectar - Lagera JTL Sync</title><style>'
            . 'body{background:#fef7eb;color:#000;font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;padding:16px;box-sizing:border-box}'
            . 'main{background:#fff;border:1px solid #ece4d6;border-radius:12px;max-width:460px;width:100%;padding:28px}'
            . 'h1{font-size:20px;margin:0 0 16px}p{line-height:1.5}.muted{color:#737373;font-size:14px}'
            . 'button{background:#5c64cf;border:0;border-radius:8px;color:#fff;cursor:pointer;font:inherit;font-weight:600;padding:10px 16px;margin-top:8px}'
            . 'button.secondary{background:#fff;border:1px solid #ddd3c2;color:#000}a{color:#4a52b8}'
            . '</style></head><body><main>' . $body . '</main></body></html>';
    }

    private function json(int $status, mixed $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
