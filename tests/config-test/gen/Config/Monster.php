<?php

namespace Gen\Config;

/**
 * 配置行：monster（源 config/tables/monster.xlsx）
 *
 * **自动生成，不要手改**——改了下次 tsf config build 就没了。
 * 要加字段就改 Excel 的类型行。
 */
final class Monster
{
    public int $id = 0;

    public string $monsterName = "";

    public int $hp = 0;

    /** 外键 → item（存的是键值） */
    public string $dropItem = "";

    /** 外键数组 → item */
    public array $dropItems = [];
}
