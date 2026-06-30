<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AppUser;

final class Auth
{
    /** @var array<string, mixed>|null */
    private ?array $authenticatedUser = null;

    public function __construct()
    {
        $this->startSession();
    }

    public function enabled(): bool
    {
        return (bool) Setting::get('AUTH_ENABLED', false);
    }

    public function configured(): bool
    {
        return $this->users()->hasActiveUsers() || $this->legacyConfigured();
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

        $user = $this->users()->findByLogin($username);

        if ($user !== null && password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            $this->authenticatedUser = $user;
            return true;
        }

        return $this->attemptLegacy($username, $password);
    }

    public function login(string $username): void
    {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['auth_user'] = $username;
        $_SESSION['authenticated_at'] = time();

        $user = $this->authenticatedUser ?? $this->users()->findByLogin($username);

        if ($user !== null) {
            $id = (int) ($user['id'] ?? 0);
            $_SESSION['auth_user_id'] = $id;
            $_SESSION['auth_user'] = (string) ($user['username'] ?? $username);

            if ($id > 0) {
                $this->users()->markLogin($id);
            }
        }
    }

    public function currentUserId(): ?int
    {
        $id = $_SESSION['auth_user_id'] ?? null;

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    private function attemptLegacy(string $username, string $password): bool
    {
        if (!$this->legacyConfigured()) {
            return false;
        }

        $expectedUser = (string) Setting::get('AUTH_USERNAME', '');

        if (!hash_equals($expectedUser, $username)) {
            return false;
        }

        $hash = trim((string) Setting::get('AUTH_PASSWORD_HASH', ''));

        if ($hash !== '') {
            return password_verify($password, $hash);
        }

        $plainPassword = (string) Setting::get('AUTH_PASSWORD', '');

        return $plainPassword !== '' && hash_equals($plainPassword, $password);
    }

    private function legacyConfigured(): bool
    {
        return trim((string) Setting::get('AUTH_USERNAME', '')) !== ''
            && (
                trim((string) Setting::get('AUTH_PASSWORD_HASH', '')) !== ''
                || trim((string) Setting::get('AUTH_PASSWORD', '')) !== ''
            );
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

        $sessionName = (string) Setting::get('AUTH_SESSION_NAME', 'jtlsync_session');

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

    private function users(): AppUser
    {
        return new AppUser();
    }
}
