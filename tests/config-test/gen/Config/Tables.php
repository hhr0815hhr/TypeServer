<?php

namespace Gen\Config;

/**
 * 全部配置表的入口。
 *
 * **自动生成，不要手改**。
 * 每张表一个静态属性 + 一个访问器：属性带类型，AOT 下比用数组装对象稳。
 */
final class Tables
{
    public static string $dir = "";

    public static ?ItemTable $item = null;

    public static ?MonsterTable $monster = null;

    /**
     * 加载全部表。返回 [表名 => 行数]。
     *
     * **先把所有表读进局部变量，全部成功后再一次性替换**：中途任何一张报错都不动现有数据。
     * 热更最怕的不是不生效，而是一半新一半旧——那种状态数据看着都正常，只是对不上。
     */
    public static function load(string $dir): array
    {
        $counts = [];
        $firstItem = new ItemTable();
        $counts["item"] = $firstItem->load($dir . "/item.bin");
        $firstMonster = new MonsterTable();
        $counts["monster"] = $firstMonster->load($dir . "/monster.bin");

        self::$item = $firstItem;
        self::$monster = $firstMonster;
        self::$dir = $dir;
        return $counts;
    }

    /**
     * 只重载指定的表（$files 是 .bin 文件名，来自 Watcher 的变更清单）。
     *
     * 按表重载而不是整批：一万行的表读一次要 60 ms 以上，改一张小表却把大表也重读一遍是白花时间。
     */
    public static function reload(array $files): array
    {
        $counts = [];
        if (in_array("item.bin", $files, true)) {
            $reItem = new ItemTable();
            $counts["item"] = $reItem->load(self::$dir . "/item.bin");
            self::$item = $reItem;
        }
        if (in_array("monster.bin", $files, true)) {
            $reMonster = new MonsterTable();
            $counts["monster"] = $reMonster->load(self::$dir . "/monster.bin");
            self::$monster = $reMonster;
        }
        return $counts;
    }

    public static function item(): ItemTable
    {
        $t = self::$item;
        if ($t === null) {
            throw new \RuntimeException("配置表 item 还没加载，先调 Tables::load()");
        }
        return $t;
    }

    public static function monster(): MonsterTable
    {
        $t = self::$monster;
        if ($t === null) {
            throw new \RuntimeException("配置表 monster 还没加载，先调 Tables::load()");
        }
        return $t;
    }
}
