<?php

namespace Ddz\Protocol;

/**
 * 投给房间的内部消息。
 *
 * op 的取值见下方常量。`cards` 是出牌请求里的牌 id 列表，其他 op 为空。
 */
final class RoomTask
{
    public const OP_JOIN = 1;

    public const OP_LEAVE = 2;

    public const OP_PLAY = 3;

    public const OP_PASS = 4;

    public const OP_READY = 5;

    public const OP_START = 6;

    public const OP_BID = 7;

    public const OP_AGAIN = 8;

    public const OP_DISCONNECT = 9;

    /** 断线超时后由定时器投递，用来检查"还离线吗" */
    public const OP_KICK = 10;

    public int $op = 0;

    public int $fd = 0;

    public int $uid = 0;

    /** @var int[] 出牌的牌 id */
    public array $cards = [];

    /** 叫分（op=OP_BID 时用），0 表示不叫 */
    public int $score = 0;
}
