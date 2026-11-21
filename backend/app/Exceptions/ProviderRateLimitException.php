<?php

namespace App\Exceptions;

use RuntimeException;

class ProviderRateLimitException extends RuntimeException
{
    public function __construct(
        string $message = "Provider rate limit reached.",
        public ?string $provider = null,
        public ?int $retryAfterSeconds = null
    ) {
        parent::__construct($message);
    }
}
