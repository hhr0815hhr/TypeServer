<?php

/**
 * 金币结算的语义验证（T6-05 之后补的那一项：事件日志接进真实业务）。
 *
 * 要证明的四件事：
 *   1. **一局只发一次**——`duel:<房间号>` 是业务主键，重复调用/重启重放都不多发
 *   2. **余额是派生的**——丢掉内存状态，从日志重放就能追平
 *   3. **日志先行**——半截行/坏行只会让余额落后，不会让余额领先于账
 *   4. 平局、非法参数不写垃圾事件（日志里每一条都该是真的结算）
 */

use Duel\Settle;
use TSF\EventLog\EventLog;

const DIR = "/tmp/settle_test";

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

    public static function eq(int|string|bool $got, int|string|bool $want, string $label): void
    {
        self::ok($got === $want, $label, "got=" . var_export($got, true) . " want=" . var_export($want, true));
    }
}

/** 每个用例一个干净目录，避免互相污染 */
function fresh(string $name): string
{
    $dir = DIR . "/" . $name;
    if (is_dir($dir)) {
        $files = glob($dir . "/*");
        if (is_array($files)) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
    } else {
        @mkdir($dir, 0777, true);
    }
    return $dir . "/settle.log";
}

/** 日志文件里有多少条非空行 */
function lineCount(string $path): int
{
    if (!is_file($path)) {
        return 0;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        return 0;
    }
    $n = 0;
    foreach (explode("\n", $raw) as $line) {
        if (trim($line) !== "") {
            $n++;
        }
    }
    return $n;
}

function caseBasic(): void
{
    echo "== [1] 一局结算 ==" . PHP_EOL;
    $path = fresh("basic");
    $log = new EventLog($path);
    $st = new Settle($log);

    T::ok($st->settle(1, 1001), "第一次结算返回 true");
    T::eq($st->balanceOf(1001), Settle::REWARD, "赢家拿到 " . Settle::REWARD);
    T::eq($st->balanceOf(1002), 0, "输家没有钱");
    T::eq(lineCount($path), 1, "日志里 1 条事件");
    T::ok($st->isSettled(1), "房间 1 标记为已结算");
    T::ok(!$st->isSettled(2), "房间 2 没有标记");

    // 事件内容：id 必须是业务主键，不是序号
    $raw = file_get_contents($path);
    $raw = is_string($raw) ? $raw : "";
    T::ok(str_contains($raw, "\"id\":\"duel:1\""), "事件 id 是 duel:<房间号>", $raw);
    T::ok(str_contains($raw, "\"k\":\"duel.settled\""), "事件类型是 duel.settled");
    T::ok(str_contains($raw, "\"winner\":1001"), "事件里带赢家");
    T::ok(str_contains($raw, "\"amount\":100"), "事件里带金额");
    $log->close();
}

function caseIdempotent(): void
{
    echo "== [2] 重复结算不多发 ==" . PHP_EOL;
    $path = fresh("idem");
    $log = new EventLog($path);
    $st = new Settle($log);

    T::ok($st->settle(7, 2001), "首次结算成功");
    T::ok(!$st->settle(7, 2001), "同一局再结算返回 false");
    T::ok(!$st->settle(7, 2002), "同一局换个赢家也返回 false（房间已经结过）");
    T::eq($st->balanceOf(2001), Settle::REWARD, "只发了一次");
    T::eq($st->balanceOf(2002), 0, "第二个人没被发钱");
    T::eq(lineCount($path), 1, "日志仍然只有 1 条");
    $log->close();
}

function caseSkip(): void
{
    echo "== [3] 平局与非法参数不写日志 ==" . PHP_EOL;
    $path = fresh("skip");
    $log = new EventLog($path);
    $st = new Settle($log);

    T::ok(!$st->settle(10, -1), "平局（winner=-1）不结算");
    T::ok(!$st->settle(11, 0), "winner=0 不结算");
    T::ok(!$st->settle(12, 3001, 0), "金额为 0 不结算");
    T::ok(!$st->settle(0, 3001), "房间号为 0 不结算");
    T::eq(lineCount($path), 0, "一条垃圾事件都没写");
    T::eq($st->stats()["settled"], 0, "统计里结算数为 0");
    $log->close();
}

function caseRebuild(): void
{
    echo "== [4] 丢掉内存状态，从日志重建 ==" . PHP_EOL;
    $path = fresh("rebuild");
    $log = new EventLog($path);
    $st = new Settle($log);
    $st->settle(1, 1001);
    $st->settle(2, 1001);
    $st->settle(3, 1002);
    $log->close();

    // 模拟进程重启：新实例、同一个文件
    $log2 = new EventLog($path);
    $st2 = new Settle($log2);
    T::eq($st2->balanceOf(1001), Settle::REWARD * 2, "1001 重建后是 200");
    T::eq($st2->balanceOf(1002), Settle::REWARD, "1002 重建后是 100");
    T::ok($st2->isSettled(2), "已结算集合也重建了");
    T::eq($st2->stats()["settled"], 3, "折叠出 3 笔结算");

    // 重建之后继续结算，接着往下写
    T::ok($st2->settle(4, 1002), "重建后可以继续结算");
    T::eq($st2->balanceOf(1002), Settle::REWARD * 2, "1002 变成 200");
    $log2->close();
}

function caseRebuildTwice(): void
{
    echo "== [5] 重复重建不翻倍 ==" . PHP_EOL;
    $path = fresh("twice");
    $log = new EventLog($path);
    $st = new Settle($log);
    $st->settle(1, 1001);
    $st->settle(2, 1001);

    $st->rebuildFrom();
    T::eq($st->balanceOf(1001), Settle::REWARD * 2, "重建一次后仍是 200");
    $st->rebuildFrom();
    T::eq($st->balanceOf(1001), Settle::REWARD * 2, "重建两次后仍是 200（reset 生效）");
    $log->close();
}

function caseMany(): void
{
    echo "== [6] 多局多人 ==" . PHP_EOL;
    $path = fresh("many");
    $log = new EventLog($path);
    $st = new Settle($log);

    $sum = 0;
    for ($i = 1; $i <= 100; $i++) {
        $winner = 1000 + ($i % 7);
        if ($st->settle($i, $winner)) {
            $sum += Settle::REWARD;
        }
    }
    T::eq($st->wallet()->total(), $sum, "总余额等于实发总额");
    T::eq($st->stats()["settled"], 100, "100 笔结算");

    // 每个赢家应得 = 他赢的局数 × REWARD
    $expect = [];
    for ($i = 1; $i <= 100; $i++) {
        $w = 1000 + ($i % 7);
        $expect[$w] = (isset($expect[$w]) ? $expect[$w] : 0) + Settle::REWARD;
    }
    $bad = 0;
    foreach ($expect as $uid => $v) {
        if ($st->balanceOf($uid) !== $v) {
            $bad++;
        }
    }
    T::eq($bad, 0, "每个人的余额都对");
    $log->close();
}

function caseTornLine(): void
{
    echo "== [7] 半截行（崩溃留下的）==" . PHP_EOL;
    $path = fresh("torn");
    $log = new EventLog($path);
    $st = new Settle($log);
    $st->settle(1, 1001);
    $st->settle(2, 1001);
    $log->close();

    // 模拟"写到一半被杀"：追加一段没有换行的半条 JSON
    file_put_contents($path, "{\"s\":3,\"id\":\"duel:3\",\"k\":\"duel.sett", FILE_APPEND);

    $log2 = new EventLog($path);
    $st2 = new Settle($log2);
    T::eq($st2->balanceOf(1001), Settle::REWARD * 2, "只认完整的两条，余额 200");
    T::ok(!$st2->isSettled(3), "半截那条不算结算");

    // 修掉坏尾并继续写：序号要接在最后一条好记录之后
    $cut = $log2->repair();
    T::ok($cut >= 0, "repair 返回截掉的行数", (string) $cut);
    T::ok($st2->settle(3, 1001), "修好之后还能继续结算");
    $log2->close();

    $log3 = new EventLog($path);
    $st3 = new Settle($log3);
    T::eq($st3->balanceOf(1001), Settle::REWARD * 3, "重启后是 300");
    T::eq($st3->stats()["settled"], 3, "3 笔结算");
    $log3->close();
}

function caseDuplicateId(): void
{
    echo "== [8] 日志里同一个 id 出现两次 ==" . PHP_EOL;
    $path = fresh("dup");
    $log = new EventLog($path);
    $st = new Settle($log);
    $st->settle(1, 1001);
    $log->close();

    // 人工再塞一条同 id 的事件（模拟"重放工具把同一批写了两遍"）
    $line = "{\"s\":2,\"id\":\"duel:1\",\"k\":\"duel.settled\",\"t\":1,\"d\":{\"room\":1,\"winner\":1001,\"amount\":100}}\n";
    file_put_contents($path, $line, FILE_APPEND);

    $log2 = new EventLog($path);
    $st2 = new Settle($log2);
    T::eq($st2->balanceOf(1001), Settle::REWARD, "同 id 只折叠一次，仍是 100");
    T::eq($st2->stats()["replayed"], 1, "折叠计数按去重后算");
    $log2->close();
}

function caseOrder(): void
{
    echo "== [9] 日志先行：append 在 credit 之前 ==" . PHP_EOL;
    // 直接看实现顺序不好断言，所以用"崩在中间"的办法验：
    // 只 append 不 credit（模拟 credit 之前被杀），重启后余额必须是**已到账**的
    $path = fresh("order");
    $log = new EventLog($path);
    $log->append("duel:1", "duel.settled", ["room" => 1, "winner" => 1001, "amount" => 100]);
    $log->close();

    $log2 = new EventLog($path);
    $st = new Settle($log2);
    T::eq($st->balanceOf(1001), 100, "重启后重放把没来得及加的钱补上了");
    $log2->close();
}

function main(): void
{
    @mkdir(DIR, 0777, true);
    caseBasic();
    caseIdempotent();
    caseSkip();
    caseRebuild();
    caseRebuildTwice();
    caseMany();
    caseTornLine();
    caseDuplicateId();
    caseOrder();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
    exit(T::$fail === 0 ? 0 : 1);
}
