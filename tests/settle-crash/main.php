<?php

/**
 * 崩溃验证：结算跑到一半被 `kill -9`，重启后账必须"不多不少"。
 *
 * 三种跑法（由 run.sh 调度）：
 *   settle <dir> <count>   连着结算 count 局，每局打一行进度
 *   verify <dir>           从日志重建，输出机器可读的一行结论
 *   repeat <dir> <count>   把同样一批房间**再结算一遍**（模拟重启后重放），不该多发钱
 *
 * 断言的核心不是"一条都没丢"——被 kill 在 append 之前的那一局注定不存在，
 * 那是正常的。要保证的是：
 *   1. 余额 == 100 × 日志里的结算条数（不多、不少）
 *   2. 房间号 1..K 连续无洞（说明只有尾部被截，没有中间缺失）
 *   3. 没有任何 id 出现两次（重放不会重复发钱）
 */

use Duel\Settle;
use TSF\EventLog\EventLog;

function logPath(string $dir): string
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . "/settle.log";
}

/** settle 模式：一路结算下去，直到被外部杀掉 */
function modeSettle(string $dir, int $count): void
{
    $path = logPath($dir);
    $log = new EventLog($path);
    $st = new Settle($log);

    // 重启后 $st 已经知道哪些房间结过账，所以这里从 1 开始也不会重复发钱
    for ($i = 1; $i <= $count; $i++) {
        $st->settle($i, 1000 + ($i % 3));
        // 每局都报一声，父进程据此知道"跑到第几局被杀的"
        fwrite(STDOUT, "settled " . $i . PHP_EOL);
        fflush(STDOUT);
        usleep(300);
    }
    $log->close();
}

/** verify 模式：只做重建与自检 */
function modeVerify(string $dir): void
{
    $path = logPath($dir);
    $log = new EventLog($path);
    $st = new Settle($log);

    $events = 0;
    $settles = 0;
    $net = 0;
    $ids = [];
    $dup = 0;
    $rooms = [];
    foreach ($log->replay() as $ev) {
        $events++;
        $id = (string) $ev["id"];
        if (isset($ids[$id])) {
            $dup++;
        }
        $ids[$id] = true;
        $d = is_array($ev["d"]) ? $ev["d"] : [];
        $amount = isset($d["amount"]) ? (int) $d["amount"] : 0;

        // 净额 = 入账 - 出账。之前这里无脑相加，
        // 一旦日志里出现 wallet.debit，加出来的就不是余额了
        if ($ev["k"] === Settle::KIND_DEBIT) {
            $net -= $amount;
        } else {
            $net += $amount;
        }

        if ($ev["k"] === Settle::KIND) {
            $settles++;
            $rooms[isset($d["room"]) ? (int) $d["room"] : 0] = true;
        }
    }

    // 房间号必须 1..settles 连续：日志只可能从尾部被截，中间缺一局就说明丢在中间了
    $gaps = 0;
    for ($i = 1; $i <= $settles; $i++) {
        if (!isset($rooms[$i])) {
            $gaps++;
        }
    }

    $walletTotal = $st->wallet()->total();

    printf(
        "events=%d balance=%d net=%d gaps=%d dup=%d torn=%d\n",
        $events,
        $walletTotal,
        $net,
        $gaps,
        $dup,
        $log->badLines()
    );
    $log->close();
}

/**
 * mix 模式：发奖与扣款交替，用来看"借贷混在一起时被杀，重放还能不能对上"。
 *
 * 每局：结算给 100，再花掉 30。所以 Net = 局数 × 70，而不是 × 100。
 */
function modeMix(string $dir, int $count): void
{
    $path = logPath($dir);
    $log = new EventLog($path);
    $st = new Settle($log);

    for ($i = 1; $i <= $count; $i++) {
        $uid = 1000 + ($i % 3);
        $st->settle($i, $uid);
        // 刚发了 100，扣 30 一定够——这里要验的是"重放"，不是"余额不足"
        $st->debit($uid, 30, "spend:" . $i);
        fwrite(STDOUT, "settled " . $i . PHP_EOL);
        fflush(STDOUT);
        usleep(300);
    }
    $log->close();
}

/** repeat 模式：拿同一批房间再结算一遍 */
function modeRepeat(string $dir, int $count): void
{
    $path = logPath($dir);
    $log = new EventLog($path);
    $st = new Settle($log);

    $paid = 0;
    for ($i = 1; $i <= $count; $i++) {
        if ($st->settle($i, 1000 + ($i % 3))) {
            $paid++;
        }
    }
    printf("newly_paid=%d\n", $paid);
    $log->close();
}

function main(): void
{
    // verify 要把整本日志重放进内存（EventLog::replay() 返回全量数组）：续跑完是 10 万局、
    // 借贷混合是 20 万条事件，远超 PHP 默认的 128M。本机 php.ini 是 512M 所以从没露头；
    // GitHub runner 上是默认值，「续跑后账目一致」两条红在这里（2026-11-27，两次 CI）。
    // 这个套件测的是崩溃之后账目对不对，不是内存上限——上限交给别处测（mem-pressure）。
    ini_set("memory_limit", "1G");

    $argv = $_SERVER["argv"];
    $mode = isset($argv[1]) ? (string) $argv[1] : "help";
    $dir = isset($argv[2]) ? (string) $argv[2] : "/tmp/settle_crash";
    $n = isset($argv[3]) ? (int) $argv[3] : 0;

    if ($mode === "settle") {
        modeSettle($dir, $n > 0 ? $n : 100000);
        return;
    }
    if ($mode === "verify") {
        modeVerify($dir);
        return;
    }
    if ($mode === "repeat") {
        modeRepeat($dir, $n > 0 ? $n : 50);
        return;
    }
    if ($mode === "mix") {
        modeMix($dir, $n > 0 ? $n : 100000);
        return;
    }
    echo "用法: settle_crash settle <dir> <count> | verify <dir> | repeat <dir> <count> | mix <dir> <count>" . PHP_EOL;
}
