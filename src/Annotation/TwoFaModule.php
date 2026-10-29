<?php

declare(strict_types=1);

namespace Goletter\TwoFa\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * 声明接口所属 2FA 模块（用于中间件读取）。
 *
 * ```php
 * #[TwoFaModule('wallet')]
 * public function transfer() {}
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class TwoFaModule extends AbstractAnnotation
{
    public function __construct(public string $module = 'default')
    {
    }
}
