<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\LoginAttempt;
use App\Support\Auth;

final class AuthController extends Controller
{
    public function login(): void
    {
        $auth = new Auth();

        if (!$auth->required()) {
            $this->redirect('/');
        }

        if ($this->isPost()) {
            $this->authenticate($auth);
            return;
        }

        if ($auth->check()) {
            $this->redirect('/');
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo $this->renderLogin(
            $auth->configured() ? null : 'Autenticacion no configurada. Crea un usuario por invitacion o define el usuario bootstrap en .env.',
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

        if ($auth->check()) {
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
            echo $this->renderLogin('Autenticacion no configurada. Crea un usuario por invitacion o define el usuario bootstrap en .env.', $redirect);
            return;
        }

        if ($auth->locked($username)) {
            header('Content-Type: text/html; charset=UTF-8');
            echo $this->renderLogin(
                'Demasiados intentos fallidos para este usuario. Espera ' . LoginAttempt::WINDOW_MINUTES . ' minutos e intentalo de nuevo.',
                $redirect
            );
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <style>
        :root {
            color-scheme: light;
            --bg: #fef7eb;
            --panel: #ffffff;
            --text: #000000;
            --muted: #737373;
            --line: #ece4d6;
            --line-strong: #ddd3c2;
            --accent: #5c64cf;
            --accent-strong: #4a52b8;
            --accent-soft: #eef0fb;
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
            font-family: "Inter", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            justify-content: center;
            margin: 0;
            min-height: 100vh;
            padding: 24px;
        }

        main {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06), 0 8px 24px rgba(0, 0, 0, 0.05);
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
            border: 1px solid var(--line-strong);
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
            font-family: inherit;
            font-weight: 600;
            min-height: 42px;
            width: 100%;
        }

        button:hover {
            background: var(--accent-strong);
        }

        input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-soft);
            outline: none;
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
}
