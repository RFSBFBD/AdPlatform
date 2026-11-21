<?php

namespace App\Exceptions;

use RuntimeException;

class OAuthTokenExpiredException extends RuntimeException
{
    public function __construct(
        string $message = "OAuth token has expired and could not be renewed.",
        public ?string $provider = null,
        public ?int $accountId = null
    ) {
        parent::__construct($message);
    }
}
