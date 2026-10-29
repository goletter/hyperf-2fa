<?php

declare(strict_types=1);

namespace Goletter\TwoFa\Contract;

use Psr\Http\Message\ServerRequestInterface;

/**
 * 从当前请求解析已登录用户 ID。
 */
interface UserIdResolverInterface
{
    public function resolve(ServerRequestInterface $request): int|string|null;
}
