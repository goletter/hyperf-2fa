<?php

declare(strict_types=1);

namespace Goletter\TwoFa\Store;

use Goletter\TwoFa\Contract\TwoFaSessionStoreInterface;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;

class RedisTwoFaSessionStore implements TwoFaSessionStoreInterface
{
    public function __construct(
        protected ConfigInterface $config,
        protected RedisFactory $redisFactory
    ) {
    }

    public function isFresh(int|string $userId, string $module, int $ttlSeconds): bool
    {
        $last = $this->getLastActiveAt($userId, $module);
        if ($last === null) {
            return false;
        }

        return (time() - $last) < max(1, $ttlSeconds);
    }

    public function markVerified(int|string $userId, string $module, int $ttlSeconds): void
    {
        $now = time();
        $payload = json_encode([
            'verified_at' => $now,
            'last_active_at' => $now,
        ], JSON_UNESCAPED_UNICODE);

        $ttl = max(1, $ttlSeconds);
        // Redis 过期略大于业务 ttl，避免边界刚超时 key 已丢
        $this->redis()->setex($this->sessionKey($userId, $module), $ttl + 60, (string) $payload);
        $this->redis()->sAdd($this->modulesKey($userId), $module);
    }

    public function touch(int|string $userId, string $module, int $ttlSeconds): bool
    {
        $raw = $this->redis()->get($this->sessionKey($userId, $module));
        if (! is_string($raw) || $raw === '') {
            return false;
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return false;
        }

        $data['last_active_at'] = time();
        $ttl = max(1, $ttlSeconds);
        $this->redis()->setex(
            $this->sessionKey($userId, $module),
            $ttl + 60,
            (string) json_encode($data, JSON_UNESCAPED_UNICODE)
        );

        return true;
    }

    public function clear(int|string $userId, string $module): void
    {
        $this->redis()->del($this->sessionKey($userId, $module));
        $this->redis()->sRem($this->modulesKey($userId), $module);
    }

    public function clearAll(int|string $userId): void
    {
        $modules = $this->redis()->sMembers($this->modulesKey($userId));
        if (is_array($modules)) {
            foreach ($modules as $module) {
                $this->redis()->del($this->sessionKey($userId, (string) $module));
            }
        }
        $this->redis()->del($this->modulesKey($userId));
    }

    public function getLastActiveAt(int|string $userId, string $module): ?int
    {
        $raw = $this->redis()->get($this->sessionKey($userId, $module));
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['last_active_at'])) {
            return null;
        }

        return (int) $data['last_active_at'];
    }

    protected function sessionKey(int|string $userId, string $module): string
    {
        return $this->prefix() . 'session:' . $userId . ':' . $module;
    }

    protected function modulesKey(int|string $userId): string
    {
        return $this->prefix() . 'modules:' . $userId;
    }

    protected function prefix(): string
    {
        return (string) $this->config->get('two_fa.redis.prefix', 'two_fa:');
    }

    protected function redis(): RedisProxy
    {
        $pool = (string) $this->config->get('two_fa.redis.pool', 'default');

        return $this->redisFactory->get($pool);
    }
}
