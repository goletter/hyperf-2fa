<?php

declare(strict_types=1);

namespace Goletter\TwoFa\Middleware;

use Goletter\TwoFa\Annotation\TwoFaModule;
use Goletter\TwoFa\Contract\UserIdResolverInterface;
use Goletter\TwoFa\Exception\TwoFaNotBoundException;
use Goletter\TwoFa\Exception\TwoFaRequiredException;
use Goletter\TwoFa\Service\TwoFaService;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Hyperf\HttpServer\Router\Dispatched;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 按模块校验 2FA：未验证或空闲超时则拦截。
 *
 * 模块名解析顺序：
 * 1. 请求 attribute `two_fa_module`
 * 2. 控制器/方法上的 #[TwoFaModule]
 * 3. 默认 `default`
 *
 * 未绑定行为见配置 `two_fa.unbound`：reject | skip | require_bind
 */
class TwoFaModuleMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected TwoFaService $twoFa,
        protected UserIdResolverInterface $userIdResolver,
        protected ConfigInterface $config,
        protected HttpResponse $response,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $userId = $this->userIdResolver->resolve($request);
        if ($userId === null || $userId === '') {
            return $this->response->json([
                'code' => 401,
                'message' => 'Unauthenticated',
            ])->withStatus(401);
        }

        $module = $this->resolveModule($request);

        if (! $this->twoFa->isBound($userId)) {
            $mode = (string) $this->config->get('two_fa.unbound', 'require_bind');
            if ($mode === 'skip') {
                return $handler->handle($request);
            }

            $message = $mode === 'reject'
                ? 'Two-factor authentication is required but not bound.'
                : 'Two-factor authentication is not bound.';

            return $this->response->json([
                'code' => 428,
                'message' => $message,
                'two_fa_bind_required' => true,
            ])->withStatus(428);
        }

        try {
            $this->twoFa->assertModuleVerified($userId, $module);
        } catch (TwoFaRequiredException $e) {
            return $this->response->json([
                'code' => $e->getCode() ?: 428,
                'message' => $e->getMessage(),
                'module' => $e->module,
                'two_fa_required' => true,
            ])->withStatus($e->getCode() ?: 428);
        } catch (TwoFaNotBoundException $e) {
            return $this->response->json([
                'code' => $e->getCode() ?: 428,
                'message' => $e->getMessage(),
                'two_fa_bind_required' => true,
            ])->withStatus($e->getCode() ?: 428);
        }

        return $handler->handle($request);
    }

    protected function resolveModule(ServerRequestInterface $request): string
    {
        $fromAttr = $request->getAttribute('two_fa_module');
        if (is_string($fromAttr) && $fromAttr !== '') {
            return $fromAttr;
        }

        $dispatched = $request->getAttribute(Dispatched::class);
        if ($dispatched instanceof Dispatched && isset($dispatched->handler->callback)) {
            $callback = $dispatched->handler->callback;
            if (is_array($callback) && count($callback) >= 2) {
                [$class, $method] = $callback;
                if (is_string($class) && is_string($method)) {
                    $methodAnn = AnnotationCollector::getClassMethodAnnotation($class, $method);
                    if (isset($methodAnn[TwoFaModule::class]) && $methodAnn[TwoFaModule::class] instanceof TwoFaModule) {
                        return $methodAnn[TwoFaModule::class]->module;
                    }

                    $classAnn = AnnotationCollector::getClassAnnotation($class, TwoFaModule::class);
                    if ($classAnn instanceof TwoFaModule) {
                        return $classAnn->module;
                    }
                }
            }
        }

        return 'default';
    }
}
