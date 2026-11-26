<?php

use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 内存压力：在 OOM 之前把现场写下来。
 *
 * ## 为什么需要
 *
 * OOM 是 fatal error，不是 Throwable。实测（bench/oom）AOT 产物 OOM 时：
 *   - `catch (\Throwable)` 接不住
 *   - `register_shutdown_function` **不执行**
 *   - 进程以退出码 137（SIGKILL）直接没了
 *   - 只在 stderr 留一行 "Hard memory limit of N bytes exhausted ... in file on line"
 *
 * 也就是"吃满内存的那一刻，什么现场都来不及记"。唯一的办法是提前动手：
 * 每 64 轮 tick 查一次占用率，到 92% 就落 dump——那时进程还活着。
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

final class Idle extends Service
{
    public function onMessage(Message $m): void
    {
    }
}

const DUMP_DIR = "/tmp/tsf-mem-pressure";

function cleanDir(): void
{
    if (!is_dir(DUMP_DIR)) {
        return;
    }
    $files = glob(DUMP_DIR . "/*");
    if ($files !== false) {
        foreach ($files as $f) {
            @unlink($f);
        }
    }
}

function dumpCount(): int
{
    $files = glob(DUMP_DIR . "/*");
    return $files === false ? 0 : count($files);
}

function main(): void
{
    echo "== 内存压力：OOM 前落 dump ==" . PHP_EOL;
    cleanDir();

    // ---- 1. 没开压力检查时什么都不做 ----
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->crashDir(DUMP_DIR);
    $s->spawnOf(function (): Service {
        return new Idle();
    });
    for ($i = 0; $i < 64; $i++) {
        $s->tick();
    }
    T::ok(dumpCount() === 0, "未开启时不落 dump", "文件数 " . dumpCount());
    T::ok($s->memoryPressure()["dumped"] === 0, "未开启时 dumped 为 0");

    // ---- 2. 阈值设得很低，必然触发 ----
    // 把 limit 压到 64M：512M 下本进程只占约 2MB，整数百分比会算成 0%，
    // 测不出任何东西。64M 既能让百分比有意义，又留足余量给 dump 过程
    ini_set("memory_limit", "64M");
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->crashDir(DUMP_DIR);
    $s->setMemoryPressure(1, 2);
    $s->spawnOf(function (): Service {
        return new Idle();
    });
    for ($i = 0; $i < 64; $i++) {
        $s->tick();
    }
    $mp = $s->memoryPressure();
    T::ok($mp["pct"] >= 2, "实际占用确实超过 dump 阈值",
        $mp["pct"] . "%（used=" . intdiv($mp["used"], 1024) . "KB limit=" . intdiv($mp["limit"], 1048576) . "MB）");
    T::ok($mp["dumped"] === 1, "落了一次 dump");
    T::ok(dumpCount() === 1, "dump 文件生成", "文件数 " . dumpCount());

    // ---- 3. 只落一次：后续再 tick 不应继续写 ----
    for ($i = 0; $i < 200; $i++) {
        $s->tick();
    }
    T::ok(dumpCount() === 1, "重复 tick 不会重复 dump", "文件数 " . dumpCount());

    // ---- 4. memory_limit = -1（无限制）时不误报 ----
    ini_set("memory_limit", "-1");
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->crashDir(DUMP_DIR);
    $s->setMemoryPressure(1, 2);
    $s->spawnOf(function (): Service {
        return new Idle();
    });
    for ($i = 0; $i < 64; $i++) {
        $s->tick();
    }
    $mp2 = $s->memoryPressure();
    T::ok($mp2["limit"] === 0, "无限制时 limit 读成 0", "limit=" . $mp2["limit"]);
    T::ok($mp2["dumped"] === 0, "无限制时不落 dump");

    // 恢复，避免影响后续
    ini_set("memory_limit", "512M");
    cleanDir();

    echo PHP_EOL . "passed=" . T::$pass . " failed=" . T::$fail . PHP_EOL;
}
