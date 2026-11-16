<?php

/**
 * T6-01 验证：配置表导出 → AOT 编译 → 运行期加载。
 *
 * 这里要证明的不是"能读到数"，而是四件在真实项目里会咬人的事：
 *   1. 带类型的往返：int / float / bool / string / int[] 都不走样（尤其 float）
 *   2. 空字符串 vs 缺失要能区分（策划真的会留空描述）
 *   3. **代码和数据不是一批生成的** 要被拦住，而且错误信息要指出怎么修
 *   4. 外键存的是键值，业务侧能顺着查过去
 */

use Gen\Config\ItemTable;
use Gen\Config\Tables;
use TSF\Config\Reader;

final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $label, string $detail = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  OK   " . $label . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL " . $label . ($detail === "" ? "" : "  → " . $detail) . PHP_EOL;
        }
    }

    public static function eq(int|string|float|bool $got, int|string|float|bool $want, string $label): void
    {
        self::ok($got === $want, $label, "got=" . var_export($got, true) . " want=" . var_export($want, true));
    }

    public static function eqArr(array $got, array $want, string $label): void
    {
        $same = count($got) === count($want);
        if ($same) {
            $i = 0;
            foreach ($want as $v) {
                if (!isset($got[$i]) || $got[$i] !== $v) {
                    $same = false;
                    break;
                }
                $i++;
            }
        }
        self::ok($same, $label, "got=[" . implode(",", $got) . "] want=[" . implode(",", $want) . "]");
    }
}

function main(): void
{
    $args = $_SERVER["argv"] ?? [];
    $dir = isset($args[1]) ? (string) $args[1] : "build/config";

    echo "== [1] 加载 ==" . PHP_EOL;
    $counts = Tables::load($dir);
    T::eq($counts["item"] ?? -1, 4, "item 表 4 行");
    T::eq($counts["monster"] ?? -1, 2, "monster 表 2 行");

    $items = Tables::item();
    $mon = Tables::monster();

    echo "== [2] 类型往返 ==" . PHP_EOL;
    $w = $items->get(1001);
    if ($w === null) {
        T::ok(false, "get(1001) 不该是 null");
        echo "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
        exit(1);
    }
    T::eq($w->id, 1001, "int 主键");
    T::eq($w->itemName, "木剑", "string（中文）");
    T::eq($w->quality, 1, "int");
    T::eq($w->attack, 5, "int");
    T::eq($w->speed, 0.5, "float 0.5");
    T::eq($w->price, 100, "int");
    T::eq($w->stackable, true, "bool true（表里写 1）");
    T::eq($w->desc, "新手用的木剑", "string");
    T::eqArr($w->tags, [1, 2, 3], "int[]");

    // 浮点往返是最容易悄悄坏的地方：1.2 这类二进制不能精确表示的数，
    // 只要导出/读入任何一端用了低精度格式化，值就会偏
    $d = $items->get(1003);
    if ($d !== null) {
        T::eq($d->speed, 1.2, "float 1.2 精确往返");
        T::eq($d->stackable, false, "bool false（表里写 0）");
        T::eq($d->price, 99999, "大一点的 int");
    }

    $p = $items->get(1004);
    if ($p !== null) {
        T::eq($p->desc, "", "空描述读成空串（不是 null）");
        T::eqArr($p->tags, [1], "单元素数组");
    }

    echo "== [3] 查不到 / 顺序 ==" . PHP_EOL;
    T::eq($items->get(9999) === null, true, "不存在的键返回 null");
    T::eq($items->count(), 4, "count()");
    $all = $items->all();
    T::eq(count($all), 4, "all() 行数");
    T::eq($all[0]->id, 1001, "all() 保持 Excel 里的顺序");
    T::eq($all[3]->id, 1004, "all() 最后一行的 id");

    echo "== [4] 外键 ==" . PHP_EOL;
    $slime = $mon->get(1);
    if ($slime !== null) {
        T::eq($slime->monsterName, "史莱姆", "怪物名");
        T::eq($slime->hp, 50, "血量");
        T::eq($slime->dropItem, "1001", "单个外键存的是键值（字符串）");
        T::eqArr($slime->dropItems, ["1001", "1004"], "外键数组");
        // 顺着外键查过去——这才是外键的意义
        $target = $items->get((int) $slime->dropItem);
        T::ok($target !== null && $target->itemName === "木剑", "顺着外键能查到目标行");
    }

    echo "== [5] 代码和数据不是一批生成的 ==" . PHP_EOL;
    // 第 3 列写错（quality 的区间变了）
    $badSchema = "id:int(1..9999);itemName:string(1..16);quality:int(0..9);attack:int(0..999);"
        . "speed:float(0..9.9);price:int(0..999999);stackable:bool;desc:string(0..64);tags:int[]";
    $caught = "";
    try {
        Reader::open($dir . "/item.bin", "item", $badSchema);
    } catch (\RuntimeException $e) {
        $caught = $e->getMessage();
    }
    T::ok($caught !== "", "schema 对不上时抛异常");
    T::ok(str_contains($caught, "第 3 列"), "**能指出是第几列**", $caught);
    T::ok(str_contains($caught, "重新跑"), "错误信息指出怎么修", $caught);

    $caught2 = "";
    try {
        Reader::open($dir . "/item.bin", "monster", ItemTable::SCHEMA);
    } catch (\RuntimeException $e) {
        $caught2 = $e->getMessage();
    }
    T::ok(str_contains($caught2, "表名不符"), "读错文件能立刻发现", $caught2);

    $caught3 = "";
    try {
        Reader::open($dir . "/no_such.bin", "item", "");
    } catch (\RuntimeException $e) {
        $caught3 = $e->getMessage();
    }
    T::ok(str_contains($caught3, "读不到"), "文件不存在时说清楚路径", $caught3);

    echo "== [6] 末尾追加列：不用重编程序 ==" . PHP_EOL;
    // 模拟"策划在 monster 表末尾加了一列 dropRate，程序还是老版本"：
    // 老代码只认识前 4 列，第 5 列（dropItems）应当被跳过，数据照样读对
    $monSchema4 = "id:int;monsterName:string(1..16);hp:int(1..99999);dropItem:ref:item";
    $r4 = null;
    $caught4 = "";
    try {
        $r4 = Reader::open($dir . "/monster.bin", "monster", $monSchema4);
    } catch (\RuntimeException $e) {
        $caught4 = $e->getMessage();
    }
    T::eq($caught4, "", "只认识前 4 列也能打开（多出来的尾列被忽略）");
    if ($r4 !== null) {
        $extra = $r4->extraFields();
        T::eq(count($extra), 1, "认出 1 个未使用的尾列");
        T::eq($extra[0] ?? "", "dropItems:ref:item[]", "尾列就是 dropItems");

        $gotId = 0;
        $gotName = "";
        $gotHp = 0;
        $gotDrop = "";
        $n = 0;
        while ($r4->next()) {
            $gotId = $r4->int();
            $gotName = $r4->str();
            $gotHp = $r4->int();
            $gotDrop = $r4->ref();
            $n++;
        }
        T::eq($n, 2, "2 行都读到了（跳尾列没把行读歪）");
        T::eq($gotId, 2, "最后一行的 id");
        T::eq($gotName, "骷髅兵", "最后一行的名字");
        T::eq($gotHp, 180, "最后一行的血量");
        T::eq($gotDrop, "1002", "最后一行的外键");
    }

    echo "== [7] 中间插列 / 列数不足 ==" . PHP_EOL;
    // hp 的类型被改了（等价于中间换了一列）
    $midBad = "id:int;monsterName:string(1..16);hp:int;dropItem:ref:item";
    $caught5 = "";
    try {
        Reader::open($dir . "/monster.bin", "monster", $midBad);
    } catch (\RuntimeException $e) {
        $caught5 = $e->getMessage();
    }
    T::ok(str_contains($caught5, "第 3 列"), "中间列变了能报出位置", $caught5);

    // 代码要求的列比数据多
    $tooMany = "id:int;monsterName:string(1..16);hp:int(1..99999);dropItem:ref:item;"
        . "dropItems:ref:item[];notExists:int";
    $caught6 = "";
    try {
        Reader::open($dir . "/monster.bin", "monster", $tooMany);
    } catch (\RuntimeException $e) {
        $caught6 = $e->getMessage();
    }
    T::ok(str_contains($caught6, "只有 5 列"), "数据列数不足时说要重新导出", $caught6);

    echo "== [8] 重复 load 整体替换 ==" . PHP_EOL;
    // 热更的语义：再 load 一次必须**换掉**旧数据，而不是叠加
    $again = $items->load($dir . "/item.bin");
    T::eq($again, 4, "重复 load 后仍是 4 行");
    T::eq($items->count(), 4, "count() 没有翻倍");

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
    exit(T::$fail === 0 ? 0 : 1);
}
