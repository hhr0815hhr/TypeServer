<?php

use TSF\Script\Context;
use TSF\Script\Manager;

/**
 * pkg/script 的回归测试。
 *
 * 输出 PASS/FAIL 行，由 run.sh 统计。不用 assert：
 * AOT 下断言失败的行为不确定，显式比较更可靠。
 */

final class Player
{
    public int $uid = 1001;
    public int $level = 10;
    public int $gold = 500;
}

function check(bool $cond, string $name, string $detail = ""): void
{
    if ($cond) {
        echo "PASS " . $name . "\n";
    } else {
        echo "FAIL " . $name . ($detail !== "" ? "  (" . $detail . ")" : "") . "\n";
    }
}

function main(): void
{
    $dir = __DIR__;

    // ---- 1. 加载 ----
    $mgr = new Manager();
    $mgr->register("quest", $dir . "/quest.php");
    $failed = $mgr->loadAll();
    check(count($failed) === 0, "加载脚本");

    $s = $mgr->get("quest");
    check($s !== null, "按名取到脚本");
    if ($s === null) {
        return;
    }
    check($s->loaded(), "loaded() 为真");
    check($s->versions() === 1, "首次加载 versions=1");

    // ---- 2. 对象共享 ----
    $p = new Player();
    $ctx = new Context($p, ["uid", "level", "gold"], ["gold"]);
    $r = $s->callWithContext("main", $ctx);
    check($p->gold === 600, "脚本改了宿主对象（500 -> 600）", "实际 " . (string) $p->gold);
    check($r === 600, "返回值是脚本算出的 600");

    // ---- 3. 只读字段 ----
    $ctx2 = new Context($p, ["uid", "level", "gold"], ["gold"]);
    check(!$ctx2->canWrite("level"), "level 不可写");
    check($ctx2->canWrite("gold"), "gold 可写");
    check($ctx2->getInt("level") === 10, "只读字段能读");

    // ---- 4. 越权写入 ----
    $threw = false;
    try {
        $ctx2->setInt("level", 99);
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    check($threw, "越权写入抛 RuntimeException");
    check($p->level === 10, "越权后 level 没变");

    // ---- 5. 热更 ----
    $path = $dir . "/quest.php";
    $orig = (string) file_get_contents($path);
    // quest.php 里是 $ctx->addInt("gold", 100)，不是 "+ 100"
    $mod = str_replace('"gold", 100', '"gold", 999', $orig);
    if ($mod === $orig) {
        check(false, "测试自身：改脚本内容失败（字符串没匹配上）");
        return;
    }
    file_put_contents($path, $mod);

    // filemtime 精度是秒，测试里直接 reload 而不是等 changed()
    $s->reload();
    $p2 = new Player();
    $ctx3 = new Context($p2, ["gold"], ["gold"]);
    $s->callWithContext("main", $ctx3);
    check($p2->gold === 1499, "热更后走新逻辑（500 -> 1499）", "实际 " . (string) $p2->gold);
    check($s->versions() === 2, "reload 后 versions=2");

    // 还原脚本
    file_put_contents($path, $orig);

    // ---- 6. 异常隔离 ----
    $mgr->register("boom", $dir . "/boom.php");
    $mgr2failed = $mgr->loadAll();
    check(count($mgr2failed) === 0, "加载会抛异常的脚本");

    $boom = $mgr->get("boom");
    if ($boom !== null) {
        $ctx4 = new Context(new Player(), ["gold"], ["gold"]);
        $r2 = $boom->callWithContext("main", $ctx4);
        $isErr = is_array($r2) && isset($r2["ok"]) && $r2["ok"] === false;
        check($isErr, "异常被隔离成结构化错误");
        if ($isErr) {
            check(isset($r2["class"]) && $r2["class"] === "RuntimeException", "错误里带异常类名");
        }
    }

    echo "DONE\n";
}
