<?php

declare(strict_types=1);

namespace Goletter\TwoFa\Store;

use Goletter\TwoFa\Contract\UserIdResolverInterface;
use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 从 Request attribute / Context 解析 user_id。
 *
 * 业务登录中间件应先写入 `user_id` 或 `authenticated_user_id`。
 */
class RequestAttributeUserIdResolver implements UserIdResolverInterface
{
    public function __construct(protected ConfigInterface $config)
    {
    }

    public function resolve(ServerRequestInterface $request): int|string|null
    {
        $keys = $this->config->get('two_fa.user_id_attributes', ['user_id', 'authenticated_user_id']);
        if (! is_array($keys)) {
            $keys = ['user_id', 'authenticated_user_id'];
        }

        foreach ($keys as $key) {
            $value = $request->getAttribute((string) $key);
            if ($value !== null && $value !== '') {
                return is_numeric($value) ? (int) $value : (string) $value;
            }
        }

        foreach ($keys as $key) {
            $value = Context::get((string) $key);
            if ($value !== null && $value !== '') {
                return is_numeric($value) ? (int) $value : (string) $value;
            }
        }

        return null;
    }
}
