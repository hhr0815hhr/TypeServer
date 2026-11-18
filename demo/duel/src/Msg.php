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

    /**
     * 客户端 → 服务端：重连后找回自己那一局。
     *
     * 断线不立刻判负（宽限期内重连不算输），所以客户端重连后要主动说一句
     * "我是谁、把我放回我的局里"。服务端据 uid 查 `DuelManager::$duelOf`。
     */
    public const REJOIN = 5003;

    /** 服务端 → 客户端：匹配成功（房间号、对手、自己的起始位置） */
    public const MATCH_FOUND = 5100;

    /** 服务端 → 客户端：重连成功（room/foe/side），之后的帧推送会自然续上 */
    public const REJOIN_OK = 5101;

    /** 客户端 → 服务端：设置移动方向 {dir: -1|0|1} */
    public const MOVE = 5201;

    /** 客户端 → 服务端：攻击（无载荷） */
    public const ATTACK = 5202;

    /** 服务端 → 客户端：状态快照（每 200 ms） */
    public const STATE = 5300;

    /** 服务端 → 客户端：结算（winner：uid，-1 平局） */
    public const RESULT = 5301;

    /**
     * 服务端 → 客户端：一句话通知（{code, text}）。
     *
     * 存在的理由是"拒绝必须是可见的"：本服 drain（正在退役）时不再建新房，
     * 如果只是静默不配对，玩家会以为是卡了、反复点匹配。
     * 用一条通用通知而不是给每种拒绝各开一个号——玩家不需要区分原因，
     * 排查的人看服务端日志。
     */
    public const NOTICE = 5400;
}
