<?php

namespace Idle;

use TSF\Db\Col;
use TSF\Db\Id;
use TSF\Db\Table;

/**
 * 挂机玩家。
 *
 * 主键是 uid 且**由业务分配**（`#[Id]` 不带 auto）—— 这是分片的硬要求：
 * Repo 用主键算分片，自增主键会在各分片算出重复 id 写到不同库。
 *
 * 只有 `last_tick` 一个时间字段，**不存"待领取"**：待领取永远按
 * `now - last_tick` 现算。
 */
#[Table("idle_player")]
final class IdlePlayer
{
    #[Id]
    public int $uid = 0;

    #[Col("name")]
    public string $name = "";

    #[Col("coins")]
    public int $coins = 0;

    /** 上次结算到的时间点（unix 秒） */
    #[Col("last_tick")]
    public int $lastTick = 0;

    #[Col("created_at")]
    public int $createdAt = 0;
}
