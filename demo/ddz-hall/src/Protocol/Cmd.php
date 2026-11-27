<?php

namespace Ddz\Protocol;

/**
 * 消息 ID。
 *
 * 网络 ID（1xxx）和内部 ID（2xxx）分开编号：内部消息永远不出进程，
 * 改它们不影响客户端。
 */
final class Cmd
{
    // ---- 客户端 → 服务端 ----
    public const LIST_REQ = 1001;

    public const CREATE_REQ = 1003;

    public const JOIN_REQ = 1005;

    public const LEAVE_REQ = 1007;

    public const PLAY_REQ = 1009;

    public const PASS_REQ = 1011;

    public const READY_REQ = 1013;

    public const START_REQ = 1015;

    public const BID_REQ = 1017;

    public const AGAIN_REQ = 1019;

    // ---- 服务端 → 客户端（应答）----
    public const LIST_RESP = 1002;

    public const CREATE_RESP = 1004;

    public const JOIN_RESP = 1006;

    public const ERR_RESP = 1008;

    public const PLAY_RESP = 1010;

    public const READY_RESP = 1014;

    public const START_RESP = 1016;

    public const BID_RESP = 1018;

    public const AGAIN_RESP = 1020;

    // ---- 服务端 → 客户端（推送）。放 11xx，跟请求/应答的 10xx 分开 ----
    public const PUSH_STATE = 1100;

    public const PUSH_DEAL = 1101;

    public const PUSH_PLAY = 1102;

    public const PUSH_OVER = 1103;

    /** 轮到谁叫分 */
    public const PUSH_BID = 1104;

    /** 叫分结束：谁当地主、底牌是什么 */
    public const PUSH_BID_RESULT = 1105;

    // ---- 内部消息 ----
    public const SVC_LOBBY = 2001;

    public const SVC_ROOM = 2002;

    /** 房间 → 大厅：这局结束了，把我从列表里摘掉 */
    public const SVC_ROOM_CLOSED = 2003;
}
