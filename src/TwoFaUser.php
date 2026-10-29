<?php

declare(strict_types=1);

namespace Goletter\TwoFa;

/**
 * 用户 2FA 绑定状态（由业务仓储返回）。
 */
class TwoFaUser
{
    public function __construct(
        public readonly int|string $userId,
        public readonly ?string $secret = null,
        public readonly bool $enabled = false,
    ) {
    }

    public function isBound(): bool
    {
        return $this->enabled && is_string($this->secret) && $this->secret !== '';
    }
}
