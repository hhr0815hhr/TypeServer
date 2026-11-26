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

    public int $op = 0;

    public int $fd = 0;

    public int $uid = 0;

    /** @var int[] 出牌的牌 id */
    public array $cards = [];
}
