<?php

// P1 演示：多模块共存 + 索引绑定 + 灰度切换 + 观测
//
// 验证四件事：
//   1) 两个模块（v1/v2）可同时加载，各自独立实例、互不影响
//   2) 索引绑定调用 vs 字符串查找调用的开销差异
//   3) 灰度切换：把 v1 槽位切到 v2 产物，行为立即改变，切换耗时可见
//   4) trap 后自动重建，统计口径能反映出来

// 产物路径优先从环境变量取，便于在不同目录/机器上跑而不改代码
function art_v1(): string
{
    $v = getenv("TP_ART_V1");
    if ($v === false || $v === "") {
        return "/data/TypeServer/spike/s1/s1-probe.wasm.cwasm";
    }
    return $v;
}

function art_v2(): string
{
    $v = getenv("TP_ART_V2");
    if ($v === false || $v === "") {
        return "/data/TypeServer/spike/e1/module_v2/s1-probe-v2.wasm.cwasm";
    }
    return $v;
}

function rss_mb(): int
{
    $s = file_get_contents("/proc/self/status");
    if ($s === false) {
        return 0;
    }
    foreach (explode("\n", $s) as $line) {
        if (strpos($line, "VmRSS:") === 0) {
            $p = explode(" ", $line);
            return (int) ($p[count($p) - 2] / 1024);
        }
    }
    return 0;
}

function main(): void
{
    echo "== P1: 多模块 + 索引绑定 + 灰度切换 ==" . PHP_EOL;
    echo "RSS(before): " . rss_mb() . " MB" . PHP_EOL;

    // ---- 1) 加载 v1 ----
    $t0 = microtime(true);
    $hMain = wasm_load("main", art_v1());
    $t1 = microtime(true);
    echo "load main(v1): handle=" . $hMain . " 耗时 " . (int) (($t1 - $t0) * 1000) . " ms" . PHP_EOL;
    echo "  main.add(1,2) = " . wasm_add($hMain, 1, 2) . PHP_EOL;

    // ---- 2) 加载 v2（另一个模块，独立实例）----
    $t0 = microtime(true);
    $hCanary = wasm_load("canary", art_v2());
    $t1 = microtime(true);
    echo "load canary(v2): handle=" . $hCanary . " 耗时 " . (int) (($t1 - $t0) * 1000) . " ms" . PHP_EOL;
    echo "  canary.add(1,2) = " . wasm_add($hCanary, 1, 2) . "  <- 应为 1003" . PHP_EOL;
    echo "存活模块数: " . wasm_count() . PHP_EOL;
    echo "RSS(after 2 modules): " . rss_mb() . " MB" . PHP_EOL;

    // ---- 3) 索引绑定 vs 字符串查找 ----
    $n = 200000;
    $t0 = microtime(true);
    $acc = 0;
    for ($i = 0; $i < $n; $i++) {
        $acc = wasm_add($hMain, $i, 1);
    }
    $t1 = microtime(true);
    $nsIdx = (int) (($t1 - $t0) * 1e9 / $n);

    $t0 = microtime(true);
    for ($i = 0; $i < $n; $i++) {
        $acc = wasm_add_byname($hMain, $i, 1);
    }
    $t1 = microtime(true);
    $nsName = (int) (($t1 - $t0) * 1e9 / $n);

    echo "索引绑定调用: " . $nsIdx . " ns/call" . PHP_EOL;
    echo "字符串查找  : " . $nsName . " ns/call" . PHP_EOL;
    echo "差异        : " . ($nsName - $nsIdx) . " ns/call" . PHP_EOL;

    // ---- 4) 灰度切换：main 槽位从 v1 切到 v2 ----
    echo "切换前 main.add(1,2) = " . wasm_add($hMain, 1, 2) . PHP_EOL;
    $swapUs = wasm_swap($hMain, art_v2());
    echo "wasm_swap 耗时: " . $swapUs . " us" . PHP_EOL;
    echo "切换后 main.add(1,2) = " . wasm_add($hMain, 1, 2) . "  <- 应为 1003" . PHP_EOL;

    // ---- 5) trap 后自动重建 ----
    echo "burn(2e9) 返回值: " . wasm_burn($hCanary, 2000000000) . "  <- 应为 -3000" . PHP_EOL;
    echo "重建后 canary.add(1,2) = " . wasm_add($hCanary, 1, 2) . PHP_EOL;

    // ---- 6) 观测口径 ----
    echo PHP_EOL . "== 观测 ==" . PHP_EOL;
    echo "  main  : calls=" . wasm_stats($hMain, 0)
        . " traps=" . wasm_stats($hMain, 1)
        . " rebuilds=" . wasm_stats($hMain, 2)
        . " alive=" . wasm_stats($hMain, 4) . PHP_EOL;
    echo "  canary: calls=" . wasm_stats($hCanary, 0)
        . " traps=" . wasm_stats($hCanary, 1)
        . " rebuilds=" . wasm_stats($hCanary, 2)
        . " alive=" . wasm_stats($hCanary, 4) . PHP_EOL;

    wasm_set_timing(1);
    for ($i = 0; $i < 100; $i++) {
        wasm_sim($hMain, 10000, 3);
    }
    wasm_set_timing(0);
    echo "  main max 单次耗时(sim 10000): " . wasm_stats($hMain, 3) . " us" . PHP_EOL;

    wasm_shutdown();
    echo "RSS(end): " . rss_mb() . " MB" . PHP_EOL;
    echo "存活模块数(shutdown 后): " . wasm_count() . PHP_EOL;
    echo "done" . PHP_EOL;
}
