<?php

namespace Game\Protocol;

/**
 * 协议号。
 *
 * 分两段：网络协议（客户端能看到）与内部消息（进程内 Service 之间）。
 * 混在一起编号是常见错误——后来内部消息加到 1003，和客户端协议撞了，
 * 症状是"客户端发 1003，服务端当成了内部消息"。
 */
final class Cmd
{
    // ---- 网络（客户端 ↔ 网关）----
    public const RAID_REQ = 1001;

    public const RAID_RESP = 1002;

    /** 活动脚本到点后主动推给客户端（timer 能力的落地） */
    public const MILESTONE = 1003;

    // ---- 内部（网关 → Service）----
    public const SVC_RAID = 1;

    /** 脚本 timer 到点回投给自己 */
    public const SVC_SCRIPT_TIMER = 2;
}
