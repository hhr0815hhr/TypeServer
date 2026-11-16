<?php

use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

final class Sink extends Service
{
    /** @var array<int, int> 每条定时器的误差（毫秒） */
    public array $errors = [];

    public function onMessage(Message $m): void
    {
        $expected = 0;
        if (is_string($m->data)) {
            $expected = (int) $m->data;
        }
        $this->errors[] = Scheduler::nowMs() - $expected;
    }
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

function main(): void
{
    // ---- 先单独测定时器结构本身的开销：这决定了 10 万定时器能不能按时触发 ----
    echo "== 时间轮本身的开销 ==" . PHP_EOL;
    $t = new \TSF\Timer\Timers();
    $base = Scheduler::nowMs();
    $m = 100000;
    $t1 = hrtime(true);
    for ($i = 0; $i < $m; $i++) {
        $t->add($base, 1, $base + 1 + intdiv($i * 2000, $m), 9001, "");
    }
    $t2 = hrtime(true);
    $dueAll = $t->due($base + 2002);
    $t3 = hrtime(true);
    echo "  入槽 10 万      " . number_format(($t2 - $t1) / 1e6, 1) . " ms（单个 "
        . number_format(($t2 - $t1) / $m, 0) . " ns）" . PHP_EOL;
    echo "  排空 10 万      " . number_format(($t3 - $t2) / 1e6, 1) . " ms（单个 "
        . number_format(($t3 - $t2) / $m, 0) . " ns）" . PHP_EOL;
    echo "  取出条数        " . number_format(count($dueAll)) . PHP_EOL;
    echo PHP_EOL;

    // ---- 对照：远端用的小顶堆，同一编译参数下的成本 ----
    $heap = new \TSF\Timer\TimerHeap();
    $t1 = hrtime(true);
    for ($i = 0; $i < $m; $i++) {
        $heap->push(new \TSF\Timer\Timer($i + 1, $base + 1 + intdiv($i * 2000, $m), 1, 9001, ""));
    }
    $t2 = hrtime(true);
    $popped = 0;
    while ($heap->pop() !== null) {
        $popped++;
    }
    $t3 = hrtime(true);
    echo "  对照·最小堆     push " . number_format(($t2 - $t1) / $m, 0) . " ns/个，pop "
        . number_format(($t3 - $t2) / $m, 0) . " ns/个（$popped 个）" . PHP_EOL;
    echo PHP_EOL;

    $n = 100000;
    $spreadMs = 2000;
    // 留足注册时间：注册 10 万个本身要几百毫秒，起始点必须晚于注册结束，
    // 否则后注册的定时器"一出生就过期"，测出来的是注册耗时不是调度精度。
    $startDelayMs = 2000;

    Scheduler::reset();
    $s = Scheduler::instance();
    $s->register("sink", function () {
        return new Sink();
    });
    $addr = $s->spawn("sink");

    $t0 = Scheduler::nowMs();

    // 注册 10 万个定时器：从 t0+300ms 开始，在 2 秒内均匀铺开
    $regStart = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $at = $t0 + $startDelayMs + intdiv($i * $spreadMs, $n);
        $s->timers()->add($t0, $addr, $at, 9001, (string) $at);
    }
    $regEnd = hrtime(true);

    echo "== 10 万定时器精度 ==" . PHP_EOL;
    echo "  注册耗时      " . number_format(($regEnd - $regStart) / 1e6, 1) . " ms（单个 "
        . number_format(($regEnd - $regStart) / $n, 0) . " ns）" . PHP_EOL;
    echo "  堆内定时器    " . number_format($s->timers()->size()) . PHP_EOL;
    echo "  分布          " . $spreadMs . " ms 内均匀铺开（约 "
        . round($n / $spreadMs) . " 个/ms）" . PHP_EOL;

    $fired = 0;
    $runStart = hrtime(true);
    $guard = 0;
    while ($fired < $n) {
        $h = $s->tick();
        $fired += $h;
        if ($fired >= $n) {
            break;
        }
        // 只有确实闲着才睡：定时器密集时一睡就会追不上（这是驱动方的责任，
        // 也是把 tick() 交给事件循环而不是自己开 sleep 循环的原因）
        if ($h === 0) {
            usleep(100);
        }
        $guard++;
        if ($guard > 40_000_000) {
            echo "  ! 超时退出（已触发 " . $fired . "）" . PHP_EOL;
            break;
        }
    }
    $runEnd = hrtime(true);

    $svc = $s->serviceOf($addr);
    if (!($svc instanceof Sink)) {
        echo "  ! 取不到服务" . PHP_EOL;
        return;
    }
    $errs = $svc->errors;
    sort($errs);
    $abs = [];
    $sum = 0;
    foreach ($errs as $e) {
        $v = $e < 0 ? -$e : $e;
        $abs[] = $v;
        $sum += $v;
    }
    sort($abs);

    echo "  触发数        " . number_format(count($errs)) . " / " . number_format($n) . PHP_EOL;
    echo "  实际耗时      " . number_format(($runEnd - $runStart) / 1e6, 0) . " ms（分布窗口 "
        . $spreadMs . " ms）" . PHP_EOL;
    echo "  误差 p50      " . number_format(pct($abs, 50, 100)) . " ms" . PHP_EOL;
    echo "  误差 p99      " . number_format(pct($abs, 99, 100)) . " ms" . PHP_EOL;
    echo "  误差 p999     " . number_format(pct($abs, 999, 1000)) . " ms" . PHP_EOL;
    echo "  误差 max      " . number_format($abs[count($abs) - 1]) . " ms" . PHP_EOL;
    echo "  平均误差      " . number_format($sum / (count($abs) > 0 ? count($abs) : 1), 2) . " ms" . PHP_EOL;
    echo "  剩余墓碑      " . number_format($s->timers()->tombstones()) . PHP_EOL;
    echo "  堆内剩余      " . number_format($s->timers()->size()) . PHP_EOL;
    echo "  达标(max≤20ms) " . ($abs[count($abs) - 1] <= 20 ? "是" : "否") . PHP_EOL;
}
