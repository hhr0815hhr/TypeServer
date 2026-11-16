<?php

/**
 * pkg/fsm 测试。状态机是独立能力，所以这里只测状态机本身，
 * 不牵扯房间、Service、网络。
 */

use TSF\Fsm\Machine;

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

    public static function eq(int|string|float|bool $got, int|string|float|bool $want, string $label): void
    {
        self::ok($got === $want, $label, "got=" . var_export($got, true) . " want=" . var_export($want, true));
    }
}

const IDLE = 1;
const RUNNING = 2;
const DONE = 3;

function makeFsm(): Machine
{
    $m = new Machine(IDLE);
    $m->allow(IDLE, "go", RUNNING)
        ->allow(RUNNING, "stop", DONE)
        ->allow(RUNNING, "reset", IDLE);
    return $m;
}

/** 1. 基本转移 */
function caseBasic(): void
{
    echo "== [1] 基本转移 ==" . PHP_EOL;

    $m = makeFsm();
    T::eq($m->state(), IDLE, "初始状态");

    T::ok($m->can("go"), "IDLE 能 go");
    T::ok(!$m->can("stop"), "IDLE 不能 stop");

    T::ok($m->fire("go"), "go 成功");
    T::eq($m->state(), RUNNING, "到 RUNNING");

    T::ok($m->fire("stop"), "stop 成功");
    T::eq($m->state(), DONE, "到 DONE");

    // DONE 是终态：没有出边
    T::ok(!$m->fire("go"), "终态不再响应");
    T::eq($m->state(), DONE, "状态没变");
}

/** 2. 非法转移 */
function caseIllegal(): void
{
    echo "== [2] 非法转移 ==" . PHP_EOL;

    $m = makeFsm();
    T::ok(!$m->fire("stop"), "IDLE 直接 stop 被拒");
    T::eq($m->state(), IDLE, "被拒后状态不变");
    T::ok(!$m->fire("不存在的"), "未知事件被拒");
    T::eq($m->state(), IDLE, "未知事件也不改状态");

    $empty = new Machine(IDLE);
    T::ok(!$empty->fire("go"), "空转移表一律拒绝");
    T::ok($empty->events() === [], "空转移表没有可选事件");
}

/** 3. 回调顺序 */
function caseHooks(): void
{
    echo "== [3] 进入/退出钩子 ==" . PHP_EOL;

    $log = "";
    $m = new Machine(IDLE);
    $m->allow(IDLE, "go", RUNNING);
    $m->onExit(IDLE, function (int $from, int $to) use (&$log): void {
        $log .= "exit" . $from . ";";
    });
    $m->onEnter(RUNNING, function (int $from, int $to) use (&$log): void {
        $log .= "enter" . $to . ";";
    });

    $m->fire("go");
    T::eq($log, "exit1;enter2;", "先 exit 旧状态，再 enter 新状态");

    // 没有钩子的转移不报错
    $m2 = new Machine(IDLE);
    $m2->allow(IDLE, "go", RUNNING);
    T::ok($m2->fire("go"), "无钩子也能转移");

    // 非法转移不触发任何钩子
    $log2 = "";
    $m3 = new Machine(IDLE);
    $m3->allow(IDLE, "go", RUNNING);
    $m3->onEnter(RUNNING, function (int $f, int $t) use (&$log2): void {
        $log2 .= "x";
    });
    $m3->fire("stop");
    T::eq($log2, "", "非法转移不触发钩子");
}

/** 4. force 与链式声明 */
function caseForce(): void
{
    echo "== [4] force 与重复声明 ==" . PHP_EOL;

    $m = makeFsm();
    $m->force(DONE);
    T::eq($m->state(), DONE, "force 直接设置状态");

    // force 不触发钩子
    $hit = 0;
    $m2 = new Machine(IDLE);
    $m2->onEnter(RUNNING, function (int $f, int $t) use (&$hit): void {
        $hit++;
    });
    $m2->force(RUNNING);
    T::eq($hit, 0, "force 不触发进入钩子");

    // 重复声明覆盖
    $m3 = new Machine(IDLE);
    $m3->allow(IDLE, "go", RUNNING);
    $m3->allow(IDLE, "go", DONE);   // 覆盖
    $m3->fire("go");
    T::eq($m3->state(), DONE, "后声明的转移覆盖前面的");
}

/** 5. events() */
function caseEvents(): void
{
    echo "== [5] 可选事件列表 ==" . PHP_EOL;

    $m = makeFsm();
    $ev = $m->events();
    T::eq(count($ev), 1, "IDLE 只有 1 个可选事件");
    T::ok(in_array("go", $ev, true), "包含 go");

    $m->fire("go");
    $ev2 = $m->events();
    T::eq(count($ev2), 2, "RUNNING 有 2 个");
    T::ok(in_array("stop", $ev2, true), "包含 stop");
    T::ok(in_array("reset", $ev2, true), "包含 reset");
}

/** 6. 性能：状态机通常是高频对象 */
function casePerf(): void
{
    echo "== [6] 性能 ==" . PHP_EOL;

    $n = 200000;
    $m = new Machine(IDLE);
    $m->allow(IDLE, "go", RUNNING)->allow(RUNNING, "reset", IDLE);

    $t0 = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $m->fire("go");
        $m->fire("reset");
    }
    $t1 = hrtime(true);
    $per = ($t1 - $t0) / ($n * 2);
    echo "  fire(): " . number_format($per, 0) . " ns/次" . PHP_EOL;
    T::ok($per < 2000, "单次转移 < 2 µs", number_format($per, 0) . " ns");

    // 非法转移也走同一条查找路径，不会更慢
    $bad = new Machine(DONE);
    $t2 = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $bad->fire("go");
    }
    $t3 = hrtime(true);
    echo "  fire() 被拒: " . number_format(($t3 - $t2) / $n, 0) . " ns/次" . PHP_EOL;

    $t4 = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $bad->can("go");
    }
    $t5 = hrtime(true);
    echo "  can(): " . number_format(($t5 - $t4) / $n, 0) . " ns/次" . PHP_EOL;

    // 造一台状态机的成本（房间里每个都要一台）
    $t6 = hrtime(true);
    for ($i = 0; $i < 1000; $i++) {
        $x = makeFsm();
    }
    $t7 = hrtime(true);
    echo "  建模（3 条边）: " . number_format(($t7 - $t6) / 1000, 0) . " ns/个" . PHP_EOL;
}

function main(): void
{
    caseBasic();
    caseIllegal();
    caseHooks();
    caseForce();
    caseEvents();
    casePerf();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
