<?php

declare(strict_types=1);

namespace Goletter\TwoFa\Contract;

/**
 * 模块级「已验证」状态与最后活跃时间。
 */
interface TwoFaSessionStoreInterface
{
    /**
     * 是否仍在空闲窗口内（已验证且未超时）。
     */
    public function isFresh(int|string $userId, string $module, int $ttlSeconds): bool;

    /**
     * 标记模块验证通过，并设置 last_active。
     */
    public function markVerified(int|string $userId, string $module, int $ttlSeconds): void;

    /**
     * 刷新最后活跃时间（滑动窗口）；未验证时返回 false。
     */
    public function touch(int|string $userId, string $module, int $ttlSeconds): bool;

    /**
     * 清除某模块验证状态。
     */
    public function clear(int|string $userId, string $module): void;

    /**
     * 清除用户全部模块验证状态（例如解绑后）。
     */
    public function clearAll(int|string $userId): void;

    public function getLastActiveAt(int|string $userId, string $module): ?int;
}
