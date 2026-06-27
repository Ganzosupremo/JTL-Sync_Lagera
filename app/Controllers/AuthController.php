<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Auth;

final class AuthController
{
    public function login(): void
    {
        $auth = new Auth();

        if (!$auth->enabled()) {
            $this->redirect('/');
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->authenticate($auth);
            return;
        }

        if ($auth->check()) {
            $this->redirect('/');
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo $this->renderLogin(
            $auth->configured() ? null : 'Autenticacion no configurada. Define AUTH_USERNAME y AUTH_PASSWORD_HASH en .env.',
            $this->redirectTarget()
        );
    }

    public function logout(): void
    {
        (new Auth())->logout();
        $this->redirect('/login');
    }

    public function requireLogin(string $target): void
    {
        $auth = new Auth();

        if (!$auth->enabled() || $auth->check()) {
            return;
        }

        header('Location: ' . $this->url('/login') . '?redirect=' . rawurlencode($this->safeRedirect($target)), true, 303);
        exit;
    }

    private function authenticate(Auth $auth): void
    {
        $username = is_scalar($_POST['username'] ?? null) ? trim((string) $_POST['username']) : '';
        $password = is_scalar($_POST['password'] ?? null) ? (string) $_POST['password'] : '';
        $redirect = $this->safeRedirect(is_scalar($_POST['redirect'] ?? null) ? (string) $_POST['redirect'] : '/');

        if (!$auth->configured()) {
            header('Content-Type: text/html; charset=UTF-8');
            echo $this->renderLogin('Autenticacion no configurada. Define AUTH_USERNAME y AUTH_PASSWORD_HASH en .env.', $redirect);
            return;
        }

        if (!$auth->attempt($username, $password)) {
            header('Content-Type: text/html; charset=UTF-8');
            echo $this->renderLogin('Usuario o password incorrecto.', $redirect);
            return;
        }

        $auth->login($username);
        $this->redirect($redirect);
    }

    private function renderLogin(?string $error, string $redirect): string
    {
        ob_start();
        ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Lagera JTL Sync</title>
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
            max-width: 420px;
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
        <h1>Lagera JTL Sync</h1>
        <p>Ingresa para continuar.</p>

        <?php if ($error !== null): ?>
            <div class="error"><?= $this->e($error) ?></div>
        <?php endif; ?>

        <form action="<?= $this->e($this->url('/login')) ?>" method="post" autocomplete="off">
            <input type="hidden" name="redirect" value="<?= $this->e($redirect) ?>">

            <label for="username">Usuario</label>
            <input id="username" name="username" type="text" autofocus required>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" required>

            <button type="submit">Entrar</button>
        </form>
    </main>
</body>
</html>
        <?php

        return (string) ob_get_clean();
    }

    private function redirectTarget(): string
    {
        return $this->safeRedirect(is_scalar($_GET['redirect'] ?? null) ? (string) $_GET['redirect'] : '/');
    }

    private function safeRedirect(string $target): string
    {
        if (
            $target === ''
            || $target[0] !== '/'
            || str_starts_with($target, '//')
            || str_contains($target, "\r")
            || str_contains($target, "\n")
        ) {
            return '/';
        }

        return $target;
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
