<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppUser;
use App\Models\OAuthClient;
use App\Models\OAuthToken;
use App\Support\Config;
use App\Support\Setting;

/**
 * A small OAuth 2.1 authorization server, just enough for MCP clients such as Claude: dynamic
 * client registration, authorization code with PKCE (S256 only) and rotating refresh tokens.
 * Approving access needs an admin logged into the panel, and every token stops working as soon
 * as its user is deactivated or loses the admin role.
 */
final class OAuthService
{
    public const CODE_TTL = 300;
    public const ACCESS_TTL = 3600;
    public const REFRESH_TTL = 30 * 86400;

    public function __construct(
        private readonly ?OAuthClient $clients = null,
        private readonly ?OAuthToken $tokens = null,
        private readonly ?AppUser $users = null
    ) {
    }

    /** Absolute URL of the app, from APP_BASE_URL or else the current request. */
    public static function baseUrl(): string
    {
        $configured = trim((string) Config::get('app.base_url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = str_starts_with($scriptDir, '/') ? rtrim($scriptDir, '/') : '';

        return ($https ? 'https' : 'http') . '://' . $host . $base;
    }

    /** @return array<string, mixed> */
    public function authorizationServerMetadata(): array
    {
        $base = self::baseUrl();

        return [
            'issuer' => $base,
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'registration_endpoint' => $base . '/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => ['mcp'],
        ];
    }

    /** @return array<string, mixed> */
    public function protectedResourceMetadata(): array
    {
        $base = self::baseUrl();

        return [
            'resource' => $base . '/mcp',
            'authorization_servers' => [$base],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['mcp'],
            'resource_name' => 'Lagera JTL Sync',
        ];
    }

    /**
     * RFC 7591 dynamic client registration. Only public clients (PKCE, no secret) are issued.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function register(array $request): array
    {
        $uris = $request['redirect_uris'] ?? null;

        if (!is_array($uris) || $uris === [] || count($uris) > 10) {
            throw new OAuthException('invalid_redirect_uri', 'redirect_uris debe ser una lista de 1 a 10 URLs.');
        }

        foreach ($uris as $uri) {
            if (!is_string($uri) || !self::acceptableRedirectUri($uri)) {
                throw new OAuthException('invalid_redirect_uri', 'Cada redirect_uri debe ser https (o http en localhost) y sin fragmento.');
            }
        }

        $name = is_string($request['client_name'] ?? null) ? mb_substr(trim($request['client_name']), 0, 200) : null;
        $clientId = bin2hex(random_bytes(16));
        $this->clientModel()->create($clientId, $name !== '' ? $name : null, $uris);

        return [
            'client_id' => $clientId,
            'client_id_issued_at' => time(),
            'client_name' => $name,
            'redirect_uris' => array_values($uris),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
    }

    /**
     * Checks an /oauth/authorize request. Problems with the client or redirect URI must not
     * redirect (the URI is untrusted), so they throw; the caller shows them on a page.
     *
     * @param array<string, mixed> $query
     * @return array{client: array<string, mixed>, redirect_uri: string, code_challenge: string, state: string|null}
     */
    public function validateAuthorizationRequest(array $query): array
    {
        $client = $this->clientModel()->find($this->param($query, 'client_id'));

        if ($client === null) {
            throw new OAuthException('invalid_client', 'La aplicacion que pide acceso no esta registrada.');
        }

        $redirectUri = $this->param($query, 'redirect_uri');

        if (!in_array($redirectUri, $client['redirect_uris'], true)) {
            throw new OAuthException('invalid_request', 'La redirect_uri no coincide con la registrada por la aplicacion.');
        }

        if ($this->param($query, 'response_type') !== 'code') {
            throw new OAuthException('unsupported_response_type', 'Solo se admite response_type=code.');
        }

        $challenge = $this->param($query, 'code_challenge');

        if ($this->param($query, 'code_challenge_method') !== 'S256' || preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge) !== 1) {
            throw new OAuthException('invalid_request', 'Se requiere PKCE con code_challenge_method=S256.');
        }

        $state = $this->param($query, 'state');

        return [
            'client' => $client,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'state' => $state !== '' ? $state : null,
        ];
    }

    public function issueCode(string $clientId, string $redirectUri, string $codeChallenge, ?int $userId, string $userLabel): string
    {
        return $this->tokenModel()->issue(OAuthToken::KIND_CODE, self::CODE_TTL, [
            'client_id' => $clientId,
            'user_id' => $userId,
            'user_label' => $userLabel,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
        ]);
    }

    /**
     * The /oauth/token endpoint.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function token(array $request): array
    {
        $this->tokenModel()->pruneExpired();

        return match ($this->param($request, 'grant_type')) {
            'authorization_code' => $this->exchangeCode($request),
            'refresh_token' => $this->refresh($request),
            default => throw new OAuthException('unsupported_grant_type', 'grant_type no soportado.'),
        };
    }

    /**
     * Who a bearer token on /mcp belongs to, or null if it is not valid (anymore).
     *
     * @return array{label: string, user_id: int|null}|null
     */
    public function authenticate(string $bearer): ?array
    {
        if ($bearer === '') {
            return null;
        }

        $staticToken = trim((string) Setting::get('MCP_TOKEN', ''));

        if ($staticToken !== '' && hash_equals($staticToken, $bearer)) {
            return ['label' => 'token MCP', 'user_id' => null];
        }

        $row = $this->tokenModel()->findValid(OAuthToken::KIND_ACCESS, $bearer);

        if ($row === null || !$this->userStillAdmin($row)) {
            return null;
        }

        return [
            'label' => (string) $row['user_label'],
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function exchangeCode(array $request): array
    {
        $row = $this->tokenModel()->findValid(OAuthToken::KIND_CODE, $this->param($request, 'code'));

        if ($row === null) {
            throw new OAuthException('invalid_grant', 'El codigo no es valido o ya vencio.');
        }

        $clientId = $this->param($request, 'client_id');
        $redirectUri = $this->param($request, 'redirect_uri');

        if (
            !hash_equals((string) $row['client_id'], $clientId)
            || $redirectUri !== (string) $row['redirect_uri']
        ) {
            throw new OAuthException('invalid_grant', 'El codigo no corresponde a esta aplicacion.');
        }

        $verifier = $this->param($request, 'code_verifier');
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        if ($verifier === '' || !hash_equals((string) $row['code_challenge'], $expected)) {
            throw new OAuthException('invalid_grant', 'code_verifier incorrecto.');
        }

        // Spending the code first means a replayed code can never mint a second pair of tokens.
        if (!$this->tokenModel()->consume((int) $row['id']) || !$this->userStillAdmin($row)) {
            throw new OAuthException('invalid_grant', 'El codigo ya se uso o el usuario ya no es administrador.');
        }

        return $this->issuePair($row);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function refresh(array $request): array
    {
        $row = $this->tokenModel()->findValid(OAuthToken::KIND_REFRESH, $this->param($request, 'refresh_token'));
        $clientId = $this->param($request, 'client_id');

        if ($row === null || !hash_equals((string) $row['client_id'], $clientId)) {
            throw new OAuthException('invalid_grant', 'El refresh token no es valido o ya vencio.');
        }

        if (!$this->tokenModel()->consume((int) $row['id']) || !$this->userStillAdmin($row)) {
            throw new OAuthException('invalid_grant', 'El refresh token ya se uso o el usuario ya no es administrador.');
        }

        return $this->issuePair($row);
    }

    /**
     * @param array<string, mixed> $grant The spent code or refresh token the new pair comes from.
     * @return array<string, mixed>
     */
    private function issuePair(array $grant): array
    {
        $data = [
            'client_id' => (string) $grant['client_id'],
            'user_id' => $grant['user_id'] !== null ? (int) $grant['user_id'] : null,
            'user_label' => (string) $grant['user_label'],
        ];

        return [
            'access_token' => $this->tokenModel()->issue(OAuthToken::KIND_ACCESS, self::ACCESS_TTL, $data),
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $this->tokenModel()->issue(OAuthToken::KIND_REFRESH, self::REFRESH_TTL, $data),
            'scope' => 'mcp',
        ];
    }

    /** @param array<string, mixed> $row */
    private function userStillAdmin(array $row): bool
    {
        if ($row['user_id'] === null) {
            // The bootstrap user from .env has no database row; Auth treats it as an admin too.
            return true;
        }

        $user = $this->userModel()->findById((int) $row['user_id']);

        return $user !== null
            && (int) ($user['active'] ?? 0) === 1
            && (string) ($user['role'] ?? AppUser::ROLE_ADMIN) === AppUser::ROLE_ADMIN;
    }

    private static function acceptableRedirectUri(string $uri): bool
    {
        $parts = parse_url($uri);

        if (!is_array($parts) || isset($parts['fragment']) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        return $scheme === 'https'
            || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));
    }

    /** @param array<string, mixed> $source */
    private function param(array $source, string $key): string
    {
        $value = $source[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function clientModel(): OAuthClient
    {
        return $this->clients ?? new OAuthClient();
    }

    private function tokenModel(): OAuthToken
    {
        return $this->tokens ?? new OAuthToken();
    }

    private function userModel(): AppUser
    {
        return $this->users ?? new AppUser();
    }
}
