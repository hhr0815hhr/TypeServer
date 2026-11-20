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
use TSF\EventLog\Checkpoint;
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


function caseDebit(): void
{
    echo "== [10] 扣款：够就扣、不够就拒、拒绝不写日志 ==" . PHP_EOL;
    $path = fresh("debit");
    $log = new EventLog($path);
    $st = new Settle($log);

    T::ok($st->credit(3001, 100, "init"), "先充值 100");
    T::eq($st->balanceOf(3001), 100, "余额 100");
    T::ok($st->debit(3001, 30, "buy-1"), "扣 30 成功");
    T::eq($st->balanceOf(3001), 70, "余额 70");
    T::eq(lineCount($path), 2, "日志 2 条（充值 + 扣款）");

    T::ok(!$st->debit(3001, 71, "buy-2"), "余额不足返回 false");
    T::eq($st->balanceOf(3001), 70, "拒绝后余额一分没动");
    T::eq(lineCount($path), 2, "**拒绝不写日志**");

    T::ok($st->debit(3001, 70, "buy-3"), "正好扣到 0 可以");
    T::eq($st->balanceOf(3001), 0, "余额 0");
    T::ok(!$st->debit(3001, 1, "buy-4"), "0 余额再扣 1 被拒");
    T::ok($st->balanceOf(3001) >= 0, "余额不会变成负数");
    $log->close();
}

function caseDebitIdempotent(): void
{
    echo "== [11] 扣款幂等：同一 ref 只扣一次 ==" . PHP_EOL;
    $path = fresh("debit-idem");
    $log = new EventLog($path);
    $st = new Settle($log);

    $st->credit(4001, 100, "init");
    T::ok($st->debit(4001, 10, "order-1"), "首次扣款成功");
    T::ok(!$st->debit(4001, 10, "order-1"), "同一 ref 再扣返回 false");
    T::eq($st->balanceOf(4001), 90, "只扣了一次");
    T::eq(lineCount($path), 2, "日志仍 2 条（充值 + 1 笔扣款）");
    T::ok($st->isDebited("order-1"), "isDebited 查得到");
    T::ok(!$st->isDebited("order-2"), "没扣过的 ref 查不到");

    // 不同 ref 是不同的一笔，可以继续扣
    T::ok($st->debit(4001, 90, "order-2"), "换一个 ref 继续扣");
    T::eq($st->balanceOf(4001), 0, "扣光");
    T::eq(lineCount($path), 3, "日志 3 条");

    // 空 ref 不允许：那等于放弃幂等
    T::ok(!$st->debit(4001, 1, ""), "空 ref 被拒");
    $log->close();
}

function caseDebitRebuild(): void
{
    echo "== [12] 借贷混合后重启：余额与去重表都追平 ==" . PHP_EOL;
    $path = fresh("debit-rebuild");

    $log = new EventLog($path);
    $st = new Settle($log);
    $st->credit(5001, 100, "c1");
    $st->debit(5001, 40, "d1");
    T::ok($st->settle(1, 5002), "一局结算给 5002 发奖");
    T::ok($st->debit(5002, 60, "d2"), "再把 5002 扣 60");
    $log->close();

    // 模拟重启：新进程、同一份日志
    $log2 = new EventLog($path);
    $st2 = new Settle($log2);
    T::eq($st2->balanceOf(5001), 60, "5001 重放后 60");
    T::eq($st2->balanceOf(5002), 40, "5002 重放后 40");
    T::ok($st2->isDebited("d1"), "去重表也回来了");
    T::ok(!$st2->debit(5001, 1, "d1"), "重启后同一个 ref 仍然幂等");
    T::eq($st2->balanceOf(5001), 60, "余额没被重复扣");

    $st2->rebuildFrom();
    T::eq($st2->balanceOf(5001), 60, "再重放一次不翻倍");
    $log2->close();
}

function caseReconcile(): void
{
    echo "== [13] 对账：平、篡改内存、日志被改 ==" . PHP_EOL;
    $path = fresh("reconcile");
    $log = new EventLog($path);
    $st = new Settle($log);
    $st->credit(6001, 100, "c1");
    $st->debit(6001, 30, "d1");

    $rep = $st->reconcile();
    T::ok($rep["ok"], "正常账平");
    T::eq($rep["total_mem"], 70, "内存合计 70");
    T::eq($rep["total_log"], 70, "日志合计 70");
    T::eq(count($rep["diff"]), 0, "没有差异");

    // 模拟"有人绕过 debit 直接改内存"
    $st->wallet()->credit(6001, 5);
    $rep2 = $st->reconcile();
    T::ok(!$rep2["ok"], "篡改内存后对账能发现");
    T::eq(count($rep2["diff"]), 1, "1 个 uid 有差异");
    T::eq($rep2["diff"][0]["mem"], 75, "内存侧 75");
    T::eq($rep2["diff"][0]["log"], 70, "日志侧 70");
    T::eq($rep2["total_mem"] - $rep2["total_log"], 5, "两边差 5");

    // 模拟"日志被改"：往日志里塞一条余额根本不够的扣款
    $log->append("debit:tamper", "wallet.debit", ["uid" => 6001, "amount" => 99999, "ref" => "tamper"]);
    $rep3 = $st->reconcile();
    T::ok(in_array("debit:tamper", $rep3["bad_debits"], true), "重放时余额不足的扣款被抓出来");
    T::ok(!$rep3["ok"], "存在坏扣款时对账不平");
    $log->close();
}


function caseMergeNodeLogs(): void
{
    echo "== [14] 多节点日志：归并读、跨节点对账 ==" . PHP_EOL;
    $p0 = fresh("node0");
    $p1 = fresh("node1");

    // 两个节点各写各的文件（房间号按节点分段）
    $l0 = new EventLog($p0);
    $s0 = new Settle($l0);
    $s0->settle(1, 7001);
    $s0->settle(2, 7002);
    $l0->close();

    $l1 = new EventLog($p1);
    $s1 = new Settle($l1);
    $s1->settle(1000000001, 7001);
    $l1->close();

    // 读侧把两个文件归并
    $r0 = new EventLog($p0);
    $r1 = new EventLog($p1);
    $both = new Settle($r0, null, [$r0, $r1]);

    T::eq($both->balanceOf(7001), 200, "7001 跨节点两局都算上");
    T::eq($both->balanceOf(7002), 100, "7002 一局");
    T::ok($both->isSettled(1), "node 0 的房间在去重表里");
    T::ok($both->isSettled(1000000001), "node 1 的房间也在");

    $rep = $both->reconcile();
    T::ok($rep["ok"], "跨节点对账平");
    T::eq($rep["events"], 3, "归并后 3 条事件");
    T::eq($rep["players"], 2, "2 个玩家");

    // 归并必须**确定**：重放两次结果一样，否则对账会出现假警报
    $before = $both->wallet()->all();
    $both->rebuildFrom();
    T::ok($both->wallet()->all() === $before, "重放两次结果一致（归并顺序确定）");
    $r0->close();
    $r1->close();
}


function caseCheckpoint(): void
{
    echo "== [15] 检查点：恢复等价 + 截断 ==" . PHP_EOL;
    $path = fresh("ckpt");
    $log = new EventLog($path);
    // 每 20 条压一次，方便在测试里触发
    $cp = new Checkpoint($path . ".ckpt");
    $st = new Settle($log, null, [], $cp, 20);

    $sum = 0;
    for ($i = 1; $i <= 100; $i++) {
        $winner = 1000 + ($i % 5);
        if ($st->settle($i, $winner)) {
            $sum += Settle::REWARD;
        }
    }
    T::eq($st->wallet()->total(), $sum, "压检查点不影响余额");
    T::ok($cp->exists(), "检查点已写出");
    T::ok($cp->seq() > 0, "检查点记录了水位", "seq=" . $cp->seq());
    $left = count($log->replay());
    T::ok($left < 100, "老日志已被截断", "left=" . $left);
    // 第一次必须写基座，之后只写增量（100 条 / 每 20 条一次 = 1 基座 + 4 增量）
    T::ok($cp->deltaCount() === 4, "首次写基座，其余写增量", "deltas=" . $cp->deltaCount());

    $before = $st->wallet()->all();
    $log->close();

    // 模拟重启：检查点在，日志只剩尾巴
    $log2 = new EventLog($path);
    $cp2 = new Checkpoint($path . ".ckpt");
    $st2 = new Settle($log2, null, [], $cp2, 20);
    T::eq($st2->wallet()->total(), $sum, "重启后总余额一致");
    $same = true;
    foreach ($before as $uid => $bal) {
        if ($st2->balanceOf($uid) !== $bal) {
            $same = false;
        }
    }
    T::ok($same, "逐人余额一致");
    T::ok($st2->isSettled(100), "去重表也恢复了");
    T::ok(!$st2->settle(100, 1000), "已结算的房间不会再发一次奖");
    T::eq($st2->stats()["settled"], 100, "结算笔数从检查点恢复");
    $log2->close();
}


function caseSeenWindow(): void
{
    echo "== [16] 去重表有界裁剪 ==" . PHP_EOL;
    $path = fresh("seen");
    $log = new EventLog($path);
    $cp = new Checkpoint($path . ".ckpt");
    // 窗口 100、每 50 条压一次：跑 400 局后去重表应稳定在 100 上下，而不是 400
    $st = new Settle($log, null, [], $cp, 50, 100);

    $sum = 0;
    for ($i = 1; $i <= 400; $i++) {
        if ($st->settle($i, 1000 + ($i % 3))) {
            $sum += Settle::REWARD;
        }
    }
    T::eq($st->wallet()->total(), $sum, "裁剪不影响余额");
    T::ok($st->seenCount() <= 150, "去重表被裁到窗口附近", "seen=" . $st->seenCount());
    T::ok($st->seenCount() > 50, "裁剪不会把窗口内的也删掉", "seen=" . $st->seenCount());

    // 窗口内仍然幂等
    T::ok(!$st->settle(400, 1000), "最近的房间仍然挡住重复结算");
    T::ok(!$st->settle(399, 1001), "倒数第二个也挡得住");

    // 窗口外的旧 id 已经忘掉：这是刻意的取舍，写清楚
    T::ok(!$st->isSettled(1), "很久以前的房间号已被裁掉（刻意）");

    $log->close();

    // 重启：余额仍要对（裁剪只忘 id，不动钱）
    $log2 = new EventLog($path);
    $cp2 = new Checkpoint($path . ".ckpt");
    $st2 = new Settle($log2, null, [], $cp2, 50, 100);
    T::eq($st2->wallet()->total(), $sum, "重启后余额一致");
    T::ok($st2->isSettled(400), "重启后最近的 id 还在");
    T::eq($st2->stats()["settled"], 400, "结算笔数不因裁剪而丢");
    $log2->close();
}


function caseCompact(): void
{
    echo "== [17] 增量定期压实 ==" . PHP_EOL;
    $path = fresh("compact");
    $log = new EventLog($path);
    $cp = new Checkpoint($path . ".ckpt");
    // 每 10 条一个检查点、每 5 个增量压实一次
    $st = new Settle($log, null, [], $cp, 10, 200000, 5);

    $sum = 0;
    for ($i = 1; $i <= 200; $i++) {
        if ($st->settle($i, 2000 + ($i % 4))) {
            $sum += Settle::REWARD;
        }
    }
    T::eq($st->wallet()->total(), $sum, "压实不影响余额");
    T::ok($cp->deltaCount() <= 5, "增量个数被压在阈内", "deltas=" . $cp->deltaCount());
    T::ok($cp->deltaCount() > 0, "确实在写增量（不是每次都全量）", "deltas=" . $cp->deltaCount());
    $log->close();

    $log2 = new EventLog($path);
    $cp2 = new Checkpoint($path . ".ckpt");
    $st2 = new Settle($log2, null, [], $cp2, 10, 200000, 5);
    T::eq($st2->wallet()->total(), $sum, "基座+增量恢复后余额一致");
    T::ok($st2->isSettled(200), "最近的 id 还在");
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
    caseDebit();
    caseDebitIdempotent();
    caseDebitRebuild();
    caseReconcile();
    caseMergeNodeLogs();
    caseCheckpoint();
    caseSeenWindow();
    caseCompact();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
    exit(T::$fail === 0 ? 0 : 1);
}
