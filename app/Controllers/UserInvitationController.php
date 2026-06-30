<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AppUser;
use App\Models\UserInvitation;
use App\Support\Auth;
use App\Support\Setting;
use RuntimeException;
use Throwable;

final class UserInvitationController
{
    public function create(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        try {
            $email = is_scalar($_POST['email'] ?? null) ? (string) $_POST['email'] : '';
            $ttl = $this->ttlHours(is_scalar($_POST['ttl_hours'] ?? null) ? (string) $_POST['ttl_hours'] : null);
            $token = (new UserInvitation())->create($email, (new Auth())->currentUserId(), $ttl);
            $link = $this->absoluteUrl('/invite?token=' . rawurlencode($token));
            $this->redirect('/?tab=settings&notice=' . rawurlencode('Invitacion creada: ' . $link));
        } catch (Throwable $exception) {
            $this->redirect('/?tab=settings&notice=' . rawurlencode($exception->getMessage()));
        }
    }

    public function revoke(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        $id = is_numeric($_POST['id'] ?? null) ? (int) $_POST['id'] : 0;

        if ($id > 0) {
            (new UserInvitation())->revoke($id);
        }

        $this->redirect('/?tab=settings&notice=' . rawurlencode('Invitacion revocada.'));
    }

    public function accept(): void
    {
        $token = $this->requestToken();
        $invitation = $token !== '' ? (new UserInvitation())->findValidByToken($token) : null;

        if ($invitation === null) {
            header('Content-Type: text/html; charset=UTF-8');
            echo $this->renderInvite(null, $token, 'Invitacion invalida, expirada o revocada.');
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->acceptPost($token, $invitation);
            return;
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo $this->renderInvite($invitation, $token, null);
    }

    /** @param array<string, mixed> $invitation */
    private function acceptPost(string $token, array $invitation): void
    {
        try {
            $username = is_scalar($_POST['username'] ?? null) ? trim((string) $_POST['username']) : '';
            $password = is_scalar($_POST['password'] ?? null) ? (string) $_POST['password'] : '';
            $passwordConfirmation = is_scalar($_POST['password_confirmation'] ?? null)
                ? (string) $_POST['password_confirmation']
                : '';

            if ($username === '' || !preg_match('/^[A-Za-z0-9._-]{3,120}$/', $username)) {
                throw new RuntimeException('Usa un username de 3 a 120 caracteres con letras, numeros, punto, guion o underscore.');
            }

            if (strlen($password) < 10) {
                throw new RuntimeException('El password debe tener al menos 10 caracteres.');
            }

            if (!hash_equals($password, $passwordConfirmation)) {
                throw new RuntimeException('Los passwords no coinciden.');
            }

            $email = (string) ($invitation['email'] ?? '');
            $userId = (new AppUser())->create($username, $email, $password);
            (new UserInvitation())->accept((int) $invitation['id'], $userId);

            $auth = new Auth();
            $auth->login($username);

            $this->redirect('/?notice=' . rawurlencode('Usuario creado correctamente.'));
        } catch (Throwable $exception) {
            header('Content-Type: text/html; charset=UTF-8');
            echo $this->renderInvite($invitation, $token, $exception->getMessage());
        }
    }

    /** @param array<string, mixed>|null $invitation */
    private function renderInvite(?array $invitation, string $token, ?string $error): string
    {
        $email = is_array($invitation) ? (string) ($invitation['email'] ?? '') : '';

        ob_start();
        ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invitacion - Lagera JTL Sync</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6f7f9;
            --panel: #ffffff;
            --text: #1b1f24;
            --muted: #667085;
            --line: #d9dee7;
            --accent: #2563eb;
            --bad: #b42318;
        }

        * {
            box-sizing: border-box;
        }

        body {
            align-items: center;
            background: var(--bg);
            color: var(--text);
            display: flex;
            font-family: Arial, Helvetica, sans-serif;
            justify-content: center;
            margin: 0;
            min-height: 100vh;
            padding: 24px;
        }

        main {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            max-width: 460px;
            padding: 24px;
            width: 100%;
        }

        h1 {
            font-size: 22px;
            margin: 0 0 4px;
        }

        p {
            color: var(--muted);
            margin: 0 0 18px;
        }

        label {
            color: var(--muted);
            display: block;
            font-size: 12px;
            font-weight: 700;
            margin: 0 0 6px;
        }

        input {
            border: 1px solid var(--line);
            border-radius: 6px;
            font: inherit;
            margin-bottom: 14px;
            min-height: 42px;
            padding: 0 10px;
            width: 100%;
        }

        button {
            background: var(--accent);
            border: 0;
            border-radius: 6px;
            color: #fff;
            cursor: pointer;
            font-weight: 700;
            min-height: 42px;
            width: 100%;
        }

        .error {
            background: #fff1f0;
            border: 1px solid #fecdca;
            border-radius: 6px;
            color: var(--bad);
            margin-bottom: 14px;
            padding: 10px 12px;
        }
    </style>
</head>
<body>
    <main>
        <h1>Crear usuario</h1>
        <p><?= $email !== '' ? 'Invitacion para ' . $this->e($email) : 'Link de invitacion.' ?></p>

        <?php if ($error !== null): ?>
            <div class="error"><?= $this->e($error) ?></div>
        <?php endif; ?>

        <?php if ($invitation !== null): ?>
            <form action="<?= $this->e($this->url('/invite')) ?>" method="post" autocomplete="off">
                <input type="hidden" name="token" value="<?= $this->e($token) ?>">

                <label for="username">Usuario</label>
                <input id="username" name="username" type="text" required autofocus>

                <label for="password">Password</label>
                <input id="password" name="password" type="password" required>

                <label for="password_confirmation">Confirmar password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required>

                <button type="submit">Crear usuario</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
        <?php

        return (string) ob_get_clean();
    }

    private function requestToken(): string
    {
        $token = $_POST['token'] ?? $_GET['token'] ?? '';

        return is_scalar($token) ? trim((string) $token) : '';
    }

    private function ttlHours(?string $value): int
    {
        if ($value !== null && ctype_digit($value)) {
            return (int) $value;
        }

        return (int) Setting::get('AUTH_INVITATION_TTL_HOURS', 72);
    }

    private function absoluteUrl(string $path): string
    {
        $baseUrl = $this->configuredBaseUrl();

        if ($baseUrl !== '') {
            return $baseUrl . '/' . ltrim($path, '/');
        }

        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return $scheme . '://' . $host . $this->url($path);
    }

    private function configuredBaseUrl(): string
    {
        $baseUrl = trim((string) Setting::get('APP_BASE_URL', ''));

        if ($baseUrl === '') {
            return '';
        }

        foreach (['hhttps://' => 'https://', 'hhttp://' => 'http://'] as $badPrefix => $goodPrefix) {
            if (str_starts_with(strtolower($baseUrl), $badPrefix)) {
                $baseUrl = $goodPrefix . substr($baseUrl, strlen($badPrefix));
                break;
            }
        }

        $baseUrl = rtrim($baseUrl, '/');
        $path = (string) (parse_url($baseUrl, PHP_URL_PATH) ?? '');
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));

        if (str_ends_with($path, '/public') && !str_ends_with($scriptDir, '/public')) {
            $baseUrl = substr($baseUrl, 0, -7);
        }

        return rtrim($baseUrl, '/');
    }

    private function redirect(string $path): void
    {
        header('Location: ' . $this->url($path), true, 303);
        exit;
    }

    private function url(string $path): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

        return $base . '/' . ltrim($path, '/');
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
