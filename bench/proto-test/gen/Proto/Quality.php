<?php

namespace Gen\Proto;

/**
 * 枚举 Quality。**自动生成，不要手改**。
 *
 * 生成成常量而不是 PHP 8.1 的 enum：协议字段在 wire 上就是 int32，
 * 用 int 存最直接，也不会在 AOT 的类型推断上多绕一层。
 */
final class Quality
{
    public const COMMON = 0;
    public const RARE = 1;
    public const EPIC = 2;

    /** @return array<int, string> */
    public static function all(): array
    {
        return [
            0 => "COMMON",
            1 => "RARE",
            2 => "EPIC",
        ];
    }
}
