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

function main(): void
{
    @mkdir(DIR, 0777, true);
    caseWinByForfeit();
    caseTwoRooms();
    caseDraw();
    caseRestartAfterWiring();
    caseNoRoomIdReuse();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
    exit(T::$fail === 0 ? 0 : 1);
}
