<?php

declare(strict_types=1);

namespace Goletter\TwoFa\Exception;

class TwoFaInvalidCodeException extends TwoFaException
{
    public function __construct(string $message = 'Invalid two-factor authentication code.', int $code = 422)
    {
        parent::__construct($message, $code);
    }
}
