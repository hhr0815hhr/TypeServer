<?php

use TSF\Script\Context;
use TSF\Script\Script;

/**
 * 脚本层（内嵌 PHP 解释器）vs AOT 直调的成本标定。
 *
 * 要回答的问题只有一个：**一条业务逻辑放脚本层，比放 AOT 贵多少。**
 * 没有这个数字，L1/L2 的边界只能靠"慢 10-50 倍"这个估算值猜。
 *
 * 四组用例对应四种真实形状：
 *   1 noop    —— 跨边界本身的固定成本
 *   2 fields  —— 白名单字段读写（现状的脚本形态）
 *   3 view    —— 10 次方法调用（P0-2 的类型化视图目标形态）
 *   4 state   —— 私有状态累加 100 次
 *
 * 口径：每组跑 5 轮，每轮 N 次，取**每轮总耗时的中位数**再除以 N。
 * 不做单次采样——`bench/REPORT.md` §二 已经证明单次采样会被机器漂移带偏 30%。
 */

final class Player
{
    public int $uid = 1001;

    public int $lv = 50;

    public int $gold = 500;

    public int $hp = 400;

    public int $hpMax = 1000;

    public int $buffN = 3;

    public function level(): int
    {
        return $this->lv;
    }

    public function gold(): int
    {
        return $this->gold;
    }

    public function hpRatioPct(): int
    {
        return intdiv($this->hp * 100, $this->hpMax);
    }

    public function buffCount(): int
    {
        return $this->buffN;
    }
}

/** 跑 $rounds 轮，每轮 $n 次，返回"每轮总纳秒"的中位数 */
function timeRounds(int $rounds, int $n, \Closure $fn): int
{
    $totals = [];
    for ($r = 0; $r < $rounds; $r++) {
        $t0 = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $fn();
        }
        $totals[] = hrtime(true) - $t0;
    }
    // 轮数少，插入排序足够，也避免 sort() 在 AOT 下对 array 的类型推断问题
    $m = count($totals);
    for ($i = 1; $i < $m; $i++) {
        $v = $totals[$i];
        $j = $i - 1;
        while ($j >= 0 && $totals[$j] > $v) {
            $totals[$j + 1] = $totals[$j];
            $j--;
        }
        $totals[$j + 1] = $v;
    }
    return $totals[intdiv($m, 2)];
}

function report(string $name, int $scriptNs, int $aotNs): void
{
    $ratio = $aotNs > 0 ? intdiv($scriptNs * 10, $aotNs) : 0;
    $ratioX = intdiv($ratio, 10);
    $ratioFrac = $ratio % 10;
    echo "  " . $name . "  script=" . $scriptNs . "ns  aot=" . $aotNs . "ns  ratio="
        . $ratioX . "." . $ratioFrac . "x" . PHP_EOL;
}

function main(): void
{
    $path = __DIR__ . "/scripts/probe.php";
    $script = new Script($path);
    if (!$script->load()) {
        echo "FAIL 脚本加载失败: " . $path . PHP_EOL;
        return;
    }

    $p = new Player();
    // view 用独立对象：fields 用例会反复写 gold，共用实例会让自检值对不上
    $pView = new Player();
    $ctx = new Context($p, ["uid", "lv", "gold"], ["gold"]);
    $ctx->attachState($script->state());

    // 次数按"每次成本"反推：空调用假设 ~2µs，2 万次约 40ms/轮，5 轮 200ms
    $n = 20000;
    $rounds = 5;

    echo "== 脚本层 vs AOT ==" . PHP_EOL;
    echo "  每组 " . $rounds . " 轮 × " . $n . " 次，取每轮总耗时中位数" . PHP_EOL . PHP_EOL;

    // 1. 空调用
    $scriptNoop = intdiv(timeRounds($rounds, $n, function () use ($script, $p): int {
        $script->call("noop", $p);
        return 0;
    }), $n);
    $aotNoop = intdiv(timeRounds($rounds, $n, function () use ($p): int {
        return $p->level();
    }), $n);

    // 2. 字段读三个写一个
    $scriptFields = intdiv(timeRounds($rounds, $n, function () use ($script, $ctx): int {
        $script->callWithContext("fields", $ctx);
        return 0;
    }), $n);
    $aotFields = intdiv(timeRounds($rounds, $n, function () use ($p): int {
        $a = $p->uid;
        $b = $p->lv;
        $c = $p->gold;
        $p->gold = $c + 1;
        return $a + $b + $c;
    }), $n);

    // 3. 十次方法调用（每次 4 个方法 = 40 次调用）。
    //    先校验一次返回值：脚本里调错方法会静默返回 null，那样测出来的"很快"是假的。
    $viewProbe = $script->call("view", $pView);
    if (!is_int($viewProbe)) {
        echo "FAIL view 用例没有返回 int（脚本调用静默失败）" . PHP_EOL;
        return;
    }
    // 期望 10 × (50 + 500 + 40 + 3) = 5930；不对说明脚本改错了，数字不可信
    echo "  view 自检: " . $viewProbe . "（期望 5930）" . PHP_EOL . PHP_EOL;
    $scriptView = intdiv(timeRounds($rounds, $n, function () use ($script, $pView): int {
        $script->call("view", $pView);
        return 0;
    }), $n);
    $aotView = intdiv(timeRounds($rounds, $n, function () use ($pView): int {
        $s = 0;
        for ($i = 0; $i < 10; $i++) {
            $s += $pView->level() + $pView->gold() + $pView->hpRatioPct() + $pView->buffCount();
        }
        return $s;
    }), $n);

    // 3b. callWithContext 相对 call 的纯固定开销（同一个空入口，只差包装）
    $scriptNoopCtx = intdiv(timeRounds($rounds, $n, function () use ($script, $ctx): int {
        $script->callWithContext("noop", $ctx);
        return 0;
    }), $n);

    // 3c. 同一逻辑换成"每次调用先落局部变量"。
    // 用来判断 3 view 的 0.5x 是真实结论还是表达式嵌套导致的（不查就写进文档＝传播错误）
    $aotViewSplit = intdiv(timeRounds($rounds, $n, function () use ($pView): int {
        $s = 0;
        for ($i = 0; $i < 10; $i++) {
            $a = $pView->level();
            $b = $pView->gold();
            $c = $pView->hpRatioPct();
            $d = $pView->buffCount();
            $s += $a + $b + $c + $d;
        }
        return $s;
    }), $n);

    // 4. 私有状态累加 100 次（脚本侧本身慢，用 2000 次）
    $nState = 2000;
    $scriptState = intdiv(timeRounds($rounds, $nState, function () use ($script, $ctx): int {
        $script->callWithContext("state", $ctx);
        return 0;
    }), $nState);
    // 对照必须是同样的哈希操作，否则 100 次自增会被编译器折叠成常数（实测 40ns/100 次）
    $aotState = intdiv(timeRounds($rounds, $nState, function (): int {
        $arr = ["k" => 0];
        for ($i = 0; $i < 100; $i++) {
            $arr["k"] = $arr["k"] + 1;
        }
        return $arr["k"];
    }), $nState);

    report("1 noop   ", $scriptNoop, $aotNoop);
    report("2 fields ", $scriptFields, $aotFields);
    report("3 view   ", $scriptView, $aotView);
    report("4 state  ", $scriptState, $aotState);
    echo "  1b noopCtx" . "  script=" . $scriptNoopCtx . "ns  (相对 1 noop 的包装开销 "
        . ($scriptNoopCtx - $scriptNoop) . "ns，含 zend_set_timeout)" . PHP_EOL;
    echo "  3b viewSplit aot=" . $aotViewSplit . "ns  (同一逻辑拆开写，判断 0.5x 是否写法导致)"
        . PHP_EOL;
}
