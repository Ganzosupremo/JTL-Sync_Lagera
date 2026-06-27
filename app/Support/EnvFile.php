<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class EnvFile
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @param array<string, string> $updates
     */
    public function update(array $updates): void
    {
        $lines = is_file($this->path) ? file($this->path, FILE_IGNORE_NEW_LINES) : [];

        if ($lines === false) {
            throw new RuntimeException('Unable to read .env file.');
        }

        $written = [];

        foreach ($lines as $index => $line) {
            $key = $this->lineKey((string) $line);

            if ($key === null || !array_key_exists($key, $updates)) {
                continue;
            }

            $lines[$index] = $key . '=' . $this->encode($updates[$key]);
            $written[$key] = true;
        }

        foreach ($updates as $key => $value) {
            if (isset($written[$key])) {
                continue;
            }

            if ($lines !== [] && trim((string) end($lines)) !== '') {
                $lines[] = '';
            }

            $lines[] = $key . '=' . $this->encode($value);
        }

        $contents = implode(PHP_EOL, $lines) . PHP_EOL;

        if (file_put_contents($this->path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write .env file.');
        }

        foreach ($updates as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function lineKey(string $line): ?string
    {
        if (!preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function encode(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', $value);
    }
}
