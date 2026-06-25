<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        private readonly string $method,
        private readonly string $url,
        string $body = ''
    ) {
        $message = sprintf(
            'HTTP %d from %s %s: %s',
            $statusCode,
            $method,
            $url,
            trim($body) !== '' ? $body : 'empty response body'
        );

        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function url(): string
    {
        return $this->url;
    }
}
