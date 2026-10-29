<?php

declare(strict_types=1);

namespace Goletter\TwoFa;

use Goletter\TwoFa\Contract\TwoFaSessionStoreInterface;
use Goletter\TwoFa\Contract\TwoFaUserRepositoryInterface;
use Goletter\TwoFa\Contract\UserIdResolverInterface;
use Goletter\TwoFa\Store\InMemoryTwoFaUserRepository;
use Goletter\TwoFa\Store\RedisTwoFaSessionStore;
use Goletter\TwoFa\Store\RequestAttributeUserIdResolver;

/**
 * 默认绑定内存用户仓储（仅开发/演示）；生产请换成数据库实现。
 */
class ConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                TwoFaSessionStoreInterface::class => RedisTwoFaSessionStore::class,
                UserIdResolverInterface::class => RequestAttributeUserIdResolver::class,
                // 生产务必覆盖：
                // TwoFaUserRepositoryInterface::class => App\TwoFa\DbTwoFaUserRepository::class,
                TwoFaUserRepositoryInterface::class => InMemoryTwoFaUserRepository::class,
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config of Two-Factor Authentication.',
                    'source' => __DIR__ . '/../publish/two_fa.php',
                    'destination' => BASE_PATH . '/config/autoload/two_fa.php',
                ],
            ],
        ];
    }
}
