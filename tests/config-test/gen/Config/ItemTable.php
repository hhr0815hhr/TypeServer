<?php

namespace Gen\Config;

use TSF\Config\Reader;

/**
 * item 表的加载与查询。
 *
 * **自动生成，不要手改**。数据在 build/config/item.bin，运行期读文件——
 * 所以改数值不需要重新编译（这是 T6-02 热更的前提）。
 */
final class ItemTable
{
    /**
     * 本代码认识的列（名字:类型，分号分隔）。
     *
     * 运行期会拿它和数据文件的字段清单逐列比对：
     * 前 N 列必须一致，**文件里多出来的尾列会被忽略**——
     * 所以策划在末尾追加一列时，重新导出数据就够了，不用重编程序。
     */
    public const SCHEMA = "id:int(1..9999);itemName:string(1..16);quality:int(1..5);attack:int(0..999);speed:float(0..9.9);price:int(0..999999);stackable:bool;desc:string(0..64);tags:int[]";

    public const BIN_NAME = "item.bin";

    /** @var array<int, Item> */
    private array $byKey = [];

    /** @var Item[] */
    private array $rows = [];

    /**
     * 读二进制。返回行数。
     *
     * 重复 load 会整体替换（热更要的就是这个语义，不能把旧行留着）。
     */
    public function load(string $path): int
    {
        $r = Reader::open($path, "item", self::SCHEMA);
        $byKey = [];
        $rows = [];
        while ($r->next()) {
            $o = new Item();
            $o->id = $r->int();
            $o->itemName = $r->str();
            $o->quality = $r->int();
            $o->attack = $r->int();
            $o->speed = $r->float();
            $o->price = $r->int();
            $o->stackable = $r->bool();
            $o->desc = $r->str();
            $o->tags = $r->ints();
            $byKey[$o->id] = $o;
            $rows[] = $o;
        }
        $this->byKey = $byKey;
        $this->rows = $rows;
        return count($rows);
    }

    public function get(int $id): ?Item
    {
        return $this->byKey[$id] ?? null;
    }

    /** @return Item[] */
    public function all(): array
    {
        return $this->rows;
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
