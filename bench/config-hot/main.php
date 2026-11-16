<?php

/**
 * T6-02 验证：配置表热更。
 *
 * 这个程序模拟一个"已经在跑的服务器"：启动时加载配置，然后一直 tick。
 * 外部把新的 .bin + manifest 拷进来，它应当在 100 ms 内把新值读进内存。
 *
 * 输出约定（给 shell 脚本掐表用）：
 *   ready ... at <墙钟毫秒>
 *   生效 name=<新值> at <墙钟毫秒>
 *   stats: reloads=... failures=... last_ms=...
 */

use Gen\Config\Tables;
use TSF\Config\Watcher;
use TSF\Service\Scheduler;

function wallMs(): int
{
    return (int) (microtime(true) * 1000);
}

function nameOf(int $id): string
{
    $it = Tables::item()->get($id);
    return $it === null ? "<none>" : $it->itemName;
}

function main(): void
{
    $args = $_SERVER["argv"] ?? [];
    $seconds = isset($args[1]) ? (int) $args[1] : 20;
    $dir = "build/config";

    $counts = Tables::load($dir);
    $rows = 0;
    foreach ($counts as $n) {
        $rows += $n;
    }
    echo "ready rows=" . $rows . " name=" . nameOf(1001) . " at " . wallMs() . PHP_EOL;

    $s = Scheduler::instance();
    $watchAddr = $s->spawnOf(function () use ($dir): Watcher {
        return new Watcher($dir, function (array $changed): array {
            return Tables::reload($changed);
        }, 30);
    });

    $last = nameOf(1001);
    $deadline = Scheduler::nowMs() + $seconds * 1000;
    while (Scheduler::nowMs() < $deadline) {
        $s->tick();
        usleep(500);

        $now = nameOf(1001);
        if ($now !== $last) {
            $last = $now;
            echo "生效 name=" . $now . " at " . wallMs() . PHP_EOL;
        }
    }

    $w = $s->serviceOf($watchAddr);
    if ($w instanceof Watcher) {
        $st = $w->stats();
        printf(
            "stats: reloads=%d failures=%d last_ms=%.2f changed=[%s] err=%s" . PHP_EOL,
            (int) $st["reloads"],
            (int) $st["failures"],
            (float) $st["last_ms"],
            implode(",", $st["last_changed"]),
            (string) $st["last_error"]
        );
    }
}
