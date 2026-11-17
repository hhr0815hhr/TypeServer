<?php

namespace Ddz;

/**
 * 协议号。业务消息从 1100 起，避开框架的标准消息（LOGIN=1、PING=100、ERR=400）。
 */
final class Msg
{
    // 客户端 → 服务端
    public const SEAT = 1101;       // 坐桌 {"seat":0}
    public const READY = 1102;      // 准备 {}
    public const CALL = 1103;       // 叫地主 {"call":true}
    public const PLAY = 1104;       // 出牌 {"cards":[0,4,8]}
    public const PASS = 1105;       // 过 {}
    public const LEAVE = 1106;      // 离桌 {}

    // 服务端 → 客户端
    public const TABLE = 1201;      // 桌面状态（谁在桌上、状态、轮到谁）
    public const HAND = 1202;       // 自己的手牌（只发给本人）
    public const TURN = 1203;       // 轮到你了 {"mustLead":bool}
    public const PLAYED = 1204;     // 某座位出牌 {"seat":0,"cards":[],"type":"顺子"}
    public const PASSED = 1205;     // 某座位过牌
    public const SETTLED = 1206;    // 结算
    public const ERROR = 1207;      // 操作被拒 {"why":"..."}
}
