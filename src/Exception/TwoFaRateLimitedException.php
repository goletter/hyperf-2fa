<?php

declare(strict_types=1);

namespace Goletter\TwoFa\Exception;

class TwoFaRateLimitedException extends TwoFaException
{
    public function __construct(
        public readonly int $retryAfter,
        string $message = 'Too many two-factor authentication attempts.',
        int $code = 429
    ) {
        parent::__construct($message, $code);
    }
}
