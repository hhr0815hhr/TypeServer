<?php

use Bench\CountService;
use Bench\LatencyService;
use TSF\Service\Scheduler;

function say(string $s): void
{
    echo $s . PHP_EOL;
}

function pct(array $sorted, int $num, int $den): int
{
    $n = count($sorted);
    if ($n === 0) {
        return 0;
    }
    $i = intdiv($n * $num, $den);
    if ($i >= $n) {
        $i = $n - 1;
    }
    return $sorted[$i];
}

function median(array $v): float
{
    sort($v);
    $n = count($v);
    if ($n === 0) {
        return 0.0;
    }
    return $n % 2 === 1 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2.0;
}

function drain(Scheduler $s): int
{
    return $s->tick();
}

/** 跑一轮吞吐，返回 msg/s */
function throughputOnce(int $n, int $batch): float
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->register("count", function () {
        return new CountService();
    });
    $addr = $s->spawn("count");

    for ($i = 0; $i < 10000; $i++) {
        $s->deliver($addr, 1001, "w");
    }
    drain($s);

    $t0 = hrtime(true);
    if ($batch <= 0) {
        for ($i = 0; $i < $n; $i++) {
            $s->deliver($addr, 1001, "x");
        }
        drain($s);
    } else {
        $rest = $n;
        while ($rest > 0) {
            $k = $rest < $batch ? $rest : $batch;
            for ($i = 0; $i < $k; $i++) {
                $s->deliver($addr, 1001, "x");
            }
            drain($s);
            $rest -= $k;
        }
    }
    $t1 = hrtime(true);
    return $n / (($t1 - $t0) / 1e9);
}

function benchThroughput(int $n, int $batch, int $rounds): void
{
    $rates = [];
    for ($r = 0; $r < $rounds; $r++) {
        $rates[] = throughputOnce($n, $batch);
    }
    $med = median($rates);
    sort($rates);
    $mode = $batch <= 0 ? "深队列（一次性投完）" : "浅队列（每批 " . $batch . " 条）";
    say("  " . str_pad($mode, 26)
        . "中位 " . str_pad(number_format($med, 0), 9) . " msg/s"
        . "  区间 " . number_format($rates[0], 0) . " ~ " . number_format($rates[count($rates) - 1], 0)
        . "  单条 " . number_format(1e9 / $med, 0) . " ns"
        . "  " . ($med >= 500000 ? "达标" : "未达标"));
}

function benchLatency(int $n, int $batch, int $rounds): void
{
    $p99s = [];
    $p50s = [];
    for ($r = 0; $r < $rounds; $r++) {
        Scheduler::reset();
        $s = Scheduler::instance();
        $s->register("lat", function () {
            return new LatencyService();
        });
        $addr = $s->spawn("lat");
        $svc = $s->serviceOf($addr);
        if (!($svc instanceof LatencyService)) {
            say("  ! 取不到服务实例");
            return;
        }

        $sendAt = [];
        $idx = 0;
        $roundsN = intdiv($n, $batch);
        for ($i = 0; $i < $roundsN; $i++) {
            for ($k = 0; $k < $batch; $k++) {
                $sendAt[$idx] = hrtime(true);
                $s->deliver($addr, 1001, (string) $idx);
                $idx++;
            }
            drain($s);
        }

        $recvAt = $svc->recvAt;
        $lat = [];
        for ($i = 0; $i < $idx; $i++) {
            if (isset($recvAt[$i])) {
                $lat[] = $recvAt[$i] - $sendAt[$i];
            }
        }
        if ($lat === []) {
            say("  ! 没有样本");
            return;
        }
        sort($lat);
        $p50s[] = pct($lat, 50, 100);
        $p99s[] = pct($lat, 99, 100);
    }

    say("  批大小 " . $batch . "，" . $rounds . " 轮");
    say("  p50 中位      " . number_format(median($p50s), 0) . " ns");
    say("  p99 中位      " . number_format(median($p99s), 0) . " ns"
        . "  " . (median($p99s) <= 100000 ? "达标(≤100μs)" : "未达标(>100μs)"));
}

function benchManyServices(int $count): void
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->register("count", function () {
        return new CountService();
    });

    $t0 = hrtime(true);
    $addrs = [];
    for ($i = 0; $i < $count; $i++) {
        $addrs[] = $s->spawn("count");
    }
    $t1 = hrtime(true);

    $t2 = hrtime(true);
    foreach ($addrs as $a) {
        $s->deliver($a, 1001, "x");
    }
    $t3 = hrtime(true);

    $handled = drain($s);
    $t4 = hrtime(true);

    say("  服务数        " . number_format($count));
    say("  spawn         " . number_format(($t1 - $t0) / 1e6, 1) . " ms（单个 "
        . number_format(($t1 - $t0) / 1000 / $count, 1) . " μs）");
    say("  投递          " . number_format(($t3 - $t2) / 1e6, 1) . " ms");
    say("  派发          " . number_format(($t4 - $t3) / 1e6, 1) . " ms（" . number_format($handled) . " 条）");
    say("  平均派发      " . number_format(($t4 - $t3) / 1000 / ($count > 0 ? $count : 1), 2) . " μs/服务");
}

/**
 * 协程模式的纯消息吞吐。
 *
 * 为什么不能直接用 `drain()`：协程模式下 `tick()` 只是**创建协程就返回**，
 * 消息在协程里异步处理。原来的 `drain()` 循环会因为"队列看起来空了"提前退出，
 * 测出来的数字是"派发速度"而不是"处理速度"——一个典型的测错但看起来合理的坑。
 *
 * 所以这里必须等"处理完成"这个事实：在协程里轮询计数，用 `Coroutine::sleep` 让出，
 * 让派发出来的协程有机会跑。`Coroutine\run` 自己带事件循环，不需要 Event::wait。
 */
function benchThroughputCoro(int $n): void
{
    $rate = 0.0;
    $count = 0;
    $singleNs = 0.0;

    \Swoole\Coroutine\run(function () use ($n, &$rate, &$count, &$singleNs): void {
        Scheduler::reset();
        $s = Scheduler::instance();
        $s->setCoroutine(true);
        $s->register("count", function () {
            return new CountService();
        });
        $addr = $s->spawn("count");
        $svc = $s->serviceOf($addr);
        if (!($svc instanceof CountService)) {
            return;
        }

        $t0 = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $s->deliver($addr, 1001, "x");
        }
        $s->tick(0);        // 首次派发：创建协程，剩下的靠轮询推进

        $guard = 0;
        while ($svc->count < $n) {
            \Swoole\Coroutine::sleep(0.001);   // Swoole 的下限是 1ms
            $s->tick(0);
            $guard++;
            if ($guard > 60000) {
                break;      // 12s 上限，防止测挂
            }
        }
        $dt = (hrtime(true) - $t0) / 1e9;
        if ($dt > 0) {
            $rate = $n / $dt;
            $singleNs = $dt * 1e9 / $n;
        }
        $count = $svc->count;
    });

    say("  协程模式（深队列 " . number_format($n) . " 条）"
        . "  " . str_pad(number_format($rate, 0), 9) . " msg/s"
        . "  单条 " . number_format($singleNs, 0) . " ns"
        . "  实际处理 " . number_format($count) . " 条");
}

function main(): void
{
    $argv = $_SERVER["argv"] ?? [];
    if (isset($argv[1]) && $argv[1] === "coro") {
        // 只跑协程对比：同一档位（深队列 10 万）分别测同步/协程
        say("== 协程 vs 同步：同一负载下的纯消息吞吐 ==");
        benchThroughput(100000, 0, 3);
        benchThroughputCoro(100000);
        return;
    }

    say("== 1) 吞吐：单服务 投递+派发（每档 5 轮取中位）==");
    benchThroughput(100000, 0, 5);
    benchThroughput(100000, 64, 5);
    benchThroughput(100000, 256, 5);

    say("");
    say("== 2) 延迟：浅队列稳态（8 条一批，3 轮）==");
    benchLatency(80000, 8, 3);

    say("");
    say("== 3) 万级服务：每服务一条消息 ==");
    benchManyServices(10000);
}
