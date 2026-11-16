<?php

namespace Duel;

/**
 * 对战协议的消息号。
 *
 * 5xxx 段是本示例专用；1xxx 段是框架保留（PING/PONG 等）。
 * 双向分开编号：5xxx 客户端发起，51xx/53xx 服务端推送，方便抓包时一眼看出方向。
 */
final class Msg
{
    /** 客户端 → 服务端：入匹配队列 */
    public const MATCH_JOIN = 5001;

    /** 客户端 → 服务端：取消匹配 */
    public const MATCH_CANCEL = 5002;

    /** 服务端 → 客户端：匹配成功（房间号、对手、自己的起始位置） */
    public const MATCH_FOUND = 5100;

    /** 客户端 → 服务端：设置移动方向 {dir: -1|0|1} */
    public const MOVE = 5201;

    /** 客户端 → 服务端：攻击（无载荷） */
    public const ATTACK = 5202;

    /** 服务端 → 客户端：状态快照（每 200 ms） */
    public const STATE = 5300;

    /** 服务端 → 客户端：结算（winner：uid，-1 平局） */
    public const RESULT = 5301;
}
