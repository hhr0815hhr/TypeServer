<?php

namespace Gen\Config;

use TSF\Config\Reader;

/**
 * monster 表的加载与查询。
 *
 * **自动生成，不要手改**。数据在 build/config/monster.bin，运行期读文件——
 * 所以改数值不需要重新编译（这是 T6-02 热更的前提）。
 */
final class MonsterTable
{
    /**
     * 本代码认识的列（名字:类型，分号分隔）。
     *
     * 运行期会拿它和数据文件的字段清单逐列比对：
     * 前 N 列必须一致，**文件里多出来的尾列会被忽略**——
     * 所以策划在末尾追加一列时，重新导出数据就够了，不用重编程序。
     */
    public const SCHEMA = "id:int;monsterName:string(1..16);hp:int(1..99999);dropItem:ref:item;dropItems:ref:item[]";

    public const BIN_NAME = "monster.bin";

    /** @var array<int, Monster> */
    private array $byKey = [];

    /** @var Monster[] */
    private array $rows = [];

    /**
     * 读二进制。返回行数。
     *
     * 重复 load 会整体替换（热更要的就是这个语义，不能把旧行留着）。
     */
    public function load(string $path): int
    {
        $r = Reader::open($path, "monster", self::SCHEMA);
        $byKey = [];
        $rows = [];
        while ($r->next()) {
            $o = new Monster();
            $o->id = $r->int();
            $o->monsterName = $r->str();
            $o->hp = $r->int();
            $o->dropItem = $r->ref();
            $o->dropItems = $r->refs();
            $byKey[$o->id] = $o;
            $rows[] = $o;
        }
        $this->byKey = $byKey;
        $this->rows = $rows;
        return count($rows);
    }

    public function get(int $id): ?Monster
    {
        return $this->byKey[$id] ?? null;
    }

    /** @return Monster[] */
    public function all(): array
    {
        return $this->rows;
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
