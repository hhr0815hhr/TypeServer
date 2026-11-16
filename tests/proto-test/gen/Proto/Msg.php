<?php

namespace Gen\Proto;

/**
 * 协议号常量。**自动生成，不要手改**。
 *
 * 数字来自 .proto 里每个 message 上方的 `// @id N` 注释。
 */
final class Msg
{
    public const VECTOR = 1001;
    public const STR_MSG = 1002;
    public const NEG_MSG = 1003;
    public const FLOAT_MSG = 1004;
    public const PACKED_MSG = 1005;
    public const ITEM = 1010;
    public const BAG = 1011;
    public const EVERYTHING = 1012;

    /** 数字 → 名字，日志/抓包用 */
    public static function name(int $msgId): string
    {
        $m = self::all();
        return $m[$msgId] ?? ("#" . $msgId);
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        return [
            1001 => "Vector",
            1002 => "StrMsg",
            1003 => "NegMsg",
            1004 => "FloatMsg",
            1005 => "PackedMsg",
            1010 => "Item",
            1011 => "Bag",
            1012 => "Everything",
        ];
    }
}
