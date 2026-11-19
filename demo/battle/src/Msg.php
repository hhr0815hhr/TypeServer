<?php

namespace Game;

/**
 * 协议号（6xxx 段）与载荷编解码。
 *
 * 载荷用 JSON 而不是框架的 payload codec，原因有两个：
 *   1. 战斗同步的推送是"一次编码、多人广播"，编码时机必须由业务掌握；
 *      走 Gateway 的 payloadCodec 会变成"每人一次 pack"。
 *   2. 压测阶段要能直接看到线上字节长什么样，JSON 肉眼可读。
 *
 * 代价是体积和解析成本（约为二进制的 2~4 倍）。这是**明确的取舍**：
 * 本工程要验证的是分层边界与 AOI/结算的性能拐点，不是协议层极限。
 * 真要上生产，这一层换成二进制帧即可，上层代码不动。
 */
final class Msg
{
    // C → S
    public const ENTER = 6001;

    public const MOVE = 6002;

    public const CAST = 6003;

    public const LEAVE = 6004;

    // S → C
    public const ENTER_OK = 6100;

    public const SNAPSHOT = 6300;

    public const DAMAGE = 6301;

    public const DEATH = 6302;

    public const REJECT = 6303;

    /**
     * 解析客户端载荷。
     *
     * 返回空数组表示"形状不对"，由调用方当协议误用处理——
     * 不要让一个脏包把异常抛到 Gateway 里，那里只能回一个笼统的 ERR。
     *
     * @return array<string, int|string>
     */
    public static function params(string $body): array
    {
        if ($body === "") {
            return [];
        }
        $raw = json_decode($body, true);
        if (!is_array($raw)) {
            return [];
        }
        return $raw;
    }

    /** @param array<string, int|string|array> $data */
    public static function body(array $data): string
    {
        $s = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($s)) {
            return "{}";
        }
        return $s;
    }
}
