<?php

use TSF\Cluster\Cluster;
use TSF\Handoff\ClusterTransport;
use TSF\Handoff\Handoff;
use TSF\Service\Message;

/**
 * 状态交接（handoff）回归。
 *
 * 在**一个进程里**起两个 Cluster（各监听一个 TCP 端口）+ 两个 Handoff，
 * 手工驱动 poll/flush。比三个进程快一个量级，更重要的是能精确控制
 * "谁没收到包"——超时回滚这条路径只能这么测。
 *
 * 判据是三条不变量：
 *   1. 失败能回滚（拒绝 / 超时），回滚后源侧状态没被动过；
 *   2. 重复投递不重复安装（同版本只装一次）；
 *   3. 同 key 同时只有一次交接在飞。
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

/** 记录回调事件。用对象持有状态：闭包里改标量不会写回外层（AOT 引用形参限制） */
final class Rec
{
    public int $installs = 0;

    public int $commits = 0;

    public int $rollbacks = 0;

    public string $lastKey = "";

    public string $lastState = "";

    public int $lastVer = 0;

    public string $why = "";

    /** 目标侧是否接受安装 */
    public bool $accept = true;
}

function nowMs(): int
{
    return (int) (microtime(true) * 1000);
}

/** 两侧各 poll 一轮并把包喂给对应的 Handoff */
function pump(Cluster $c1, Handoff $h1, Cluster $c2, Handoff $h2, int $rounds = 40): void
{
    for ($i = 0; $i < $rounds; $i++) {
        $pkts = $c1->poll();
        foreach ($pkts as $p) {
            $h1->handle(new Message($p->src, $p->msgId, $p->data));
        }
        $pkts2 = $c2->poll();
        foreach ($pkts2 as $p2) {
            $h2->handle(new Message($p2->src, $p2->msgId, $p2->data));
        }
        $c1->flush();
        $c2->flush();
        usleep(2000);
    }
}

/** 只驱动一侧：另一半的包留在内核缓冲里，用来制造"ACK 迟迟不来" */
function pumpOne(Cluster $c, Handoff $h, int $rounds = 6): void
{
    for ($i = 0; $i < $rounds; $i++) {
        $pkts = $c->poll();
        foreach ($pkts as $p) {
            $h->handle(new Message($p->src, $p->msgId, $p->data));
        }
        $c->flush();
        usleep(2000);
    }
}

function main(): void
{
    echo "== 状态交接（handoff）==" . PHP_EOL;

    $addr1 = "tcp://127.0.0.1:23801";
    $c1 = new Cluster(1, $addr1, null, "ms");
    $c1->listen();
    $c2 = new Cluster(2, "tcp://127.0.0.1:23802", null, "ms");
    $c2->listen();

    $h1 = new Handoff(1, 1, new ClusterTransport($c1), 300);
    $h2 = new Handoff(2, 1, new ClusterTransport($c2), 300);

    $r1 = new Rec();
    $r2 = new Rec();

    // 源侧：成功 → commit；失败 → rollback
    $h1->onCommit(function (string $key, int $ver) use ($r1): void {
        $r1->commits++;
        $r1->lastKey = $key;
        $r1->lastVer = $ver;
    });
    $h1->onRollback(function (string $key, int $ver, string $why) use ($r1): void {
        $r1->rollbacks++;
        $r1->lastKey = $key;
        $r1->lastVer = $ver;
        $r1->why = $why;
    });
    // 目标侧：安装
    $h2->onInstall(function (string $key, string $state, int $ver) use ($r2): bool {
        $r2->installs++;
        $r2->lastKey = $key;
        $r2->lastState = $state;
        $r2->lastVer = $ver;
        return $r2->accept;
    });

    // 0) 建链 + 握手
    T::ok($c2->connect(1, $addr1), "建链");
    pump($c1, $h1, $c2, $h2, 40);
    T::ok($c1->hasPeer(2) && $c2->hasPeer(1), "握手完成");

    // 1) 正常交接
    T::ok($h1->begin(2, 1, "room:1", 1, "state-A", nowMs()), "begin 返回 true");
    pump($c1, $h1, $c2, $h2, 40);
    T::ok($r2->installs === 1, "目标安装 1 次", "n=" . $r2->installs);
    T::ok($r2->lastState === "state-A", "状态字节原样到达", $r2->lastState);
    T::ok($r2->lastVer === 1, "版本正确");
    T::ok($r1->commits === 1, "源侧收到 commit");
    T::ok($h1->pendingCount() === 0, "在飞表已清空");

    // 2) 重复投递：同 key 同版本再来一次，不再安装
    $before = $r2->installs;
    T::ok($h1->begin(2, 1, "room:1", 1, "state-A", nowMs()), "重发 begin 仍可发");
    pump($c1, $h1, $c2, $h2, 40);
    T::ok($r2->installs === $before, "同版本不重复安装", "n=" . $r2->installs);
    T::ok($r1->commits === 2, "重发仍收到 commit（幂等对源侧透明）");
    T::ok($h2->stats()["dup"] >= 1, "目标侧记到重复", "dup=" . $h2->stats()["dup"]);

    // 3) 目标拒绝 → 源侧回滚
    $r2->accept = false;
    $rollBefore = $r1->rollbacks;
    T::ok($h1->begin(2, 1, "room:2", 1, "state-B", nowMs()), "begin（将被拒）");
    pump($c1, $h1, $c2, $h2, 40);
    T::ok($r1->rollbacks === $rollBefore + 1, "拒绝触发 rollback");
    T::ok($r1->why === "rejected", "回滚原因是 rejected", $r1->why);
    T::ok($h1->pendingCount() === 0, "拒绝后在飞表也清空");
    $r2->accept = true;

    // 4) 超时 → 回滚；随后迟到的 ACCEPT 必须被忽略
    $commitBefore = $r1->commits;
    T::ok($h1->begin(2, 1, "room:3", 1, "state-C", nowMs()), "begin（将超时）");
    pumpOne($c1, $h1, 6);          // 只推源侧：包出去了，但没人回
    T::ok($h1->isPending("room:3"), "超时前仍在飞");
    $h1->expire(nowMs() + 1000);
    T::ok(!$h1->isPending("room:3"), "expire 后在飞表清空");
    T::ok($r1->rollbacks === $rollBefore + 2, "超时触发 rollback");
    T::ok($r1->why === "timeout", "回滚原因是 timeout", $r1->why);

    // 迟到的 ACCEPT：目标其实装成功了，但源侧已回滚 —— 不能"补一次 commit"，
    // 否则源侧会以为交接完成、把本地状态删掉，而目标侧那份可能已经被业务改过
    pump($c1, $h1, $c2, $h2, 40);
    T::ok($r1->commits === $commitBefore, "迟到 ACK 不产生额外 commit", "commits=" . $r1->commits);
    T::ok($h2->installedVersion("room:3") === 1, "目标侧确实装过（这正是分叉点）", "ver=" . $h2->installedVersion("room:3"));

    // 5) 同 key 并发：第二次 begin 直接失败
    $h1->begin(2, 1, "room:4", 1, "state-D", nowMs());
    T::ok(!$h1->begin(2, 1, "room:4", 2, "state-D2", nowMs()), "同 key 第二次 begin 被拒");
    $h1->expire(nowMs() + 1000);

    // 6) 搬到自己是无意义的
    T::ok(!$h1->begin(1, 1, "room:5", 1, "x", nowMs()), "目标是自己 → false");

    // 7) 目标不可达（没建链）
    T::ok(!$h1->begin(9, 1, "room:6", 1, "x", nowMs()), "不可达节点 → false");

    $st = $h1->stats();
    echo "  源侧统计: sent=" . $st["sent"] . " accepted=" . $st["accepted"]
        . " rejected=" . $st["rejected"] . " timeout=" . $st["timeout"]
        . " ignored=" . $st["ignored"] . PHP_EOL;

    $c1->closeAll();
    $c2->closeAll();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
