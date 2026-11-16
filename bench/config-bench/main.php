<?php

/**
 * 配置表加载/热更耗时。
 *
 * `config_bench bench`  冷启动 + 重载耗时（1 万行 × 7 列）
 * `config_bench serve`  起一个 Watcher 一直跑，等外部把新数据拷进来，
 *                       量"落地 → 内存生效"——这才是 T6-02 真正要的那个数字
 */

use Gen\Config\Tables;
use TSF\Config\Watcher;
use TSF\Service\Scheduler;

function wallMs(): int
{
    return (int) (microtime(true) * 1000);
}

function firstNameOf(): string
{
    $r = Tables::big()->get(1);
    return $r === null ? "<none>" : $r->bigName;
}

function runBench(string $dir): void
{
    $t0 = hrtime(true);
    $counts = Tables::load($dir);
    $us = (float) (hrtime(true) - $t0) / 1000.0;

    $rows = 0;
    foreach ($counts as $n) {
        $rows += $n;
    }
    printf("加载 %d 行：%.2f ms（%.2f µs/行）\n", $rows, $us / 1000.0, $us / ($rows > 0 ? $rows : 1));

    $t = Tables::big();
    $first = $t->get(1);
    $last = $t->get(10000);
    if ($first !== null && $last !== null) {
        printf(
            "抽查 id=1: name=%s hp=%d ratio=%.3f flag=%s tags=[%s]\n",
            $first->bigName,
            $first->hp,
            $first->ratio,
            $first->flag ? "true" : "false",
            implode(",", $first->tags)
        );
        printf("抽查 id=10000: name=%s hp=%d\n", $last->bigName, $last->hp);
    } else {
        echo "FAIL 抽查不到数据\n";
        exit(1);
    }

    $t1 = hrtime(true);
    $again = $t->load($dir . "/big.bin");
    $us2 = (float) (hrtime(true) - $t1) / 1000.0;
    printf("重新 load %d 行：%.2f ms\n", $again, $us2 / 1000.0);
}

function runServe(string $dir, int $seconds): void
{
    Tables::load($dir);
    echo "ready name=" . firstNameOf() . " at " . wallMs() . PHP_EOL;

    $s = Scheduler::instance();
    $addr = $s->spawnOf(function () use ($dir): Watcher {
        return new Watcher($dir, function (array $changed): array {
            return Tables::reload($changed);
        }, 30);
    });

    $last = firstNameOf();
    $deadline = Scheduler::nowMs() + $seconds * 1000;
    while (Scheduler::nowMs() < $deadline) {
        $s->tick();
        usleep(500);
        $now = firstNameOf();
        if ($now !== $last) {
            $last = $now;
            echo "生效 name=" . $now . " at " . wallMs() . PHP_EOL;
        }
    }

    $w = $s->serviceOf($addr);
    if ($w instanceof Watcher) {
        $st = $w->stats();
        printf(
            "stats: reloads=%d failures=%d last_ms=%.2f changed=[%s]" . PHP_EOL,
            (int) $st["reloads"],
            (int) $st["failures"],
            (float) $st["last_ms"],
            implode(",", $st["last_changed"])
        );
    }
}

function main(): void
{
    $args = $_SERVER["argv"] ?? [];
    $mode = isset($args[1]) ? (string) $args[1] : "bench";
    $dir = "build/config";

    if ($mode === "serve") {
        Scheduler::reset();
        runServe($dir, 20);
        return;
    }
    runBench($dir);
}
