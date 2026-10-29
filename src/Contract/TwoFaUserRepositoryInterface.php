<?php

declare(strict_types=1);

namespace Goletter\TwoFa\Contract;

use Goletter\TwoFa\TwoFaUser;

/**
 * 用户 2FA 密钥持久化（生产请用数据库实现，secret 建议加密存储）。
 */
interface TwoFaUserRepositoryInterface
{
    public function find(int|string $userId): ?TwoFaUser;

    /**
     * 写入/更新密钥。
     *
     * @param bool $enabled true 表示已确认绑定并启用
     */
    public function save(int|string $userId, string $secret, bool $enabled): void;

    /**
     * 关闭 2FA（可清空 secret 或仅 disabled，由实现决定）。
     */
    public function disable(int|string $userId): void;
}
