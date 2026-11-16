<?php

// T7-03 验收：灰度分流 + 观测 + 原子切换。
//
// v1 的 add 是 a+b（3），v2 是 a+b+1000（1003）——用返回值就能分辨走的是哪一代。
// 分流必须是**确定性**的：同一个 key 每次都要落到同一侧，
// 否则同一局里新旧逻辑混跑，状态分裂。

use TSF\Wasm\Module;

function main(): void
{
    $v1 = "/data/TypeServer/spike/s1/s1-probe.wasm.cwasm";
    $v2 = "/data/TypeServer/spike/e1/module_v2/s1-probe-v2.wasm.cwasm";

    echo "bridge = " . Module::bridgeVersion() . PHP_EOL;

    $m = Module::load("probe", $v1);
    if ($m === null) {
        echo "load failed" . PHP_EOL;
        exit(1);
    }
    echo "1) load v1      add(1,2) = " . $m->call2("add", 1, 2) . "  <- 3" . PHP_EOL;

    // 2) 挂灰度实例，50% 分流
    $ok = $m->canary($v2, 500);
    echo "2) canary 50%   " . ($ok ? "ok" : "failed") . PHP_EOL;

    $old = 0;
    $new = 0;
    $k = 1;
    while ($k <= 20) {
        $r = $m->call2("add", 1, 2, $k);
        if ($r === 1003) {
            $new++;
        } elseif ($r === 3) {
            $old++;
        }
        $k++;
    }
    echo "   key 1..20 -> v1=" . $old . " v2=" . $new . "  <- 应各约一半" . PHP_EOL;

    // 3) 确定性：同一 key 调两次必须同结果
    $a1 = $m->call2("add", 1, 2, 7);
    $a2 = $m->call2("add", 1, 2, 7);
    echo "3) key=7 twice: " . $a1 . " / " . $a2 . "  <- 必须相同" . PHP_EOL;
    echo "   stats: " . $m->stats() . PHP_EOL;

    // 4) 全量切换：swap 到 v2，灰度实例应被清掉
    $us = $m->swap($v2);
    echo "4) swap v2      " . $us . " us" . PHP_EOL;
    echo "   add(1,2) = " . $m->call2("add", 1, 2) . "  <- 1003" . PHP_EOL;
    echo "   stats: " . $m->stats() . "  <- ratio 应归 0" . PHP_EOL;

    // 5) 只有 v2 才有的行为差异：regression 检查 v1 已不可用
    echo "5) no_such_fn = " . $m->call2("no_such_fn", 0, 0) . "  <- 负数" . PHP_EOL;

    $m->close();
    echo "closed" . PHP_EOL;
}
