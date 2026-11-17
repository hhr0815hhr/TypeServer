<?php

namespace Mmo;

/**
 * 协议号。2xxx 段属于 mmo-mini（斗地主用 11xx/12xx）。
 */
final class Msg
{
    // 客户端 → 服务端
    public const JOIN = 2101;   // 进房间 {"name":"甲"}
    public const MOVE = 2102;   // 移动方向 {"dx":1,"dy":0}（-1/0/1）
    public const LEAVE = 2103;  // 离开

    // 服务端 → 客户端
    public const WELCOME = 2201; // {"pid":1,"x":100,"y":100,"mapW":2000,"mapH":2000,"cell":200,"frameMs":50}
    public const SYNC = 2202;    // {"self":{...},"add":[...],"upd":[...],"del":[pid,...]}
    public const GONE = 2203;    // {"pid":3} 某人离开
}
