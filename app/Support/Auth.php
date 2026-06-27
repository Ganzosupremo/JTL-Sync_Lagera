<?php

declare(strict_types=1);

namespace App\Support;

final class Auth
{
    public function __construct()
    {
        $this->startSession();
    }

    public function enabled(): bool
    {
        return (bool) Env::get('AUTH_ENABLED', false);
    }

    public function configured(): bool
    {
        return trim((string) Env::get('AUTH_USERNAME', '')) !== ''
            && (
                trim((string) Env::get('AUTH_PASSWORD_HASH', '')) !== ''
                || trim((string) Env::get('AUTH_PASSWORD', '')) !== ''
            );
    }

    public function check(): bool
    {
        if (!$this->enabled()) {
            return true;
        }

        return ($_SESSION['authenticated'] ?? false) === true;
    }

    public function attempt(string $username, string $password): bool
    {
        if (!$this->enabled() || !$this->configured()) {
            return false;
        }

        $expectedUser = (string) Env::get('AUTH_USERNAME', '');

        if (!hash_equals($expectedUser, $username)) {
            return false;
        }

        $hash = trim((string) Env::get('AUTH_PASSWORD_HASH', ''));

        if ($hash !== '') {
            return password_verify($password, $hash);
        }

        $plainPassword = (string) Env::get('AUTH_PASSWORD', '');

        return $plainPassword !== '' && hash_equals($plainPassword, $password);
    }

    public function login(string $username): void
    {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['auth_user'] = $username;
        $_SESSION['authenticated_at'] = time();
    }

    public function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    private function startSession(): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $sessionName = (string) Env::get('AUTH_SESSION_NAME', 'jtlsync_session');

        if (preg_match('/^[A-Za-z0-9_-]+$/', $sessionName) === 1) {
            session_name($sessionName);
        }

        $secure = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }
}
