<?php

use TSF\Service\IdAllocator;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 服务 id 回收。两种模式（环境变量 ID_TEST）：
 *
 *   unit  IdAllocator 的单元行为，用 12 个 id 的小地址空间把"回绕"压缩到几次调用里：
 *         单调递增、跳过活着的、跳过宽限期内的、宽限期过后可复用、全部不可用时抛异常。
 *
 *   full  真的把 24 位地址空间用完一轮再回绕：spawn + kill 16,777,216 次（约 30–40 秒）。
 *         修之前这一次循环进行到第 16,777,216 次会抛"本地服务 id 用尽"，
 *         此后这个进程永久不能再建服务——这是"一局一房间服"的进程寿命上限。
 *         同时钉住回绕最重要的一条性质：回绕**不能撞上活着的服务**。
 */
final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $label, string $detail = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  OK   " . $label . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL " . $label . ($detail === "" ? "" : "  → " . $detail) . PHP_EOL;
        }
    }

    public static function eq(int|bool $got, int|bool $want, string $label): void
    {
        self::ok($got === $want, $label, "got=" . var_export($got, true) . " want=" . var_export($want, true));
    }
}

/** 什么都不做的服务：full 模式要建 1600 万个，构造必须尽可能便宜 */
final class EmptyService extends Service
{
    public function onMessage(Message $m): void
    {
    }
}

/** 从头活到尾的服务：回绕之后它的 id 不能被分给别人 */
final class Anchor extends Service
{
    public int $hits = 0;

    public function onMessage(Message $m): void
    {
        $this->hits++;
    }
}

function runUnit(): void
{
    echo "== [1] 单调递增 ==" . PHP_EOL;
    $none = function (int $id): bool {
        return false;
    };
    $a = new IdAllocator(12, 100);
    T::eq($a->acquire(0, $none), 1, "第一个 id 是 1（0 保留给系统）");
    T::eq($a->acquire(0, $none), 2, "第二个是 2");
    T::eq($a->left(), 10, "还没用过 10 个（3..12）");
    T::eq($a->wraps(), 0, "还没回绕");

    echo PHP_EOL . "== [2] 跳过活着的 ==" . PHP_EOL;
    $live4 = function (int $id): bool {
        return $id === 4;
    };
    T::eq($a->acquire(0, $live4), 3, "拿到 3");
    T::eq($a->acquire(0, $live4), 5, "4 被占着，跳到 5");

    echo PHP_EOL . "== [3] 到顶回绕 ==" . PHP_EOL;
    T::eq($a->acquire(0, $live4), 6, "6");
    T::eq($a->acquire(0, $live4), 7, "7");
    T::eq($a->acquire(0, $live4), 8, "8");
    T::eq($a->acquire(0, $live4), 9, "9");
    T::eq($a->acquire(0, $live4), 10, "10");
    T::eq($a->acquire(0, $live4), 11, "11");
    T::eq($a->acquire(0, $live4), 12, "12 是最后一个");
    T::eq($a->left(), 0, "没用过的为 0");
    T::eq($a->acquire(0, $live4), 1, "绕回 1");
    T::eq($a->wraps(), 1, "回绕计数 +1");
    // 从没被释放过的 id 不需要等：它上面从来没有服务被销毁过
    T::eq($a->acquire(0, $live4), 2, "2 没被释放过，直接可用");
    T::eq($a->acquire(0, $live4), 3, "3 同上");

    echo PHP_EOL . "== [4] 宽限期内的 id 不分配 ==" . PHP_EOL;
    // 5 和 6 在 t=1000 被释放
    $a->release(5, 1000);
    $a->release(6, 1000);
    // 下一个候选是 4（被占着）→ 5：刚释放 50ms，在 100ms 的宽限期内 → 跳过；
    // 6 同样 → 拿到 7。这一步没有回绕（下一个候选本来就落在这一圈里）
    T::eq($a->acquire(1050, $live4), 7, "5、6 还在宽限期内，拿到 7");
    T::eq($a->wraps(), 1, "这一步没有回绕");

    echo PHP_EOL . "== [5] 宽限期过后可以复用 ==" . PHP_EOL;
    T::eq($a->acquire(1100, $live4), 8, "8");
    T::eq($a->acquire(1100, $live4), 9, "9");
    T::eq($a->acquire(1100, $live4), 10, "10");
    T::eq($a->acquire(1100, $live4), 11, "11");
    T::eq($a->acquire(1100, $live4), 12, "12");
    T::eq($a->acquire(1100, $live4), 1, "12 之后绕回 1");
    T::eq($a->wraps(), 2, "回绕计数 +1");
    T::eq($a->acquire(1100, $live4), 2, "2");
    T::eq($a->acquire(1100, $live4), 3, "3");
    T::eq($a->acquire(1250, $live4), 5, "250ms 后 5 可以用了（4 仍被占着）");
    T::eq($a->acquire(1250, $live4), 6, "接着是 6");

    echo PHP_EOL . "== [6] 全部不可用时抛异常 ==" . PHP_EOL;
    $all = function (int $id): bool {
        return true;
    };
    $b = new IdAllocator(4, 100);
    $threw = false;
    $msg = "";
    try {
        $b->acquire(0, $all);
    } catch (\Throwable $e) {
        $threw = true;
        $msg = $e->getMessage();
    }
    T::ok($threw, "4 个 id 全被占着 → 抛异常");
    T::ok(strpos($msg, "用尽") !== false, "异常信息说明了原因", $msg);

    // 回绕之后：id 都不在宽限期内，也都可用——不能因为"回绕过"就误判为用尽
    $b->release(1, 500);
    T::eq($b->acquire(900, $none), 1, "用尽之后再释放一个：绕回去它已过宽限期，可用");

    echo PHP_EOL . "== [7] 全在宽限期内也算用尽 ==" . PHP_EOL;
    $c = new IdAllocator(3, 1000);
    T::eq($c->acquire(0, $none), 1, "1");
    T::eq($c->acquire(0, $none), 2, "2");
    T::eq($c->acquire(0, $none), 3, "3");
    $c->release(1, 2000);
    $c->release(2, 2000);
    $c->release(3, 2000);
    $threw2 = false;
    try {
        $c->acquire(2010, $none);
    } catch (\Throwable $e) {
        $threw2 = true;
    }
    T::ok($threw2, "三个 id 都在 1 秒宽限期内 → 抛异常，而不是死循环");
    T::eq($c->acquire(3100, $none), 1, "宽限期过后恢复可用");

    echo PHP_EOL . "== [8] 释放记录环有上限 ==" . PHP_EOL;
    $d = new IdAllocator(100000, 1000);
    for ($i = 1; $i <= 5000; $i++) {
        $d->release($i, $i);
    }
    T::ok($d->trackedReleases() <= 4096, "释放 5000 次后记录不超过 4096 条", "tracked=" . $d->trackedReleases());
    T::ok($d->trackedReleases() > 0, "环不是空的");

    echo PHP_EOL . "== [9] 宽限期可以关掉 ==" . PHP_EOL;
    $e = new IdAllocator(2, 0);
    T::eq($e->acquire(0, $none), 1, "1");
    T::eq($e->acquire(0, $none), 2, "2");
    $e->release(1, 10);
    T::eq($e->acquire(11, $none), 1, "grace=0：刚释放就可用");
}

function runFull(): void
{
    $sched = Scheduler::instance();
    $factory = function (): EmptyService {
        return new EmptyService();
    };

    echo "== [F1] 回绕之前先留一个从头活到尾的服务 ==" . PHP_EOL;
    $anchorAddr = $sched->spawnOf(function (): Anchor {
        return new Anchor();
    });
    $anchor = $sched->serviceOf($anchorAddr);
    T::ok($anchor instanceof Anchor, "anchor 建好了", "addr=" . $anchorAddr);
    T::ok($sched->stats()["ids_left"] > 16000000, "还没用过 1600 万个以上", "ids_left=" . $sched->stats()["ids_left"]);

    echo PHP_EOL . "== [F2] spawn + kill 整整一轮 24 位地址空间 ==" . PHP_EOL;
    $t0 = hrtime(true);
    $done = 0;
    $failed = "";
    $total = 16777216 + 10;
    // try 放在循环外：每轮进一次 try 在 AOT 下约 300ns，1600 万次就是 5 秒
    $i = 0;
    $hitAnchor = false;
    try {
        for ($i = 0; $i < $total; $i++) {
            $addr = $sched->spawnOf($factory);
            if ($addr === $anchorAddr) {
                $hitAnchor = true;
                break;
            }
            $sched->kill($addr);
            $done++;
        }
    } catch (\Throwable $e) {
        $failed = "第 " . ($i + 1) . " 次 spawn 失败: " . $e->getMessage();
    }
    if ($hitAnchor) {
        $failed = "第 " . ($i + 1) . " 次 spawn 拿到了 anchor 的地址";
    }
    $sec = intdiv(hrtime(true) - $t0, 1000000000) / 10;
    T::ok($failed === "", "16,777,226 次 spawn + kill 全部成功", $failed === "" ? "耗时 " . $sec . "s" : $failed);
    T::eq($done, $total, "一次不落地做完");

    $st = $sched->stats();
    echo "        spawned=" . $st["spawned"] . " killed=" . $st["killed"]
        . " id_wraps=" . $st["id_wraps"] . " ids_left=" . $st["ids_left"] . PHP_EOL;
    T::eq($st["id_wraps"], 1, "回绕了一次");
    T::eq($st["ids_left"], 0, "没用过的 id 为 0");

    echo PHP_EOL . "== [F3] 回绕没有撞上活着的 ==" . PHP_EOL;
    $after = $sched->serviceOf($anchorAddr);
    T::ok($after === $anchor, "anchor 还在原来的地址上，而且是同一个实例");
    $sched->deliverAs(0, $anchorAddr, 1, "ping");
    $sched->tick();
    if ($after instanceof Anchor) {
        T::eq($after->hits, 1, "anchor 照常收消息");
    }
    T::eq($sched->serviceCount(), 1, "除了 anchor 没有残留的服务（每个都 kill 了）");
}

function main(): void
{
    $mode = (string) getenv("ID_TEST");
    if ($mode === "full") {
        runFull();
    } else {
        runUnit();
    }
    echo PHP_EOL . "通过 " . T::$pass . " / 失败 " . T::$fail . PHP_EOL;
    if (T::$fail > 0) {
        exit(1);
    }
}
