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

    // ---- 服务端 → 客户端（应答）----
    public const LIST_RESP = 1002;

    public const CREATE_RESP = 1004;

    public const JOIN_RESP = 1006;

    public const ERR_RESP = 1008;

    public const PLAY_RESP = 1010;

    // ---- 服务端 → 客户端（推送）----
    public const PUSH_STATE = 1020;

    public const PUSH_DEAL = 1021;

    public const PUSH_PLAY = 1022;

    public const PUSH_OVER = 1023;

    // ---- 内部消息 ----
    public const SVC_LOBBY = 2001;

    public const SVC_ROOM = 2002;

    /** 房间 → 大厅：这局结束了，把我从列表里摘掉 */
    public const SVC_ROOM_CLOSED = 2003;
}
