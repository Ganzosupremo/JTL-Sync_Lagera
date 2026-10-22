<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AppUser;
use App\Models\LoginAttempt;

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
        return (bool) Setting::get('AUTH_ENABLED', true);
    }

    /** Login is switched on and there is at least one user who can log in. */
    public function required(): bool
    {
        return $this->enabled() && $this->configured();
    }

    public function configured(): bool
    {
        return $this->users()->hasActiveUsers() || $this->legacyConfigured();
    }

    public function check(): bool
    {
        // With no users yet the panel stays open (setup mode, flagged in the dashboard) so the
        // first user can be invited instead of locking everyone out.
        if (!$this->enabled() || !$this->configured()) {
            return true;
        }

        return ($_SESSION['authenticated'] ?? false) === true;
    }

    /** Too many failed logins for this username/email in the last LoginAttempt::WINDOW_MINUTES. */
    public function locked(string $username): bool
    {
        return $this->attempts()->isLocked($username);
    }

    public function attempt(string $username, string $password): bool
    {
        if (!$this->enabled() || !$this->configured() || $this->locked($username)) {
            return false;
        }

        $user = $this->users()->findByLogin($username);

        if ($user !== null && password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            $this->authenticatedUser = $user;
            $this->attempts()->clear($username);
            return true;
        }

        if ($this->attemptLegacy($username, $password)) {
            $this->attempts()->clear($username);
            return true;
        }

        $this->attempts()->recordFailure($username);

        return false;
    }

    /**
     * Admins can change settings, users and the JTL registration. Without required login
     * (disabled, or no users yet) everyone is treated as admin, as before roles existed.
     */
    public function isAdmin(): bool
    {
        if (!$this->required()) {
            return true;
        }

        if (($_SESSION['authenticated'] ?? false) !== true) {
            return false;
        }

        $id = $this->currentUserId();

        if ($id === null) {
            // The bootstrap user from .env has no database row and is always an admin.
            return true;
        }

        $user = $this->users()->findById($id);

        return $user !== null
            && (int) ($user['active'] ?? 0) === 1
            && (string) ($user['role'] ?? AppUser::ROLE_ADMIN) === AppUser::ROLE_ADMIN;
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

        // A plain-text AUTH_PASSWORD only works to bootstrap the first user; once real users
        // exist, the bootstrap login needs AUTH_PASSWORD_HASH.
        if ($this->users()->hasActiveUsers()) {
            return false;
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

    private function attempts(): LoginAttempt
    {
        return new LoginAttempt();
    }
}
