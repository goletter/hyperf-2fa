<?php

declare(strict_types=1);

namespace Goletter\TwoFa\Service;

use Goletter\TwoFa\Contract\TwoFaSessionStoreInterface;
use Goletter\TwoFa\Contract\TwoFaUserRepositoryInterface;
use Goletter\TwoFa\Exception\TwoFaInvalidCodeException;
use Goletter\TwoFa\Exception\TwoFaNotBoundException;
use Goletter\TwoFa\Exception\TwoFaRateLimitedException;
use Goletter\TwoFa\Exception\TwoFaRequiredException;
use Goletter\TwoFa\TwoFaUser;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use PragmaRX\Google2FA\Google2FA;

class TwoFaService
{
    protected Google2FA $google2fa;

    public function __construct(
        protected ConfigInterface $config,
        protected TwoFaUserRepositoryInterface $users,
        protected TwoFaSessionStoreInterface $sessions,
        protected RedisFactory $redisFactory,
        ?Google2FA $google2fa = null,
    ) {
        $this->google2fa = $google2fa ?? new Google2FA();
        $window = (int) $this->config->get('two_fa.window', 1);
        $this->google2fa->setWindow($window);
    }

    /**
     * 开始绑定：生成 secret（pending），返回 otpauth 信息。
     *
     * @return array{secret: string, otpauth_url: string, issuer: string, account: string}
     */
    public function beginBind(int|string $userId, ?string $accountName = null): array
    {
        $length = (int) $this->config->get('two_fa.secret_length', 32);
        $secret = $this->google2fa->generateSecretKey(max(16, $length));
        $issuer = (string) $this->config->get('two_fa.issuer', 'App');
        $account = $accountName ?: (string) $userId;

        $pendingTtl = max(60, (int) $this->config->get('two_fa.pending_ttl', 600));
        $this->redis()->setex($this->pendingKey($userId), $pendingTtl, $secret);

        return [
            'secret' => $secret,
            'otpauth_url' => $this->google2fa->getQRCodeUrl($issuer, $account, $secret),
            'issuer' => $issuer,
            'account' => $account,
        ];
    }

    /**
     * 确认绑定：校验 pending secret 的验证码后启用。
     */
    public function confirmBind(int|string $userId, string $code): TwoFaUser
    {
        $this->assertNotRateLimited($userId);

        $secret = $this->redis()->get($this->pendingKey($userId));
        if (! is_string($secret) || $secret === '') {
            throw new TwoFaInvalidCodeException('Binding session expired, please start again.');
        }

        if (! $this->verifyCode($userId, $secret, $code)) {
            $this->hitRateLimit($userId);
            throw new TwoFaInvalidCodeException();
        }

        $this->users->save($userId, $secret, true);
        $this->redis()->del($this->pendingKey($userId));
        $this->clearRateLimit($userId);

        $user = $this->users->find($userId);

        return $user ?? new TwoFaUser($userId, $secret, true);
    }

    /**
     * 关闭 2FA（需当前验证码）。
     */
    public function disable(int|string $userId, string $code): void
    {
        $user = $this->requireBoundUser($userId);
        $this->assertNotRateLimited($userId);

        if (! $this->verifyCode($userId, (string) $user->secret, $code)) {
            $this->hitRateLimit($userId);
            throw new TwoFaInvalidCodeException();
        }

        $this->users->disable($userId);
        $this->sessions->clearAll($userId);
        $this->clearRateLimit($userId);
    }

    public function getUser(int|string $userId): ?TwoFaUser
    {
        return $this->users->find($userId);
    }

    public function isBound(int|string $userId): bool
    {
        $user = $this->users->find($userId);

        return $user?->isBound() ?? false;
    }

    /**
     * 模块是否仍在空闲窗口内。
     */
    public function isModuleFresh(int|string $userId, string $module): bool
    {
        return $this->sessions->isFresh($userId, $module, $this->moduleTtl($module));
    }

    /**
     * 断言模块已通过且未超时；否则抛 TwoFaRequiredException。
     *
     * @throws TwoFaNotBoundException
     * @throws TwoFaRequiredException
     */
    public function assertModuleVerified(int|string $userId, string $module): void
    {
        if (! $this->isBound($userId)) {
            throw new TwoFaNotBoundException();
        }

        if (! $this->isModuleFresh($userId, $module)) {
            throw new TwoFaRequiredException($module);
        }

        $this->sessions->touch($userId, $module, $this->moduleTtl($module));
    }

    /**
     * 校验验证码并标记模块已通过。
     */
    public function verifyModule(int|string $userId, string $module, string $code): void
    {
        $user = $this->requireBoundUser($userId);
        $this->assertNotRateLimited($userId);

        if (! $this->verifyCode($userId, (string) $user->secret, $code)) {
            $this->hitRateLimit($userId);
            throw new TwoFaInvalidCodeException();
        }

        $this->sessions->markVerified($userId, $module, $this->moduleTtl($module));
        $this->clearRateLimit($userId);
    }

    /**
     * 业务请求成功后可主动刷新活跃时间。
     */
    public function touch(int|string $userId, string $module): bool
    {
        return $this->sessions->touch($userId, $module, $this->moduleTtl($module));
    }

    /**
     * @return array{
     *     bound: bool,
     *     module: string,
     *     verified: bool,
     *     ttl: int,
     *     last_active_at: int|null,
     *     remaining_seconds: int|null
     * }
     */
    public function status(int|string $userId, string $module): array
    {
        $ttl = $this->moduleTtl($module);
        $last = $this->sessions->getLastActiveAt($userId, $module);
        $verified = $this->isModuleFresh($userId, $module);
        $remaining = null;
        if ($verified && $last !== null) {
            $remaining = max(0, $ttl - (time() - $last));
        }

        return [
            'bound' => $this->isBound($userId),
            'module' => $module,
            'verified' => $verified,
            'ttl' => $ttl,
            'last_active_at' => $last,
            'remaining_seconds' => $remaining,
        ];
    }

    public function moduleTtl(string $module): int
    {
        $modules = (array) $this->config->get('two_fa.modules', []);
        $ttl = $modules[$module]['ttl'] ?? $modules['default']['ttl'] ?? 3600;

        return max(1, (int) $ttl);
    }

    protected function requireBoundUser(int|string $userId): TwoFaUser
    {
        $user = $this->users->find($userId);
        if ($user === null || ! $user->isBound()) {
            throw new TwoFaNotBoundException();
        }

        return $user;
    }

    protected function verifyCode(int|string $userId, string $secret, string $code): bool
    {
        $code = trim($code);
        if ($code === '' || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        if ($this->isCodeReused($userId, $code)) {
            return false;
        }

        $ok = $this->google2fa->verifyKey($secret, $code);
        if ($ok) {
            $this->rememberUsedCode($userId, $code);
        }

        return (bool) $ok;
    }

    protected function isCodeReused(int|string $userId, string $code): bool
    {
        return (bool) $this->redis()->exists($this->usedCodeKey($userId, $code));
    }

    protected function rememberUsedCode(int|string $userId, string $code): void
    {
        $ttl = max(30, (int) $this->config->get('two_fa.code_reuse_ttl', 90));
        $this->redis()->setex($this->usedCodeKey($userId, $code), $ttl, '1');
    }

    protected function assertNotRateLimited(int|string $userId): void
    {
        $max = (int) $this->config->get('two_fa.rate_limit.max_attempts', 5);
        $attempts = (int) $this->redis()->get($this->failKey($userId));
        if ($attempts >= $max) {
            $retry = (int) $this->redis()->ttl($this->failKey($userId));
            throw new TwoFaRateLimitedException(max(1, $retry));
        }
    }

    protected function hitRateLimit(int|string $userId): void
    {
        $key = $this->failKey($userId);
        $decay = max(60, (int) $this->config->get('two_fa.rate_limit.decay_seconds', 300));
        $count = (int) $this->redis()->incr($key);
        if ($count === 1) {
            $this->redis()->expire($key, $decay);
        }
    }

    protected function clearRateLimit(int|string $userId): void
    {
        $this->redis()->del($this->failKey($userId));
    }

    protected function pendingKey(int|string $userId): string
    {
        return $this->prefix() . 'pending:' . $userId;
    }

    protected function usedCodeKey(int|string $userId, string $code): string
    {
        return $this->prefix() . 'used:' . $userId . ':' . $code;
    }

    protected function failKey(int|string $userId): string
    {
        return $this->prefix() . 'fail:' . $userId;
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
