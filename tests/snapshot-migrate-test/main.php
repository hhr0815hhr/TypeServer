<?php

use TSF\Snapshot\MemoryStore;
use TSF\Snapshot\Restorer;
use TSF\Snapshot\Schema;
use TSF\Snapshot\Snapshot;

/**
 * 状态版本迁移链回归。
 *
 * 这是 "T2 带状态迁移" 的核心：滚动重启前后，老二进制写的快照要能被新代码读懂。
 * 判据不是"函数跑通了"，而是四条纪律：
 *   1. 缺 `_v` 视为 0，走完整条链；
 *   2. 分区阈值稀疏也允许（v1→v4 没变就不写恒等函数）；
 *   3. 版本比代码新时**拒绝**（不降级读）；链缺一段也拒绝；
 *   4. 纯函数、幂等、不改入参——否则"重放两次结果不同"，排障时无法对拍。
 */

final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $name, string $extra = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  PASS  " . $name . ($extra !== "" ? "  (" . $extra . ")" : "") . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL  " . $name . ($extra !== "" ? "  (" . $extra . ")" : "") . PHP_EOL;
        }
    }
}

/** 收集恢复结果。用对象持状态：AOT 下闭包按引用捕获标量不可靠 */
final class Seen
{
    /** @var array<int, array> */
    public array $blocks = [];
}

/** 造一个"当前版本 = 7"的 schema：v5 加了 hp，v7 把 gold 改名 coins */
function schema7(): Schema
{
    $s = new Schema(7);
    $s->step(5, function (array $st): array {
        $st["hp"] = $st["hp"] ?? 100;      // 老快照里没有 hp
        return $st;
    });
    $s->step(7, function (array $st): array {
        $st["coins"] = $st["gold"] ?? 0;   // 改名：先双写，旧字段保留
        return $st;
    });
    return $s;
}

function main(): void
{
    echo "== 状态版本迁移链 ==" . PHP_EOL;

    // 1) 缺 _v 视为 0，走完整链
    $sc = schema7();
    $old = ["name" => "阿甲", "gold" => 30];
    $new = $sc->migrate("player", $old);
    T::ok($new["hp"] === 100, "v5 的迁移补上了 hp");
    T::ok($new["coins"] === 30, "v7 的迁移把 gold 复制成 coins");
    T::ok($new["gold"] === 30, "旧字段保留（两阶段迁移的中间态）");
    T::ok($new["_v"] === 7, "版本打到当前值", "_v=" . $new["_v"]);

    // 2) 纯函数 / 不改入参
    T::ok(!isset($old["hp"]) && !isset($old["_v"]), "入参没被修改");

    // 3) 幂等：再迁一次结果不变
    $again = $sc->migrate("player", $new);
    T::ok($again === $new, "已是当前版本时原样返回");

    // 4) 中间版本只跑缺的那几步
    $v6 = ["name" => "阿乙", "gold" => 5, "hp" => 77, "_v" => 6];
    $r6 = $sc->migrate("player", $v6);
    T::ok($r6["hp"] === 77, "已有的 hp 不被覆盖（v5 步骤没重跑）");
    T::ok($r6["coins"] === 5, "v7 步骤跑了");

    // 5) 版本比代码新 → 拒绝
    $future = ["_v" => 9, "name" => "未来"];
    $threw = false;
    try {
        $sc->migrate("player", $future);
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    T::ok($threw, "版本比代码新时拒绝（不降级读）");

    // 6) 登记表漏一段 → 拒绝
    $gap = new Schema(7);
    $gap->step(5, function (array $st): array {
        return $st;
    });
    $threw2 = false;
    try {
        $gap->migrate("player", ["_v" => 5]);
    } catch (\RuntimeException $e) {
        $threw2 = true;
    }
    T::ok($threw2, "链缺一段时拒绝（不把旧结构当新结构用）");

    // 7) 登记超出当前版本 → 直接报错（写错代码要立刻发现）
    $threw3 = false;
    try {
        $sc->step(99, function (array $st): array {
            return $st;
        });
    } catch (\RuntimeException $e) {
        $threw3 = true;
    }
    T::ok($threw3, "登记超出当前版本被拒");

    // 8) 多块快照分别迁移
    $snap = new Snapshot("room", 1001);
    $snap->put("room", ["state" => 2, "gold" => 10]);
    $snap->put("game", ["hp" => 50, "_v" => 7]);
    $sc->migrateSnapshot($snap);
    T::ok($snap->get("room")["_v"] === 7, "room 块被迁移");
    T::ok($snap->get("game")["_v"] === 7, "game 块保持当前版本");

    // 9) Restorer 集成：恢复前先迁移，业务看到的就是当前结构
    $store = new MemoryStore();
    $s1 = new Snapshot("room", 1);
    $s1->put("room", ["gold" => 11]);
    $store->put($s1);
    $s2 = new Snapshot("room", 2);
    $s2->put("room", ["_v" => 42, "gold" => 22]);   // 未来版本：不可读
    $store->put($s2);

    $seen = new Seen();
    $restorer = new Restorer(50, 20000);
    $out = $restorer->restore(
        $store,
        "room",
        function (Snapshot $s) use ($seen): bool {
            $seen->blocks[$s->key] = $s->get("room");
            return true;
        },
        true,
        null,
        $sc
    );
    $b1 = $seen->blocks[1] ?? [];
    T::ok(($b1["_v"] ?? 0) === 7, "恢复时已迁移到当前版本");
    T::ok(($b1["hp"] ?? 0) === 100, "业务直接拿到补好的字段");
    T::ok(!isset($seen->blocks[2]), "未来版本的快照没交给业务");
    T::ok($out["migrate_failed"] === 1, "记到一条迁移失败", "n=" . $out["migrate_failed"]);
    T::ok($out["done"] === 1, "成功恢复 1 条", "done=" . $out["done"]);
    T::ok($store->keys("room") === [1], "坏快照被删掉，不会再卡住下次启动");

    $st = $sc->stats();
    echo "  schema 统计: migrated=" . $st["migrated"] . " untouched=" . $st["untouched"]
        . " rejected=" . $st["rejected"] . " steps=" . $st["steps"] . PHP_EOL;

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
