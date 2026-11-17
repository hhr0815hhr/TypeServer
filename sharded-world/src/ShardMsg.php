<?php

namespace Shard;

/**
 * router 专用协议（3xxx 段）。
 *
 * 世界内的消息沿用 Mmo\Msg（2101/2102），因为 src/MmoService.php 是直接复用的。
 * 两个命名空间分开是为了不动复用的那份代码。
 */
final class ShardMsg
{
    public const WHERE = 3101;      // {"name":"甲"} 问「我该去哪个节点」
    public const GO = 3102;         // {"go":"ws://127.0.0.1:9501","node":1,"total":2}
    public const NO_NODE = 3103;    // {"why":"没有可用节点"}
}
