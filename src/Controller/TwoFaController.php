<?php

declare(strict_types=1);

namespace Goletter\TwoFa\Controller;

use Goletter\TwoFa\Contract\UserIdResolverInterface;
use Goletter\TwoFa\Exception\TwoFaException;
use Goletter\TwoFa\Exception\TwoFaInvalidCodeException;
use Goletter\TwoFa\Exception\TwoFaNotBoundException;
use Goletter\TwoFa\Exception\TwoFaRateLimitedException;
use Goletter\TwoFa\Service\TwoFaService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * 可选 HTTP 入口：绑定 / 确认 / 模块验证 / 状态。
 *
 * 需已登录（UserIdResolver 能解析到 user_id）。可按项目需要复制改造，不必启用本控制器。
 */
#[Controller(prefix: '/two-fa')]
class TwoFaController
{
    public function __construct(
        protected TwoFaService $twoFa,
        protected UserIdResolverInterface $userIdResolver,
        protected RequestInterface $request,
        protected HttpResponse $response,
    ) {
    }

    #[PostMapping(path: 'bind/begin')]
    public function beginBind(): ResponseInterface
    {
        try {
            $userId = $this->requireUserId();
            $account = (string) $this->request->input('account', (string) $userId);
            $data = $this->twoFa->beginBind($userId, $account);

            return $this->response->json(['code' => 0, 'data' => $data]);
        } catch (TwoFaException $e) {
            return $this->error($e);
        }
    }

    #[PostMapping(path: 'bind/confirm')]
    public function confirmBind(): ResponseInterface
    {
        try {
            $userId = $this->requireUserId();
            $code = (string) $this->request->input('code', '');
            $user = $this->twoFa->confirmBind($userId, $code);

            return $this->response->json([
                'code' => 0,
                'data' => [
                    'user_id' => $user->userId,
                    'enabled' => $user->enabled,
                ],
            ]);
        } catch (TwoFaException $e) {
            return $this->error($e);
        }
    }

    #[PostMapping(path: 'verify')]
    public function verify(): ResponseInterface
    {
        try {
            $userId = $this->requireUserId();
            $module = (string) $this->request->input('module', 'default');
            $code = (string) $this->request->input('code', '');
            $this->twoFa->verifyModule($userId, $module, $code);

            return $this->response->json([
                'code' => 0,
                'data' => $this->twoFa->status($userId, $module),
            ]);
        } catch (TwoFaException $e) {
            return $this->error($e);
        }
    }

    #[GetMapping(path: 'status')]
    public function status(): ResponseInterface
    {
        try {
            $userId = $this->requireUserId();
            $module = (string) $this->request->input('module', 'default');

            return $this->response->json([
                'code' => 0,
                'data' => $this->twoFa->status($userId, $module),
            ]);
        } catch (TwoFaException $e) {
            return $this->error($e);
        }
    }

    #[PostMapping(path: 'disable')]
    public function disable(): ResponseInterface
    {
        try {
            $userId = $this->requireUserId();
            $code = (string) $this->request->input('code', '');
            $this->twoFa->disable($userId, $code);

            return $this->response->json(['code' => 0, 'message' => 'disabled']);
        } catch (TwoFaException $e) {
            return $this->error($e);
        }
    }

    protected function requireUserId(): int|string
    {
        $userId = $this->userIdResolver->resolve($this->request);
        if ($userId === null || $userId === '') {
            throw new TwoFaException('Unauthenticated', 401);
        }

        return $userId;
    }

    protected function error(TwoFaException $e): ResponseInterface
    {
        $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422;
        $payload = [
            'code' => $status,
            'message' => $e->getMessage(),
        ];

        if ($e instanceof TwoFaRateLimitedException) {
            $payload['retry_after'] = $e->retryAfter;
        }
        if ($e instanceof TwoFaNotBoundException) {
            $payload['two_fa_bind_required'] = true;
        }
        if ($e instanceof TwoFaInvalidCodeException) {
            $payload['invalid_code'] = true;
        }

        return $this->response->json($payload)->withStatus($status);
    }
}
