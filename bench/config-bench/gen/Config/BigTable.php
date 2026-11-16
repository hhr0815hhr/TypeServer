<?php

namespace Gen\Config;

use TSF\Config\Reader;

/**
 * big 表的加载与查询。
 *
 * **自动生成，不要手改**。数据在 build/config/big.bin，运行期读文件——
 * 所以改数值不需要重新编译（这是 T6-02 热更的前提）。
 */
final class BigTable
{
    /**
     * 本代码认识的列（名字:类型，分号分隔）。
     *
     * 运行期会拿它和数据文件的字段清单逐列比对：
     * 前 N 列必须一致，**文件里多出来的尾列会被忽略**——
     * 所以策划在末尾追加一列时，重新导出数据就够了，不用重编程序。
     */
    public const SCHEMA = "id:int;bigName:string(1..16);hp:int(1..999999);ratio:float(0..1);flag:bool;tags:int[];desc:string(0..40)";

    public const BIN_NAME = "big.bin";

    /** @var array<int, Big> */
    private array $byKey = [];

    /** @var Big[] */
    private array $rows = [];

    /**
     * 读二进制。返回行数。
     *
     * 重复 load 会整体替换（热更要的就是这个语义，不能把旧行留着）。
     */
    public function load(string $path): int
    {
        $r = Reader::open($path, "big", self::SCHEMA);
        $byKey = [];
        $rows = [];
        while ($r->next()) {
            $o = new Big();
            $o->id = $r->int();
            $o->bigName = $r->str();
            $o->hp = $r->int();
            $o->ratio = $r->float();
            $o->flag = $r->bool();
            $o->tags = $r->ints();
            $o->desc = $r->str();
            $byKey[$o->id] = $o;
            $rows[] = $o;
        }
        $this->byKey = $byKey;
        $this->rows = $rows;
        return count($rows);
    }

    public function get(int $id): ?Big
    {
        return $this->byKey[$id] ?? null;
    }

    /** @return Big[] */
    public function all(): array
    {
        return $this->rows;
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
