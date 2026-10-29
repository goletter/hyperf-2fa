<?php

declare(strict_types=1);

namespace Goletter\TwoFa\Exception;

/**
 * 用户尚未绑定 / 启用 2FA。
 */
class TwoFaNotBoundException extends TwoFaException
{
    public function __construct(string $message = 'Two-factor authentication is not bound.', int $code = 428)
    {
        parent::__construct($message, $code);
    }
}
