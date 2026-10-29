<?php

declare(strict_types=1);

namespace Goletter\TwoFa\Store;

use Goletter\TwoFa\Contract\TwoFaUserRepositoryInterface;
use Goletter\TwoFa\TwoFaUser;

/**
 * 进程内内存实现，仅用于本地演示；生产请换数据库仓储。
 */
class InMemoryTwoFaUserRepository implements TwoFaUserRepositoryInterface
{
    /**
     * @var array<string, array{secret: string, enabled: bool}>
     */
    protected array $users = [];

    public function find(int|string $userId): ?TwoFaUser
    {
        $key = (string) $userId;
        if (! isset($this->users[$key])) {
            return new TwoFaUser($userId, null, false);
        }

        $row = $this->users[$key];

        return new TwoFaUser($userId, $row['secret'], $row['enabled']);
    }

    public function save(int|string $userId, string $secret, bool $enabled): void
    {
        $this->users[(string) $userId] = [
            'secret' => $secret,
            'enabled' => $enabled,
        ];
    }

    public function disable(int|string $userId): void
    {
        $key = (string) $userId;
        if (! isset($this->users[$key])) {
            return;
        }

        $this->users[$key]['enabled'] = false;
        $this->users[$key]['secret'] = '';
    }
}
