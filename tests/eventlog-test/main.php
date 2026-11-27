<?php

/**
 * pkg/eventlog 测试。
 *
 * 重点不是"能写能读"，而是三件容易出错的事：
 *   1. **崩溃时最后一行是半截**——能不能识别、修好、不影响后续追加
 *   2. **序号接续**——重启后不能重号
 *   3. **持久性分档**——fflush 抗 kill -9、fsync 抗掉电，成本差 3500 倍
 *
 * 真正的 kill -9 验证在 `crash.sh` 里（需要外部杀进程）。
 */

use TSF\EventLog\EventLog;
use TSF\EventLog\MergedLog;

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

function tmpPath(string $name): string
{
    $p = "/tmp/tsf_eventlog_" . $name . ".log";
    if (file_exists($p)) {
        unlink($p);
    }
    // 高水位侧车（<日志>.seq）也要删。只删日志不删侧车，下一次跑的时候 scan() 会从侧车
    // 读出上次的序号（10、20、30……），新写的 10 条事件序号就是 31-40：
    // `truncate(7)` 一条都截不掉、`lastSeq()` 是 40 —— 用例全红。
    // 2026-11-27 之前这个套件在本机一直就是这样红的，而 run.sh 数 FAIL 的模式
    // 与输出对不上（"  FAIL 两个空格" vs "  FAIL 一个空格"），failed 恒为 0，于是绿的。
    if (file_exists($p . ".seq")) {
        unlink($p . ".seq");
    }
    if (file_exists($p . ".seq.tmp")) {
        unlink($p . ".seq.tmp");
    }
    return $p;
}

/** 1. 基本追加与重放 */
function caseBasic(): void
{
    echo "== [1] 追加与重放 ==" . PHP_EOL;

    $p = tmpPath("basic");
    $log = new EventLog($p);

    $s1 = $log->append("tx-1", "transfer", ["from" => 1, "to" => 2, "amount" => 100]);
    $s2 = $log->append("tx-2", "transfer", ["from" => 2, "to" => 3, "amount" => 50]);
    $s3 = $log->append("tx-3", "recharge", ["uid" => 3, "coin" => 500]);

    T::eq($s1, 1, "第一条序号 1");
    T::eq($s2, 2, "第二条 2");
    T::eq($s3, 3, "第三条 3");

    $all = $log->replay();
    T::eq(count($all), 3, "重放 3 条");

    $first = $all[0];
    T::eq($first["id"], "tx-1", "id 保留");
    T::eq($first["k"], "transfer", "kind 保留");
    T::eq((int) $first["d"]["amount"], 100, "数据保留");
    T::ok($first["t"] > 0, "带时间戳");

    // 从某序号之后读（增量重放）
    $tail = $log->replay(3);
    T::eq(count($tail), 1, "replay(3) 只返回第 3 条");
    if ($tail !== []) {
        T::eq($tail[0]["id"], "tx-3", "内容正确");
    }

    // limit
    T::eq(count($log->replay(1, 2)), 2, "limit 生效");

    $log->close();
    unlink($p);
}

/** 2. 重启后序号接续 */
function caseSequence(): void
{
    echo "== [2] 重启后序号接续 ==" . PHP_EOL;

    $p = tmpPath("seq");

    $a = new EventLog($p);
    $a->append("e1", "k", []);
    $a->append("e2", "k", []);
    $a->close();
    T::eq($a->lastSeq(), 2, "第一次写到 seq=2");

    // 模拟进程重启
    $b = new EventLog($p);
    T::eq($b->lastSeq(), 2, "新实例读到 lastSeq=2");
    $s3 = $b->append("e3", "k", []);
    T::eq($s3, 3, "续写从 3 开始（不重号）");
    $b->close();

    $c = new EventLog($p);
    T::eq(count($c->replay()), 3, "三条都在");
    T::eq($c->badLines(), 0, "没有坏行");
    $c->close();
    unlink($p);
}

/** 3. 崩溃留下的半行 */
function caseHalfLine(): void
{
    echo "== [3] 崩溃留下的半行 ==" . PHP_EOL;

    $p = tmpPath("half");
    // 手工造一个"写到一半被杀"的文件
    file_put_contents($p, "{\"s\":1,\"id\":\"a\",\"k\":\"k\",\"t\":1,\"d\":{}}\n"
        . "{\"s\":2,\"id\":\"b\",\"k\":\"k\",\"t\":1,\"d\":{}}\n"
        . "{\"s\":3,\"id\":\"c\",\"k\":\"k\",\"t\":");

    $log = new EventLog($p);
    T::eq($log->badLines(), 1, "识别出 1 个坏行");
    T::eq($log->lastSeq(), 2, "最后有效序号是 2");

    $rows = $log->replay();
    T::eq(count($rows), 2, "只重放出 2 条完整记录");

    // 关键：修复前不能继续追加，否则新行会接在半行后面
    $dropped = $log->repair();
    T::ok($dropped > 0, "repair 丢弃了残缺字节", $dropped . " 字节");
    T::eq($log->badLines(), 0, "修复后没有坏行");

    // 修复后追加正常，且序号接着 2
    $s = $log->append("d", "k", []);
    T::eq($s, 3, "修复后序号接续为 3");
    T::eq(count($log->replay()), 3, "三条完整记录");

    $log->close();
    unlink($p);
}

/** 4. 截断（归档） */
function caseTruncate(): void
{
    echo "== [4] 截断与归档 ==" . PHP_EOL;

    $p = tmpPath("trunc");
    $log = new EventLog($p);
    for ($i = 1; $i <= 10; $i++) {
        $log->append("e" . $i, "k", ["i" => $i]);
    }
    T::eq(count($log->replay()), 10, "先写 10 条");

    // 归档掉前 7 条
    $left = $log->truncate(7);
    T::eq($left, 3, "截断后留 3 条");
    T::eq(count($log->replay()), 3, "重放确认 3 条");

    $rows = $log->replay();
    T::eq($rows[0]["id"], "e8", "留下的是 8/9/10");
    T::eq($rows[2]["id"], "e10", "最后一条是 e10");

    // 截断后 lastSeq 仍是 10（序号不能回退，否则会重号）
    T::eq($log->lastSeq(), 10, "lastSeq 保持 10");
    $s = $log->append("e11", "k", []);
    T::eq($s, 11, "新事件从 11 开始");

    $log->close();
    unlink($p);
}

/** 5. 持久性分档 */
function caseModes(): void
{
    echo "== [5] 持久性分档 ==" . PHP_EOL;

    $p1 = tmpPath("flush");
    $a = new EventLog($p1, EventLog::MODE_FLUSH);
    T::eq($a->mode(), EventLog::MODE_FLUSH, "默认 FLUSH 模式");

    $t0 = hrtime(true);
    for ($i = 1; $i <= 1000; $i++) {
        $a->append("e" . $i, "k", ["i" => $i]);
    }
    $t1 = hrtime(true);
    $flushUs = ($t1 - $t0) / 1000 / 1000;
    echo "  FLUSH 1000 条: " . number_format(($t1 - $t0) / 1e6, 2) . " ms（"
        . number_format($flushUs, 2) . " µs/条）" . PHP_EOL;
    T::ok($flushUs < 20, "FLUSH 每条 < 20 µs", number_format($flushUs, 2) . " µs");
    T::eq($a->stats()["appended"], 1000, "写了 1000 条");
    $a->close();

    // 1000 条都在（kill -9 也能保住，因为 fflush 过了）
    $b = new EventLog($p1);
    T::eq(count($b->replay()), 1000, "全部可重放");
    $b->close();
    unlink($p1);

    // SYNC 模式只需要验证"能 commit"，不必跑 1000 条（4.3 ms/条太慢）
    $p2 = tmpPath("sync");
    $c = new EventLog($p2, EventLog::MODE_SYNC);
    T::eq($c->mode(), EventLog::MODE_SYNC, "SYNC 模式");
    $c->append("s1", "k", []);
    T::eq($c->stats()["commits"], 1, "每条自动 commit");
    $c->append("s2", "k", []);
    T::eq($c->stats()["commits"], 2, "第二条又一次 commit");
    $c->close();
    T::eq(count($c->replay()), 2, "SYNC 模式内容正确");
    unlink($p2);
}

/** 6. 性能：1000 条交易 */
function casePerf(): void
{
    echo "== [6] 性能 ==" . PHP_EOL;

    $p = tmpPath("perf");
    $log = new EventLog($p);

    $n = 1000;
    $t0 = hrtime(true);
    for ($i = 1; $i <= $n; $i++) {
        $log->append("trade-" . $i, "transfer", ["from" => $i, "to" => $i + 1, "amount" => 10]);
    }
    $t1 = hrtime(true);

    echo "  追加 " . number_format($n) . " 条: " . number_format(($t1 - $t0) / 1e6, 2) . " ms（"
        . number_format(($t1 - $t0) / $n / 1000, 2) . " µs/条）" . PHP_EOL;
    T::ok(($t1 - $t0) / 1e6 < 100, "1000 条 < 100 ms", number_format(($t1 - $t0) / 1e6, 2) . " ms");

    $t2 = hrtime(true);
    $rows = $log->replay();
    $t3 = hrtime(true);
    T::eq(count($rows), $n, "重放 1000 条");
    echo "  重放 " . number_format($n) . " 条: " . number_format(($t3 - $t2) / 1e6, 2) . " ms（"
        . number_format(($t3 - $t2) / $n / 1000, 2) . " µs/条）" . PHP_EOL;

    $st = $log->stats();
    echo "  文件大小: " . number_format((int) $st["size"]) . " 字节（"
        . number_format((int) $st["size"] / $n, 1) . " 字节/条）" . PHP_EOL;

    // 重启扫描成本（nextSeq 靠全量扫描得来）
    $t4 = hrtime(true);
    $again = new EventLog($p);
    $t5 = hrtime(true);
    T::eq($again->lastSeq(), $n, "重启后 lastSeq 正确");
    echo "  重启扫描: " . number_format(($t5 - $t4) / 1e6, 2) . " ms" . PHP_EOL;
    $again->close();

    $log->close();
    unlink($p);
}

/** 7. 幂等重放的语义：同一 id 可以安全重放多次 */
function caseIdempotent(): void
{
    echo "== [7] 幂等重放 ==" . PHP_EOL;

    $p = tmpPath("idem");
    $log = new EventLog($p);
    $log->append("tx-A", "transfer", ["amount" => 100]);
    $log->append("tx-B", "transfer", ["amount" => 50]);
    $log->append("tx-A", "transfer", ["amount" => 100]);   // 同 id 再来一次

    // 日志本身不去重——它只保证"你可以重放任意多次"
    $rows = $log->replay();
    T::eq(count($rows), 3, "日志里就是 3 条（不去重）");

    // 业务侧按 id 去重后才得到 2 笔
    $seen = [];
    $applied = 0;
    foreach ($rows as $r) {
        $id = $r["id"];
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $applied++;
    }
    T::eq($applied, 2, "按 id 去重后是 2 笔");

    // 重放两次结果相同（幂等）
    $seen2 = [];
    foreach ($rows as $r) {
        $seen2[$r["id"]] = true;
    }
    T::eq(count($seen2), 2, "再重放一次仍是 2 笔");

    $log->close();
    unlink($p);
}


/**
 * 链路 id 落到账上：这是"这笔钱对应哪次操作"的唯一线索。
 *
 * 结算链路在"记账"这一环断开的话，前面做得再好也查不到根因——
 * 玩家说"我这把没收到钱"，你只能知道他打过一局，对不上是哪条日志。
 */
function caseTrace(): void
{
    echo PHP_EOL . "== trace ==" . PHP_EOL;

    // 1) 有链路时写入 tr
    \TSF\Kernel\Logger::setTraceProvider(function (): int {
        return 987654321;
    });
    $path = tmpPath("trace.log");
    $log = new EventLog($path);
    $log->append("w:1", "debit", ["gold" => 100]);
    $log->close();

    $raw = (string) file_get_contents($path);
    T::ok(strpos($raw, '"tr":987654321') !== false, "账上写了 trace", $raw);

    $ev = (new EventLog($path))->replay(1);
    T::eq(count($ev), 1, "能读回一条");
    T::eq($ev[0]["tr"], 987654321, "replay 带出 trace");

    // 2) 没有链路时不写（避免每条后台事件都挂 tr:0）
    \TSF\Kernel\Logger::setTraceProvider(function (): int {
        return 0;
    });
    $path2 = tmpPath("notrace.log");
    $log2 = new EventLog($path2);
    $log2->append("w:2", "debit", ["gold" => 1]);
    $log2->close();
    $raw2 = (string) file_get_contents($path2);
    T::ok(strpos($raw2, '"tr"') === false, "无链路时不写 tr", $raw2);
    $ev2 = (new EventLog($path2))->replay(1);
    T::eq($ev2[0]["tr"], 0, "replay 对缺失字段给 0");

    // 3) 老文件（没有 tr 字段）仍能正常重放 —— 升级不能要求停服搬账
    $path3 = tmpPath("legacy.log");
    $legacy = '{"s":1,"id":"w:3","k":"credit","t":1700000000000,"d":{"gold":5}}' . "\n";
    file_put_contents($path3, $legacy);
    $ev3 = (new EventLog($path3))->replay(1);
    T::eq(count($ev3), 1, "老格式仍可重放");
    T::eq($ev3[0]["tr"], 0, "老格式没有 trace");
    T::eq($ev3[0]["d"]["gold"], 5, "老格式数据完整");

    // 收尾：清掉 provider，别影响后面的用例
    \TSF\Kernel\Logger::setTraceProvider(function (): int {
        return 0;
    });
}


/**
 * 多节点归并（MergedLog）。
 *
 * 这个类之前**零覆盖**：81 行的排序逻辑、内部字段 `_f` 的清理、`fromSeq` 的跨文件
 * 语义，一条测试都没有。而它正是"对账两次跑结果不同"这类假警报的来源——
 * 归并结果不确定，业务就没法信任对账。
 *
 * 这里钉三件事：
 *   1. 归并顺序**确定**（同样输入，两次结果一样）
 *   2. 内部字段 `_f` 不泄漏到返回结果里
 *   3. `fromSeq` 是**每个文件各自的序号**，不是全局位置——
 *      传大于某个文件总条数的值时，那个文件会被整个跳过。
 *      这不是 bug，但很容易被误用成"增量对账"，所以要用断言写清楚。
 */
function caseMerged(): void
{
    echo PHP_EOL . "== 多节点归并 ==" . PHP_EOL;

    $pa = tmpPath("merged_a");
    $pb = tmpPath("merged_b");
    $a = new EventLog($pa);
    $b = new EventLog($pb);

    // A 写 3 条，B 写 2 条。seq 各自从 1 开始（这正是跨节点不能直接用 fromSeq 的原因）
    $a->append("u1", "coin", ["d" => 100]);
    $a->append("u2", "coin", ["d" => 200]);
    $a->append("u3", "coin", ["d" => 300]);
    $b->append("u4", "coin", ["d" => 400]);
    $b->append("u5", "coin", ["d" => 500]);

    $m1 = MergedLog::replay([$a, $b]);
    T::eq(count($m1), 5, "两个文件共 5 条");

    $m2 = MergedLog::replay([$a, $b]);
    $same = true;
    foreach ($m1 as $i => $ev) {
        if (!isset($m2[$i]) || $m2[$i]["id"] !== $ev["id"]) {
            $same = false;
        }
    }
    T::ok($same, "两次归并结果完全一致（确定性）");

    $leak = false;
    foreach ($m1 as $ev) {
        if (array_key_exists("_f", $ev)) {
            $leak = true;
        }
    }
    T::ok(!$leak, "内部排序字段 _f 没有泄漏到返回结果");

    // fromSeq 是"每个文件各自的 seq"：A 有 3 条，传 4 会把 A 全部过滤掉，
    // 只剩 B 的 2 条。做增量对账时踩这个坑，会**静默漏掉一个节点的全部事件**。
    $m3 = MergedLog::replay([$a, $b], 4);
    T::eq(count($m3), 0, "fromSeq=4 时两个文件都被过滤（各自的 seq 都没到 4）");

    $m4 = MergedLog::replay([$a, $b], 2);
    T::eq(count($m4), 3, "fromSeq=2 只跳过各文件第 1 条（剩 3 条）");
}

function main(): void
{
    caseBasic();
    caseSequence();
    caseHalfLine();
    caseTruncate();
    caseModes();
    casePerf();
    caseIdempotent();
    caseTrace();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
    caseMerged();
}
