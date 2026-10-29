<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    // Authenticator 显示的发行方名称
    'issuer' => env('TWO_FA_ISSUER', 'App'),

    // TOTP 时间窗（±N 个 30s 周期）
    'window' => (int) env('TWO_FA_WINDOW', 1),

    // secret 长度：google2fa v9 默认 32；需要兼容旧 16 时可改
    'secret_length' => (int) env('TWO_FA_SECRET_LENGTH', 32),

    // 绑定确认前 pending secret 存活时间（秒）
    'pending_ttl' => (int) env('TWO_FA_PENDING_TTL', 600),

    // 同一验证码短时防重放（秒）
    'code_reuse_ttl' => (int) env('TWO_FA_CODE_REUSE_TTL', 90),

    // 验证失败限流
    'rate_limit' => [
        'max_attempts' => (int) env('TWO_FA_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('TWO_FA_DECAY_SECONDS', 300),
    ],

    'redis' => [
        'pool' => env('TWO_FA_REDIS_POOL', 'default'),
        'prefix' => env('TWO_FA_REDIS_PREFIX', 'two_fa:'),
    ],

    /*
     * 模块空闲再验策略。
     * ttl：该模块验证通过后，超过多少秒无操作需再次输入验证码（滑动窗口，touch 会刷新）。
     */
    'modules' => [
        'default' => [
            'ttl' => (int) env('TWO_FA_DEFAULT_TTL', 3600),
        ],
        // 'wallet' => ['ttl' => 900],
        // 'admin' => ['ttl' => 1800],
    ],

    /*
     * 用户未启用 2FA 时中间件行为：
     * - reject：返回 403
     * - skip：放行（仅对已启用用户做校验）
     * - require_bind：返回 428，提示先绑定
     */
    'unbound' => env('TWO_FA_UNBOUND', 'require_bind'),

    // 从请求解析 user_id 的 attribute 名（按顺序）
    'user_id_attributes' => ['user_id', 'authenticated_user_id'],
];
