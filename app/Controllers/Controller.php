<?php

declare(strict_types=1);

namespace App\Controllers;

/** Request helpers shared by every controller. */
abstract class Controller
{
    protected function url(string $path): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

        return $base . '/' . ltrim($path, '/');
    }

    protected function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }

    protected function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    protected function methodNotAllowed(): void
    {
        http_response_code(405);
        echo 'Method Not Allowed';
    }

    protected function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        return is_string($accept) && str_contains($accept, 'application/json');
    }

    protected function postedString(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    protected function startSession(): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_start();
    }

    /** Keeps flash notices short enough for the session and the page; the full text goes to Logs. */
    protected function compactNotice(string $message): string
    {
        $limit = 1600;

        if (strlen($message) <= $limit) {
            return $message;
        }

        return substr($message, 0, $limit - 70)
            . '... Detalle completo guardado en Logs.';
    }
}
