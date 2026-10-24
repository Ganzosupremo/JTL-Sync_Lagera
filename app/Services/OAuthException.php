<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** An OAuth error: $error is the RFC 6749 error code, the message is shown to the person. */
final class OAuthException extends RuntimeException
{
    public function __construct(public readonly string $error, string $description)
    {
        parent::__construct($description);
    }
}
