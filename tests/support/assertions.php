<?php

declare(strict_types=1);

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Esperado=' . var_export($expected, true) . ' Actual=' . var_export($actual, true));
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertContains(string $expected, string $actual, string $message): void
{
    if (!str_contains($actual, $expected)) {
        throw new RuntimeException($message . ' Falta=' . $expected . ' en ' . $actual);
    }
}

/** @param callable(): mixed $callback */
function assertThrows(string $exceptionClass, callable $callback, string $message): Throwable
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $exceptionClass) {
            return $exception;
        }

        throw new RuntimeException($message . ' Excepcion inesperada ' . $exception::class . ': ' . $exception->getMessage());
    }

    throw new RuntimeException($message . ' No se lanzo ' . $exceptionClass . '.');
}
