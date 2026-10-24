<?php

declare(strict_types=1);

namespace App\Views;

/** Shared helpers for the HTML views. Every value printed into HTML goes through e(). */
abstract class View
{
    protected function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }

    protected function url(string $path): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

        return $base . '/' . ltrim($path, '/');
    }

    protected function tabUrl(string $tab): string
    {
        return $this->url('/') . '?tab=' . rawurlencode($tab);
    }

    /** @param array<string, mixed> $data */
    protected function shortJson(array $data, int $maxLength = 220): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($json) || $json === '') {
            return '-';
        }

        if (strlen($json) <= $maxLength) {
            return $json;
        }

        return substr($json, 0, max(0, $maxLength - 3)) . '...';
    }

    protected function customerDisplayName(array $customer): string
    {
        foreach (['name', 'company_name', 'email', 'packiyo_customer_id'] as $key) {
            $value = $customer[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return '-';
    }

    /** Inlines a file from app/Views/Dashboard (styles and scripts stay in one response, no static file serving needed). */
    protected function asset(string $file): string
    {
        return (string) file_get_contents(__DIR__ . '/Dashboard/' . $file);
    }
}
