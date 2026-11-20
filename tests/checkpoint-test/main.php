<?php

use TSF\EventLog\Checkpoint;
use TSF\EventLog\EventLog;

/**
 * 检查点回归：恢复等价、截断安全、坏检查点必须被拒。
 *
 * 判据不是"文件写出来了"，而是**恢复结果和不截断时逐项相同**——
 * 检查点最大的风险是"少折了一段"或"多折了一段"，两者都只体现在结果里。
 */

final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $name, string $extra = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  PASS  " . $name . ($extra !== "" ? "  (" . $extra . ")" : "") . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL  " . $name . ($extra !== "" ? "  (" . $extra . ")" : "") . PHP_EOL;
        }
    }
}

const DIR = "/tmp/tsf_ckpt";

function fresh(string $name): string
{
    if (!is_dir(DIR)) {
        mkdir(DIR, 0777, true);
    }
    $p = DIR . "/" . $name;
    if (is_file($p)) {
        unlink($p);
    }
    if (is_file($p . ".ckpt")) {
        unlink($p . ".ckpt");
    }
    if (is_file($p . ".seq")) {
        unlink($p . ".seq");
    }
    return $p;
}

/** 把事件折叠成"每个账号的余额"。业务缩小到最小，但形态与真实账本一致 */
function fold(array $events, array $start): array
{
    $bal = $start;
    foreach ($events as $e) {
        $uid = (int) ($e["d"]["uid"] ?? 0);
        $delta = (int) ($e["d"]["delta"] ?? 0);
        $bal[$uid] = ($bal[$uid] ?? 0) + $delta;
    }
    return $bal;
}

function seed(EventLog $log, int $n, int $from): void
{
    for ($i = $from; $i <= $n; $i++) {
        $log->append("tx:" . $i, "credit", ["uid" => $i % 7, "delta" => 1 + ($i % 5)]);
    }
    $log->commit();
}

function main(): void
{
    echo "== 检查点（checkpoint）==" . PHP_EOL;

    // 1) 基本往返
    $p = fresh("basic.log");
    $cp = new Checkpoint($p . ".ckpt");
    T::ok(!$cp->exists(), "初始没有检查点");
    T::ok(!$cp->load(), "加载不存在的检查点返回 false", $cp->reason());
    T::ok($cp->save(7, ["a" => 3, "b" => "x"]), "save 成功");
    $cp2 = new Checkpoint($p . ".ckpt");
    T::ok($cp2->load(), "load 成功");
    T::ok($cp2->seq() === 7, "seq 往返", "seq=" . $cp2->seq());
    T::ok(($cp2->state()["a"] ?? 0) === 3, "state 往返");

    // 2) 检查点 + 截断：日志只剩尾巴
    $p = fresh("prune.log");
    $log = new EventLog($p);
    seed($log, 100, 1);
    T::ok($log->lastSeq() === 100, "先写 100 条", "last=" . $log->lastSeq());

    $cp = new Checkpoint($p . ".ckpt");
    $state60 = ["u1" => 60];        // 内容不重要，等价性在下面单独验
    $kept = $cp->saveAndPrune($log, 60, $state60);
    T::ok($kept === 40, "截到 60 号，保留 40 条", "keep=" . $kept);
    $tail = $log->replay();
    T::ok(count($tail) === 40, "重放只剩 40 条", "n=" . count($tail));
    T::ok($tail[0]["s"] === 61, "尾巴从 61 开始", "s=" . $tail[0]["s"]);

    // 3) 恢复等价：全量折叠 == 检查点 + 尾巴折叠
    $p = fresh("equiv.log");
    $log = new EventLog($p);
    seed($log, 200, 1);
    $full = fold($log->replay(1), []);
    T::ok(count($full) === 7, "全量折叠覆盖 7 个账号", "n=" . count($full));

    $cpx = new Checkpoint($p . ".ckpt");
    $events = $log->replay(1, 150);
    $at150 = fold($events, []);
    T::ok($cpx->saveAndPrune($log, 150, $at150) === 50, "折到 150 并截断");
    $restored = fold($log->replay(), $at150);
    $same = true;
    foreach ($full as $uid => $v) {
        if (($restored[$uid] ?? 0) !== $v) {
            $same = false;
        }
    }
    T::ok($same, "检查点+尾巴 与 全量重放 逐项相同");

    // 4) 坏文件必须被拒（不能把错的余额当起点）
    file_put_contents(DIR . "/bad.ckpt", "这不是检查点");
    $bad = new Checkpoint(DIR . "/bad.ckpt");
    T::ok(!$bad->load(), "垃圾文件加载失败", $bad->reason());
    T::ok($bad->seq() === 0 && $bad->state() === [], "失败后不留残留状态");

    // 5) 内容被篡改：crc 必须拦住
    $p = fresh("tamper.log");
    $cpt = new Checkpoint($p . ".ckpt");
    $cpt->save(5, ["u" => 100]);
    $raw = file_get_contents($p . ".ckpt");
    if (is_string($raw)) {
        file_put_contents($p . ".ckpt", str_replace("100", "999", $raw));
    }
    $cpt2 = new Checkpoint($p . ".ckpt");
    T::ok(!$cpt2->load(), "篡改后加载失败", $cpt2->reason());
    T::ok($cpt2->reason() === "crc-mismatch", "原因是 crc-mismatch", $cpt2->reason());

    // 6) 序号越界：不能把日志里还没处理的事件截掉
    $p = fresh("bound.log");
    $log = new EventLog($p);
    seed($log, 10, 1);
    $cpb = new Checkpoint($p . ".ckpt");
    T::ok($cpb->saveAndPrune($log, 20, ["x" => 1]) === -1, "越界被拒");
    T::ok($log->lastSeq() === 10 && count($log->replay()) === 10, "日志没被截断");
    T::ok($cpb->saveAndPrune($log, 0, []) === -1, "seq=0 被拒");

    // 7) 重复保存覆盖旧检查点
    $p = fresh("over.log");
    $cpo = new Checkpoint($p . ".ckpt");
    $cpo->save(3, ["v" => 1]);
    $cpo->save(9, ["v" => 2]);
    $cpo2 = new Checkpoint($p . ".ckpt");
    $cpo2->load();
    T::ok($cpo2->seq() === 9 && ($cpo2->state()["v"] ?? 0) === 2, "新检查点覆盖旧的");

    // 9) 截空之后序号不能回退：否则检查点水位失效，重启会丢数据
    $p = fresh("water.log");
    $lg = new EventLog($p);
    seed($lg, 30, 1);
    $cw = new Checkpoint($p . ".ckpt");
    $cw->saveAndPrune($lg, 30, ["x" => 1]);
    T::ok(count($lg->replay()) === 0, "日志被截空");
    // 整段截断应该是"删文件"而不是"读整文件再写空"：日志大时差别是几十 MB 的白读
    T::ok(!is_file($p), "整段截断后文件被删掉（不是留个空文件）");
    T::ok($lg->lastSeq() === 30, "高水位仍是 30", "last=" . $lg->lastSeq());
    $seqNext = $lg->append("tx:31", "credit", ["uid" => 1, "delta" => 1]);
    T::ok($seqNext === 31, "新事件接着 31，而不是重置成 1", "seq=" . $seqNext);
    $lg->commit();
    $lg->close();
    $lg2 = new EventLog($p);
    T::ok($lg2->lastSeq() === 31, "重开后高水位 31", "last=" . $lg2->lastSeq());
    T::ok($lg2->replay()[0]["s"] === 31, "重开后能读到 31 号");

    $lg2->close();

    // 部分截断保留**原始行**：重新 json_encode 会把未知字段吃掉，
    // 而日志是事实记录，不该因为一次归档被改写
    $p = fresh("raw.log");
    $lg3 = new EventLog($p);
    seed($lg3, 3, 1);
    file_put_contents($p, '{"s":999,"id":"legacy","k":"credit","t":1,"d":{},"extra":"keep-me"}' . "\n", FILE_APPEND);
    $lg3->truncate(2);
    $rawAfter = file_get_contents($p);
    T::ok(is_string($rawAfter) && strpos($rawAfter, "keep-me") !== false, "部分截断保留了未知字段");
    $tailS = [];
    foreach ($lg3->replay() as $e) {
        $tailS[] = $e["s"];
    }
    T::ok($tailS === [3, 999], "保留的序号正确", "s=" . implode(",", $tailS));
    $lg3->close();

    // 8) 恢复耗时：全量 vs 检查点+尾巴
    $p = fresh("speed.log");
    $log = new EventLog($p);
    seed($log, 30000, 1);
    $t0 = hrtime(true);
    $a = fold($log->replay(1), []);
    $usFull = intdiv(hrtime(true) - $t0, 1000);

    $cps = new Checkpoint($p . ".ckpt");
    $head = fold($log->replay(1, 29000), []);
    $cps->saveAndPrune($log, 29000, $head);
    $t1 = hrtime(true);
    $b = $head;
    foreach ($log->replay() as $e) {
        $uid = (int) ($e["d"]["uid"] ?? 0);
        $b[$uid] = ($b[$uid] ?? 0) + (int) ($e["d"]["delta"] ?? 0);
    }
    $usTail = intdiv(hrtime(true) - $t1, 1000);

    $same2 = true;
    foreach ($a as $uid => $v) {
        if (($b[$uid] ?? 0) !== $v) {
            $same2 = false;
        }
    }
    T::ok($same2, "3 万条事件下两种恢复结果一致");
    echo "  恢复耗时        全量=" . $usFull . " µs，检查点+尾巴=" . $usTail . " µs" . PHP_EOL;
    T::ok($usTail < $usFull, "检查点恢复更快", "tail=" . $usTail . " full=" . $usFull);

    // 整段截断：快速路径不读文件，耗时应该与日志大小无关
    $pfull = fresh("full.log");
    $lgf = new EventLog($pfull);
    seed($lgf, 30000, 1);
    $t3 = hrtime(true);
    $lgf->truncate($lgf->lastSeq());
    $usTrunc = intdiv(hrtime(true) - $t3, 1000);
    echo "  整段截断        3 万条耗时 " . $usTrunc . " µs，"
        . (is_file($pfull) ? "文件仍在" : "文件已删") . PHP_EOL;
    T::ok(!is_file($pfull), "3 万条整段截断后文件已删（O(1)，不随日志增长）");
    $lgf->close();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
