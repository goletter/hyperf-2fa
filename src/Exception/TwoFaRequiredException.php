<?php

declare(strict_types=1);

namespace Goletter\TwoFa\Exception;

/**
 * 需要输入 TOTP 验证码（模块未验证或空闲超时）。
 */
class TwoFaRequiredException extends TwoFaException
{
    public function __construct(
        public readonly string $module,
        string $message = 'Two-factor authentication required.',
        int $code = 428
    ) {
        parent::__construct($message, $code);
    }
}
