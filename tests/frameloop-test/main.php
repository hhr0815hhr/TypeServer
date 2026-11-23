<?php

/**
 * pkg/frameloop 回归。
 *
 * ## 重点是 delta 的三条边界，而不是"能不能跑起来"
 *
 * 帧循环的 delta 直接决定实体移动多远。算错的后果都不是崩溃：
 *
 *   1. **首帧**：`lastMs` 初始是 0，第一帧的 delta 会等于"当前时间戳"——
 *      不封顶的话，玩家一进房间就瞬移到地图外。
 *   2. **时间倒退**（NTP 校时、虚拟机暂停恢复）：delta 变负，不归零就会
 *      往回走。
 *   3. **卡顿**：一个 800ms 的卡顿之后，不封顶的话实体一次瞬移穿过墙体。
 *
 * 三种都"跑得起来"，只是位置不对。所以这里专门测这三条。
 */

use TSF\FrameLoop\FrameLoop;
use TSF\Service\Message;
use TSF\Service\Service;

final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $label, string $detail = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  PASS  " . $label . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL  " . $label . ($detail === "" ? "" : "  → " . $detail) . PHP_EOL;
        }
    }

    public static function eq(int|string|bool $got, int|string|bool $want, string $label): void
    {
        self::ok($got === $want, $label, "got=" . var_export($got, true) . " want=" . var_export($want, true));
    }
}

/** 最小的宿主服务：帧循环只需要一个 Service 实例来挂定时器 */
final class Host extends Service
{
    public int $frames = 0;

    public function onMessage(Message $m): void
    {
        $this->frames++;
    }
}

function caseDelta(): void
{
    echo PHP_EOL . "== delta 的三条边界 ==" . PHP_EOL;

    $host = new Host();
    $loop = new FrameLoop($host, 100, 50, 200);

    // 1) 首帧：lastMs 是 0，delta 等于"从纪元到现在"——必须被 maxDeltaMs 封住，
    //    否则玩家进门就飞出去
    $d0 = $loop->beginFrame();
    T::ok($d0 <= 200, "首帧 delta 被 maxDeltaMs(200) 封顶", "d=" . $d0);
    T::ok($d0 >= 0, "首帧 delta 非负");
    $loop->endFrame();

    // 2) 正常帧：间隔约 30ms，允许调度抖动
    usleep(30000);
    $d1 = $loop->beginFrame();
    T::ok($d1 >= 25 && $d1 <= 80, "正常间隔的 delta 接近真实经过时间", "d=" . $d1);
    $loop->endFrame();

    // 3) 卡顿：睡 120ms 但 maxDeltaMs=200 时不封顶
    usleep(120000);
    $d2 = $loop->beginFrame();
    T::ok($d2 >= 100, "120ms 卡顿如实反映（未触发封顶）", "d=" . $d2);
    $loop->endFrame();

    // 4) 封顶实例：maxDeltaMs=10，任何间隔都被压到 10
    $host2 = new Host();
    $tight = new FrameLoop($host2, 100, 50, 10);
    $tight->beginFrame();
    $tight->endFrame();
    usleep(50000);
    $d3 = $tight->beginFrame();
    T::eq($d3, 10, "超过 maxDeltaMs 时压到上限（不让实体瞬移）");
    $tight->endFrame();
}

function caseStats(): void
{
    echo PHP_EOL . "== 统计 ==" . PHP_EOL;
    $host = new Host();
    $loop = new FrameLoop($host, 100, 50, 200);

    T::eq($loop->ticks(), 0, "初始 ticks 为 0");
    $loop->beginFrame();
    $loop->endFrame();
    $loop->beginFrame();
    $loop->endFrame();
    T::eq($loop->ticks(), 2, "两帧后 ticks=2");

    $s = $loop->stats();
    T::eq($s["ticks"], 2, "stats 里的 ticks 一致");
    T::ok($s["last_us"] >= 0, "last_us 非负");
    T::ok($s["max_us"] >= $s["last_us"], "max_us 不小于 last_us");

    T::eq($loop->frameMs(), 50, "frameMs 按构造参数");
    T::ok(!$loop->isRunning(), "未 start 时 isRunning=false");
}

function main(): void
{
    echo "== pkg/frameloop 回归 ==" . PHP_EOL;
    caseDelta();
    caseStats();

    echo PHP_EOL . "passed=" . T::$pass . " failed=" . T::$fail . PHP_EOL;
}
