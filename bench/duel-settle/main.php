<?php

/**
 * 结算**接线**验证：从「对局结束」到「金币到账」这条链是通的。
 *
 * `bench/settle-test` 验的是 `Settle` / `Wallet` 自己的语义，`bench/settle-crash`
 * 验的是崩溃。它们都绕过了 `DuelService`——而接线恰恰是最容易出错的地方：
 * 参数传没传、结算在哪个分支触发、`$reported` 之后还会不会重入。
 *
 * 这里把 `DuelService` 真的跑起来（走 Scheduler spawn，和线上同一条路径），
 * 用「认输判负」把一局打到结束，然后推一帧让服务自己结算。
 */

use Duel\DuelManager;
use Duel\DuelService;
use Duel\Settle;
use TSF\EventLog\EventLog;
use TSF\Service\Message;
use TSF\Snapshot\FileStore;
use TSF\Snapshot\Snapshot;
use TSF\Room\RoomIds;
use TSF\Service\Scheduler;

const DIR = "/tmp/duel_settle_test";

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

    public static function eq(int $got, int $want, string $label): void
    {
        self::ok($got === $want, $label, "got=" . $got . " want=" . $want);
    }
}

function freshLog(string $name): string
{
    $dir = DIR . "/" . $name;
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    } else {
        $files = glob($dir . "/*");
        if (is_array($files)) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
    }
    return $dir . "/settle.log";
}

/** 建一个房间服务、两人入场、开局。返回 [服务, 房间号] */
function bootRoom(Scheduler $s, int $roomId, int $a, int $b, Settle $settle): DuelService
{
    $addr = $s->spawnOf(function () use ($roomId, $settle) {
        return new DuelService($roomId, null, null, null, null, null, $settle);
    });
    $svc = $s->serviceOf($addr);
    if (!($svc instanceof DuelService)) {
        throw new \RuntimeException("房间服务没建起来");
    }
    $svc->duel()->join($a);
    $svc->duel()->join($b);
    $svc->duel()->ready();
    return $svc;
}

/** 推一帧，让服务处理"已结算"分支 */
function pushTick(DuelService $svc, Scheduler $s): void
{
    $svc->onMessage(new Message(0, DuelService::MSG_TICK, ""));
    $s->tick();
}

function caseWinByForfeit(): void
{
    echo "== [1] 认输判负 → 赢家到账 ==" . PHP_EOL;
    Scheduler::reset();
    $s = Scheduler::instance();
    $path = freshLog("forfeit");
    $settle = new Settle(new EventLog($path));

    $svc = bootRoom($s, 1, 11, 22, $settle);
    T::eq($settle->balanceOf(22), 0, "开局时谁都没钱");

    // 11 认输，22 赢
    $svc->duel()->forfeit(11);
    T::ok($svc->duel()->isSettled(), "房间进入已结束");
    T::eq($settle->balanceOf(22), 0, "认输那一刻还没发钱（要等这一帧处理完）");

    pushTick($svc, $s);
    T::eq($settle->balanceOf(22), Settle::REWARD, "推一帧后赢家到账 " . Settle::REWARD);
    T::eq($settle->balanceOf(11), 0, "认输的人没钱");
    T::ok($settle->isSettled(1), "房间 1 记为已结算");

    // 再推两帧：$reported 已置位，不该重复发钱
    pushTick($svc, $s);
    pushTick($svc, $s);
    T::eq($settle->balanceOf(22), Settle::REWARD, "多推几帧不会重复发钱");
    T::eq($settle->stats()["settled"], 1, "仍然只有 1 笔结算");
}

function caseTwoRooms(): void
{
    echo "== [2] 两个房间互不影响 ==" . PHP_EOL;
    Scheduler::reset();
    $s = Scheduler::instance();
    $path = freshLog("two");
    $settle = new Settle(new EventLog($path));

    $r1 = bootRoom($s, 1, 11, 22, $settle);
    $r2 = bootRoom($s, 2, 33, 44, $settle);

    $r1->duel()->forfeit(11);   // 22 赢
    $r2->duel()->forfeit(44);   // 33 赢

    pushTick($r1, $s);
    pushTick($r2, $s);

    T::eq($settle->balanceOf(22), Settle::REWARD, "房间 1 的赢家到账");
    T::eq($settle->balanceOf(33), Settle::REWARD, "房间 2 的赢家到账");
    T::eq($settle->balanceOf(11), 0, "房间 1 的输家没钱");
    T::eq($settle->balanceOf(44), 0, "房间 2 的输家没钱");
    T::eq($settle->stats()["settled"], 2, "两笔独立结算");
}

function caseDraw(): void
{
    echo "== [3] 平局不发奖 ==" . PHP_EOL;
    Scheduler::reset();
    $s = Scheduler::instance();
    $path = freshLog("draw");
    $settle = new Settle(new EventLog($path));

    // 房间里只有一个人时认输 → 没有对手，记平局（winner = -1）
    $addr = $s->spawnOf(function () use ($settle) {
        return new DuelService(9, null, null, null, null, null, $settle);
    });
    $svc = $s->serviceOf($addr);
    if (!($svc instanceof DuelService)) {
        throw new \RuntimeException("房间服务没建起来");
    }
    $svc->duel()->join(55);
    $svc->duel()->forfeit(55);
    pushTick($svc, $s);

    T::eq($settle->wallet()->total(), 0, "平局没有任何人加钱");
    T::eq($settle->stats()["settled"], 0, "也没有写结算事件");
}

function caseRestartAfterWiring(): void
{
    echo "== [4] 结算后的日志能被新进程读回来 ==" . PHP_EOL;
    $path = DIR . "/forfeit/settle.log";
    $settle = new Settle(new EventLog($path));
    T::eq($settle->balanceOf(22), Settle::REWARD, "重建后赢家余额仍在");
    T::ok($settle->isSettled(1), "已结算集合也回来了");
}

/**
 * 房号复用会让"重启后的第一局"被判成已结算——钱静默不发，日志里也不会多一行。
 * 这条是照着真实踩到的坑写的：`run.sh` 跑完 `run-crash.sh` 之后再来一局，
 * 结果 `RESULT WIN` 打出但 `settle.log` 没动。
 */
function caseNoRoomIdReuse(): void
{
    echo "== [5] 重启后房号不复用已结算的号 ==" . PHP_EOL;
    Scheduler::reset();
    $s = Scheduler::instance();

    $path = freshLog("reuse");
    $log = new EventLog($path);
    $settle = new Settle($log);
    $settle->settle(1, 1001);
    $settle->settle(2, 1002);
    $settle->settle(3, 1001);
    T::eq($settle->maxSettledRoom(), 3, "记下已结算的最大房号");
    $log->close();

    // 模拟重启：新进程、同一个日志文件
    $log2 = new EventLog($path);
    $settle2 = new Settle($log2);
    T::eq($settle2->maxSettledRoom(), 3, "重建后仍然是 3");

    $mgr = new DuelManager(null, null, null, $settle2);
    $room = $mgr->create(7001, 7002);
    T::eq($room, 4, "新房号从 4 开始，不碰 1..3");
    T::ok($room > $settle2->maxSettledRoom(), "新房号严格大于已结算的最大号");

    // 4 号房真的能结算（证明没被幂等表挡住）
    T::ok($settle2->settle($room, 7001), "4 号房能正常结算");
    T::eq($settle2->balanceOf(7001), Settle::REWARD, "钱到账了");
    $log2->close();
}


/**
 * 房间号按节点分段：node 0 与旧行为一致，node 1 起从 10 亿段开始，互不重叠。
 */
function caseSegmentIds(): void
{
    echo "== [6] 房间号按节点分段 ==" . PHP_EOL;
    T::eq(RoomIds::compose(0, 7), 7, "node 0 的号就是序号（向后兼容）");
    T::eq(RoomIds::compose(1, 7), 1000000007, "node 1 在 10 亿段");
    T::eq(RoomIds::node(1000000007), 1, "反解节点号");
    T::eq(RoomIds::seq(1000000007), 7, "反解段内序号");
    T::eq(RoomIds::nextSeq(1, [1, 2, 3, 1000000005]), 6, "只在本段内取最大");
    T::eq(RoomIds::compose(256, 1), 0, "越界节点返回 0");
    T::eq(RoomIds::compose(0, 1000000000), 0, "段末溢出返回 0（建房会拒绝，不会绕回 1）");
    T::eq(RoomIds::compose(1, 0), 0, "序号 0 非法（0 号房是保留值）");
    // 用 ok 而不是 eq：T::eq 的第一个参数在前面几次调用里已经定型成 int，
    // AOT 按**首次调用**定型后，这里再传 string 会报
    // "Cannot re-assign variable from php::Str to php::Int"
    T::ok(RoomIds::label(1000000007) === "n1#7", "展示形式");

    Scheduler::reset();
    $path = freshLog("seg");
    $log = new EventLog($path);
    $st = new Settle($log);
    $st->settle(1000000003, 9001);   // node 1 的段
    $st->settle(42, 9001);           // node 0 的段
    $log->close();

    $log2 = new EventLog($path);
    $st2 = new Settle($log2);
    T::eq($st2->maxRoomIn(1), 1000000003, "段内最大号");
    T::eq($st2->maxRoomIn(0), 42, "另一段的最大号");

    $mgr = new DuelManager(null, null, null, $st2, 1);
    T::eq($mgr->create(9101, 9102), 1000000004, "node 1 的下一个号接在本段后面");
    $mgr2 = new DuelManager(null, null, null, $st2, 0);
    T::eq($mgr2->create(9201, 9202), 43, "node 0 不受 node 1 的号影响");
    $log2->close();
}


/**
 * 认领：只恢复本节点号段里的房间，**且绝不删别人的快照**。
 *
 * 这里用两个"重建必失败"的快照来区分两种跳过：自己的坏了该删（下次启动别再卡），
 * 别人的连读都不该读、更不能删。若把归属过滤错做成 rebuild 返回 false，
 * 下面那句"别人的快照没被删"就会红——那正是多节点下最贵的一种 bug（玩家回不来且不报错）。
 */
function caseClaimBySegment(): void
{
    echo "== [7] 认领：按号段过滤，且不删别人的快照 ==" . PHP_EOL;

    $logPath = freshLog("claim");
    $dir = dirname($logPath) . "/snap";
    @mkdir($dir, 0777, true);
    $files = glob($dir . "/*");
    if (is_array($files)) {
        foreach ($files as $f) {
            @unlink($f);
        }
    }
    $store = new FileStore($dir);

    $a = new Snapshot("duel", 1);
    $a->put("duel", []);
    $store->put($a);
    $b = new Snapshot("duel", 1000000001);
    $b->put("duel", []);
    $store->put($b);
    T::eq(count($store->keys("duel")), 2, "先放了两个快照");

    Scheduler::reset();
    $own0 = new DuelManager(null, null, $store, null, 0);
    $stat = $own0->restoreAll($store);

    T::eq($stat["not_mine"], 1, "node 1 的那个不算我的");
    T::eq($stat["skipped"], 1, "我的那个解不出来，跳过");
    T::eq($stat["done"], 0, "没有恢复成功");

    $left = $store->keys("duel");
    T::ok(in_array(1000000001, $left), "**别人的快照没被删**");
    T::ok(!in_array(1, $left), "自己的坏快照被删掉（下次启动不再卡）");

    // 换 node 1 来认领：它能看到自己的那个（坏的），不会碰到别人的
    Scheduler::reset();
    $own1 = new DuelManager(null, null, $store, null, 1);
    $stat1 = $own1->restoreAll($store);
    T::eq($stat1["not_mine"], 0, "node 1 眼里没有别人的快照");
    T::eq($stat1["skipped"], 1, "它自己那个也解不出来");
    T::eq(count($store->keys("duel")), 0, "两边各自清掉自己的坏快照");
}

function main(): void
{
    @mkdir(DIR, 0777, true);
    caseWinByForfeit();
    caseTwoRooms();
    caseDraw();
    caseRestartAfterWiring();
    caseNoRoomIdReuse();
    caseSegmentIds();
    caseClaimBySegment();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
    exit(T::$fail === 0 ? 0 : 1);
}
