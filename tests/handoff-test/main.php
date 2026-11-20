<?php

use TSF\Cluster\Cluster;
use TSF\Handoff\ClusterTransport;
use TSF\Handoff\Gate;
use TSF\Handoff\Handoff;
use TSF\Handoff\Lease;
use TSF\Handoff\MemoryLease;
use TSF\Handoff\Ownership;
use TSF\Handoff\Rebalancer;
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

    /** commit 时闸门交出的队列（联调用：验证保序） */
    public string $commitQueue = "";

    /** rollback 时闸门交回的队列 */
    public string $rollbackQueue = "";

    /** 目标侧卸掉了几次（撤销路径） */
    public int $uninstalls = 0;
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

/**
 * 闸门（Gate）：冻结期间的请求不丢、不乱序、不双写。
 *
 * 纯对象，不需要 Cluster —— 它只做"挡住并排队"，真正搬运是 Handoff 的事。
 */
function runGate(): void
{
    echo PHP_EOL . "== 交接闸门（Gate）==" . PHP_EOL;
    $g = new Gate();

    // 1) 没冻结时直接放行
    T::ok($g->submit("room:1", "op0") === Gate::PASS, "未冻结时 pass");

    // 2) 冻结
    T::ok($g->freeze("room:1", 2, 1), "freeze 返回 true");
    T::ok(!$g->freeze("room:1", 2, 1), "重复 freeze 返回 false（不重置队列）");
    $t = $g->targetOf("room:1");
    T::ok($t[0] === 2 && $t[1] === 1, "记住目标节点与入口");

    // 3) 冻结期间的请求排队，不丢
    T::ok($g->submit("room:1", "op1") === Gate::QUEUED, "冻结后 queued");
    T::ok($g->submit("room:1", "op2") === Gate::QUEUED, "第二条 queued");
    T::ok($g->submit("room:1", "op3") === Gate::QUEUED, "第三条 queued");
    T::ok($g->queueSize("room:1") === 3, "队列 3 条", "n=" . $g->queueSize("room:1"));
    T::ok($g->state("room:1") === Gate::FROZEN, "状态 FROZEN");

    // 4) 交接成功：队列按原序交给新宿主，之后直接转发
    $out = $g->commit("room:1");
    T::ok(implode(",", $out) === "op1,op2,op3", "commit 交出队列且保序", implode(",", $out));
    T::ok($g->state("room:1") === Gate::FORWARD, "commit 后状态 FORWARD");
    T::ok($g->submit("room:1", "op4") === Gate::FORWARDED, "交接后 submit 提示转发");
    T::ok($g->queueSize("room:1") === 0, "队列已清空");

    // 5) 交接失败：队列在本地按原序放回
    $g->freeze("room:2", 3, 1);
    $g->submit("room:2", "b1");
    $g->submit("room:2", "b2");
    $back = $g->rollback("room:2");
    T::ok(implode(",", $back) === "b1,b2", "rollback 交回队列且保序", implode(",", $back));
    T::ok($g->state("room:2") === Gate::OPEN, "rollback 后回到 OPEN");
    T::ok($g->submit("room:2", "b3") === Gate::PASS, "回滚后本地照常处理");

    // 6) 队列上限：满了必须让调用方拒绝，而不是静默丢弃
    $g->freeze("room:3", 4, 1, 2);
    $g->submit("room:3", "c1");
    $g->submit("room:3", "c2");
    T::ok($g->submit("room:3", "c3") === Gate::OVERFLOW, "超上限返回 overflow");
    T::ok($g->queueSize("room:3") === 2, "队列没被撑破", "n=" . $g->queueSize("room:3"));
    T::ok($g->dropped("room:3") === 1, "记到一次拒绝");
    $g->rollback("room:3");

    // 7) key 之间互不影响
    $g->freeze("room:4", 5, 1);
    T::ok($g->submit("room:5", "x") === Gate::PASS, "别的 key 不受影响");
    $g->rollback("room:4");

    // 8) 非冻结状态下 commit/rollback 空转（调用方重复收尾时不该出错）
    T::ok($g->commit("room:5") === [], "OPEN 下 commit 返回空");
    T::ok($g->rollback("room:5") === [], "OPEN 下 rollback 返回空");

    $st = $g->stats();
    echo "  闸门统计: frozen=" . $st["frozen"] . " forward=" . $st["forward"]
        . " queued=" . $st["queued"] . " dropped=" . $st["dropped"] . PHP_EOL;
}

/** 写死的归属表：让"该搬/不该搬/不可达"三种场景可控，不必真凑一个哈希环 */
final class MapOwnership implements Ownership
{
    /** @var array<string, int> */
    public array $map = [];

    public function ownerOf(string $key): int
    {
        return $this->map[$key] ?? 0;
    }
}

/** 拿得到但续不上：模拟"交接途中租约被别的节点接手" */
final class LoseLease implements Lease
{
    public function tryAcquire(string $key, int $ttlMs): bool
    {
        return true;
    }

    public function renew(string $key, int $ttlMs): bool
    {
        return false;
    }

    public function release(string $key): bool
    {
        return true;
    }

    public function holds(string $key): bool
    {
        return false;
    }
}

/** 永远拿不到租约：模拟"另一个节点认为这个 key 归他" */
final class DenyLease implements Lease
{
    public function tryAcquire(string $key, int $ttlMs): bool
    {
        return false;
    }

    public function renew(string $key, int $ttlMs): bool
    {
        return false;
    }

    public function release(string $key): bool
    {
        return true;
    }

    public function holds(string $key): bool
    {
        return false;
    }
}

/** 重平衡事件记录 */
final class Ev
{
    /** @var array<int, string> */
    public array $moved = [];

    /** @var array<int, string> */
    public array $failed = [];

    public string $queue = "";
}

/**
 * 自动重平衡：扫差异 → 限流 → 复用 Gate/Handoff → 结果收口。
 *
 * 复用 main() 里已经握好手的两条链路，所以不需要再起一套 Cluster。
 */
function runRebalance(Cluster $c1, Handoff $h1, Cluster $c2, Handoff $h2, Rec $r2): void
{
    echo PHP_EOL . "== 自动重平衡（Rebalancer）==" . PHP_EOL;

    $g = new Gate();
    $own = new MapOwnership();
    $own->map["k:1"] = 2;       // 该搬到 node2
    $own->map["k:2"] = 3;       // 该搬到 node3，但没建链
    $own->map["k:self"] = 1;    // 归自己，不动
    $own->map["k:skip"] = 2;    // 该搬，但业务说暂时不能搬
    $own->map["k:rej"] = 2;     // 该搬，目标会拒绝

    $rb = new Rebalancer(1, 1, $h1, $g, $own, 1);   // limit = 1，逼出限流行为
    $lease = new MemoryLease(1);
    $rb->useLease($lease, 5000);
    $ev = new Ev();

    $rb->onExport(function (string $key): ?string {
        if ($key === "k:skip") {
            return null;        // 业务拒绝导出
        }
        return "st:" . $key;
    });
    $rb->onMoved(function (string $key, int $target, array $queued) use ($ev): void {
        $ev->moved[] = $key . ">" . $target;
        $ev->queue = implode(",", $queued);
    });
    $rb->onFailed(function (string $key, int $target, string $why) use ($ev): void {
        $ev->failed[] = $key . "!" . $why;
    });

    $rb->own("k:1");
    $rb->own("k:2");
    $rb->own("k:self");
    $rb->own("k:skip");
    $rb->own("k:rej");
    T::ok($rb->ownCount() === 5, "登记 5 个 key");
    T::ok(!$rb->own("k:1"), "重复登记返回 false");

    // 第一轮：limit=1，只能发起 1 个
    $n = $rb->scan(nowMs());
    T::ok($n === 1, "limit=1 时只发起 1 个", "n=" . $n);
    T::ok($rb->inflight() === 1, "在飞 1 个");

    // 冻结期间来的请求排队，交接成功后随结果一起交出去
    T::ok($g->submit("k:1", "op-during") === Gate::QUEUED, "冻结期间请求排队");
    pump($c1, $h1, $c2, $h2, 40);
    // 一轮 scan 同时做"收口上一轮"和"发起下一轮"，所以 k:1 在这里被收口、
    // k:rej 在这里被发起
    $n2 = $rb->scan(nowMs());
    T::ok(in_array("k:1>2", $ev->moved), "k:1 交给 node2", implode("|", $ev->moved));
    T::ok($ev->queue === "op-during", "排队请求随结果交出", $ev->queue);
    T::ok(!$rb->isOwned("k:1"), "交接后不再归本地");
    T::ok($g->state("k:1") === Gate::FORWARD, "闸门转 FORWARD");
    T::ok(!$lease->holds("k:1"), "交接成功后租约已释放（交给新宿主去取）");

    // 同一轮里：k:2 目标不可达要立刻解冻，k:skip 被业务跳过，k:rej 发起成功
    T::ok($n2 === 1, "该轮只有 k:rej 发起成功", "n=" . $n2);
    T::ok($g->state("k:2") === Gate::OPEN, "不可达的 key 闸门回 OPEN");
    T::ok(!$h1->isPending("k:2"), "不可达的 key 不再在飞");
    T::ok($rb->stats()["skipped"] >= 1, "export 返回 null 的被跳过",
        "skipped=" . $rb->stats()["skipped"]);

    // 限流：k:rej 在飞时再扫一轮，什么都不该发起（limit=1）
    T::ok($rb->scan(nowMs()) === 0, "在飞已达上限时不再发起");

    // 第三轮：目标拒绝 → failed，key 仍归本地，且进入退避期不再重试
    $r2->accept = false;
    pump($c1, $h1, $c2, $h2, 40);
    T::ok($rb->scan(nowMs()) === 0, "失败退避期内不重试");
    T::ok(in_array("k:rej!rejected", $ev->failed), "拒绝触发 failed", implode("|", $ev->failed));
    T::ok($rb->isOwned("k:rej"), "失败后仍归本地");
    T::ok($g->state("k:rej") === Gate::OPEN, "失败后闸门回 OPEN");
    $r2->accept = true;

    // 租约被拒：拿不到就不许搬（这是防"两个节点同时认为自己是 owner"的唯一手段）
    $own2 = new MapOwnership();
    $own2->map["l:1"] = 2;
    $g2 = new Gate();
    $rb2 = new Rebalancer(1, 1, $h1, $g2, $own2, 4);
    $rb2->useLease(new DenyLease(), 5000);
    $rb2->onExport(function (string $key): ?string {
        return "st:" . $key;
    });
    $rb2->own("l:1");
    T::ok($rb2->scan(nowMs()) === 0, "租约被拒时不发起交接");
    T::ok($rb2->stats()["lease_denied"] === 1, "记到一次 lease_denied");
    T::ok($g2->state("l:1") === Gate::OPEN, "被拒的 key 不会被冻结");

    $st = $rb->stats();
    echo "  重平衡统计: started=" . $st["started"] . " moved=" . $st["moved"]
        . " failed=" . $st["failed"] . " skipped=" . $st["skipped"]
        . " lease_denied=" . $st["lease_denied"] . " owned=" . $st["owned"] . PHP_EOL;
}


/**
 * 撤销（abort）：源侧反悔时必须能收回，且目标侧装过的部分要卸掉。
 *
 * 最难的一条时序是"目标已经装了、源侧才反悔"——这时如果只做本地回滚，
 * 两份状态就同时存在了。所以撤销要发 ABORT，等对方卸完回 ABORTED 才回滚。
 */
function runAbort(Cluster $c1, Handoff $h1, Cluster $c2, Handoff $h2, Rec $r1, Rec $r2): void
{
    echo PHP_EOL . "== 撤销（abort）==" . PHP_EOL;

    // 1) 目标装完之后再撤销
    $commitsBefore = $r1->commits;
    T::ok($h1->begin(2, 1, "ab:1", 1, "state-1", nowMs()), "发起交接");
    pumpOne($c1, $h1, 2);                  // 只推源侧：MIGRATE 出去了，但还没人回
    T::ok($h1->isPending("ab:1"), "仍在飞");
    T::ok($h1->abort("ab:1", nowMs()), "abort 发出");
    T::ok(!$h1->isPending("ab:1"), "abort 后不再占用 pending");
    T::ok($h1->isAborting("ab:1"), "进入撤销中");

    pump($c1, $h1, $c2, $h2, 60);
    T::ok($h2->installedVersion("ab:1") === 0, "目标侧已卸掉", "ver=" . $h2->installedVersion("ab:1"));
    T::ok($r2->uninstalls === 1, "onUninstall 被调用", "n=" . $r2->uninstalls);
    T::ok(!$h1->isAborting("ab:1"), "撤销完成");
    T::ok($r1->why === "aborted", "源侧回滚原因 aborted", $r1->why);
    T::ok($r1->commits === $commitsBefore, "撤销前收到的 ACK 没有变成 commit", "commits=" . $r1->commits);

    // 2) 墓碑：撤销之后迟到的 MIGRATE 不能把状态又装回去
    $instBefore = $r2->installs;
    T::ok($h1->begin(2, 1, "ab:1", 1, "state-1", nowMs()), "同版本重发");
    pump($c1, $h1, $c2, $h2, 40);
    T::ok($r2->installs === $instBefore, "墓碑拦住重装", "n=" . $r2->installs);
    T::ok($r1->why === "rejected", "源侧收到 REJECT", $r1->why);

    // 3) 没有在飞时 abort 是空操作
    T::ok(!$h1->abort("nonexistent", nowMs()), "没有在飞时 abort 返回 false");

    // 4) 撤销等不到 ABORTED（对端崩了）：超时也要收敛
    $h1->begin(2, 1, "ab:2", 1, "state-2", nowMs());
    pumpOne($c1, $h1, 2);
    $h1->abort("ab:2", nowMs());
    T::ok($h1->isAborting("ab:2"), "进入撤销中（对端不回）");
    $h1->expire(nowMs() + 1000);
    T::ok(!$h1->isAborting("ab:2"), "超时后不再撤销中");
    T::ok($r1->why === "abort-timeout", "回滚原因 abort-timeout", $r1->why);
}

/** 租约中途丢失：在飞的交接要被撤销，而不是硬走完 */
function runLeaseLost(Cluster $c1, Handoff $h1, Cluster $c2, Handoff $h2): void
{
    echo PHP_EOL . "== 租约丢失 ==" . PHP_EOL;

    $own = new MapOwnership();
    $own->map["z:1"] = 2;
    $g = new Gate();
    $rb = new Rebalancer(1, 1, $h1, $g, $own, 4);
    $rb->useLease(new LoseLease(), 100);      // TTL 100ms，续不上
    $rb->onExport(function (string $key): ?string {
        return "st:" . $key;
    });
    $rb->own("z:1");

    T::ok($rb->scan(nowMs()) === 1, "首轮拿到租约并发起");
    T::ok($h1->isPending("z:1"), "交接在飞");

    usleep(60000);                            // 剩 40ms < TTL/2，触发续期
    $rb->scan(nowMs());
    T::ok($rb->stats()["lease_lost"] === 1, "记到一次租约丢失", "lost=" . $rb->stats()["lease_lost"]);
    T::ok($h1->isAborting("z:1"), "在飞交接被撤销而不是硬走完");

    pump($c1, $h1, $c2, $h2, 60);
    $rb->scan(nowMs());
    T::ok($g->state("z:1") === Gate::OPEN, "最终闸门回到 OPEN");
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
    $g = new Gate();

    // 源侧：成功 → commit（队列交给新宿主）；失败 → rollback（队列就地放回）
    // 这两行就是 README 里让人照抄的装配方式
    $h1->onCommit(function (string $key, int $ver) use ($r1, $g): void {
        $r1->commits++;
        $r1->lastKey = $key;
        $r1->lastVer = $ver;
        $q = $g->commit($key);
        $r1->commitQueue = implode(",", $q);
    });
    $h1->onRollback(function (string $key, int $ver, string $why) use ($r1, $g): void {
        $r1->rollbacks++;
        $r1->lastKey = $key;
        $r1->lastVer = $ver;
        $r1->why = $why;
        $q = $g->rollback($key);
        $r1->rollbackQueue = implode(",", $q);
    });
    // 目标侧：安装
    $h2->onInstall(function (string $key, string $state, int $ver) use ($r2): bool {
        $r2->installs++;
        $r2->lastKey = $key;
        $r2->lastState = $state;
        $r2->lastVer = $ver;
        return $r2->accept;
    });

    $h2->onUninstall(function (string $key, int $ver) use ($r2): void {
        $r2->uninstalls++;
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

    // 8) 与闸门联调：冻结 → 排队 → 交接成功 → 队列按序交给新宿主
    T::ok($g->freeze("room:7", 2, 1), "联调：冻结 room:7");
    $g->submit("room:7", "m1");
    $g->submit("room:7", "m2");
    T::ok($h1->begin(2, 1, "room:7", 1, "state-E", nowMs()), "联调：发起交接");
    pump($c1, $h1, $c2, $h2, 40);
    T::ok($g->state("room:7") === Gate::FORWARD, "交接成功后闸门转 FORWARD");
    T::ok($r1->commitQueue === "m1,m2", "队列按原序交给新宿主", $r1->commitQueue);
    T::ok($g->submit("room:7", "m3") === Gate::FORWARDED, "此后新请求直接转发");

    // 9) 与闸门联调：目标拒绝 → 队列在本地放回
    $g->freeze("room:8", 2, 1);
    $g->submit("room:8", "n1");
    $r1->rollbackQueue = "";
    $r2->accept = false;
    T::ok($h1->begin(2, 1, "room:8", 1, "state-F", nowMs()), "联调：发起将被拒的交接");
    pump($c1, $h1, $c2, $h2, 40);
    T::ok($g->state("room:8") === Gate::OPEN, "拒绝后闸门回 OPEN");
    T::ok($r1->rollbackQueue === "n1", "队列在本地放回", $r1->rollbackQueue);
    T::ok($g->submit("room:8", "n2") === Gate::PASS, "回滚后本地继续处理");
    $r2->accept = true;     // 复原，否则后面的重平衡用例会全被拒

    $st = $h1->stats();
    echo "  源侧统计: sent=" . $st["sent"] . " accepted=" . $st["accepted"]
        . " rejected=" . $st["rejected"] . " timeout=" . $st["timeout"]
        . " ignored=" . $st["ignored"] . PHP_EOL;

    runRebalance($c1, $h1, $c2, $h2, $r2);
    runAbort($c1, $h1, $c2, $h2, $r1, $r2);
    runLeaseLost($c1, $h1, $c2, $h2);

    $c1->closeAll();
    $c2->closeAll();

    runGate();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
