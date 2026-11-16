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
    $total = 0;
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
        $d = $ev["d"];
        $total += isset($d["amount"]) ? (int) $d["amount"] : 0;
        $rooms[isset($d["room"]) ? (int) $d["room"] : 0] = true;
    }

    // 房间号必须 1..events 连续：日志只可能从尾部被截，中间缺一局就说明丢在中间了
    $gaps = 0;
    for ($i = 1; $i <= $events; $i++) {
        if (!isset($rooms[$i])) {
            $gaps++;
        }
    }

    $walletTotal = $st->wallet()->total();

    printf(
        "events=%d balance=%d sum=%d gaps=%d dup=%d torn=%d\n",
        $events,
        $walletTotal,
        $total,
        $gaps,
        $dup,
        $log->badLines()
    );
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
    echo "用法: settle_crash settle <dir> <count> | verify <dir> | repeat <dir> <count>" . PHP_EOL;
}
