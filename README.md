# Hyperf 2FA

基于 [`pragmarx/google2fa`](https://packagist.org/packages/pragmarx/google2fa) 的 Hyperf 二次验证组件：

- 绑定用户（TOTP / Google Authenticator）
- **按模块**验证（如 `wallet`、`admin`）
- 模块空闲超时后需**再次验证**（滑动窗口）

## 安装

```bash
composer require goletter/hyperf-2fa
php bin/hyperf.php vendor:publish goletter/hyperf-2fa
```

生产环境请绑定自己的用户仓储（默认是进程内内存，重启即丢）：

```php
// config/autoload/dependencies.php
use Goletter\TwoFa\Contract\TwoFaUserRepositoryInterface;
use App\TwoFa\DbTwoFaUserRepository;

return [
    TwoFaUserRepositoryInterface::class => DbTwoFaUserRepository::class,
];
```

## 配置

`config/autoload/two_fa.php`：

```php
return [
    'issuer' => env('TWO_FA_ISSUER', 'App'),
    'window' => 1,
    'modules' => [
        'default' => ['ttl' => 3600],   // 1 小时无操作需再验
        'wallet'  => ['ttl' => 900],    // 15 分钟
        'admin'   => ['ttl' => 1800],
    ],
    // 未绑定：require_bind | skip | reject
    'unbound' => 'require_bind',
];
```

| 配置 | 说明 |
|------|------|
| `modules.*.ttl` | 该模块验证通过后，多久无操作需再次输入验证码 |
| `unbound` | 用户未启用 2FA 时中间件行为 |
| `rate_limit` | 验证码错误次数限制 |
| `pending_ttl` | 绑定确认前 pending secret 有效期 |

## 业务流程

### 1. 绑定

```php
use Goletter\TwoFa\Service\TwoFaService;

// 开始绑定 → 把 otpauth_url 做成二维码给用户扫
$data = $twoFa->beginBind($userId, $userEmail);
// ['secret' => '...', 'otpauth_url' => 'otpauth://totp/...', ...]

// 用户输入 App 里的 6 位码确认
$twoFa->confirmBind($userId, $code);
```

可选 HTTP（需登录态能解析出 `user_id`）：

- `POST /two-fa/bind/begin`
- `POST /two-fa/bind/confirm` `{ "code": "123456" }`

二维码可用 `pragmarx/google2fa-qrcode`，或前端用 `otpauth_url` 自行生成。

### 2. 模块验证

```php
// 用户提交验证码，打通某模块
$twoFa->verifyModule($userId, 'wallet', $code);

// 之后业务请求：未超时则放行并刷新活跃时间
$twoFa->assertModuleVerified($userId, 'wallet');

// 查看状态
$twoFa->status($userId, 'wallet');
```

HTTP：`POST /two-fa/verify` `{ "module": "wallet", "code": "123456" }`

### 3. 中间件保护接口

```php
use Goletter\TwoFa\Annotation\TwoFaModule;
use Goletter\TwoFa\Middleware\TwoFaModuleMiddleware;
use Hyperf\HttpServer\Annotation\Middleware;

#[Middleware(TwoFaModuleMiddleware::class)]
#[TwoFaModule('wallet')]
class WalletController
{
    public function transfer()
    {
        // 已通过 wallet 模块 2FA，且未超过空闲 TTL
    }
}
```

未验证或空闲超时返回 **428**，body 含 `two_fa_required` / `module`，前端弹验证码后再调 `/two-fa/verify`。

也可在上游中间件设置 `$request = $request->withAttribute('two_fa_module', 'wallet')`。

## 用户 ID 解析

默认从 Request / Context 的 `user_id`、`authenticated_user_id` 读取。登录中间件需先写入：

```php
$request = $request->withAttribute('user_id', $uid);
// 或 Context::set('user_id', $uid);
```

可自行实现 `UserIdResolverInterface` 对接 CAS / JWT。

## 仓储示例

```php
use Goletter\TwoFa\Contract\TwoFaUserRepositoryInterface;
use Goletter\TwoFa\TwoFaUser;

class DbTwoFaUserRepository implements TwoFaUserRepositoryInterface
{
    public function find(int|string $userId): ?TwoFaUser
    {
        $row = UserTwoFa::query()->where('user_id', $userId)->first();
        if (! $row) {
            return new TwoFaUser($userId, null, false);
        }

        return new TwoFaUser($userId, decrypt($row->secret), (bool) $row->enabled);
    }

    public function save(int|string $userId, string $secret, bool $enabled): void
    {
        UserTwoFa::query()->updateOrCreate(
            ['user_id' => $userId],
            ['secret' => encrypt($secret), 'enabled' => $enabled]
        );
    }

    public function disable(int|string $userId): void
    {
        UserTwoFa::query()->where('user_id', $userId)->update([
            'enabled' => false,
            'secret' => '',
        ]);
    }
}
```

## 组件说明

| 类 | 作用 |
|----|------|
| `TwoFaService` | 绑定、确认、模块验证、状态、解绑 |
| `TwoFaModuleMiddleware` | 按模块拦截 |
| `TwoFaSessionStoreInterface` | Redis 记录各模块验证与最后活跃时间 |
| `TwoFaUserRepositoryInterface` | 持久化用户 secret（业务实现） |
| `TwoFaController` | 可选 HTTP API |

未列出的能力可直接注入 `TwoFaService` 自行编排。
