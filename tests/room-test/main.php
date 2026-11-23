<?php

use TSF\Room\Member;
use TSF\Room\MemberTable;
use TSF\Room\Room;
use TSF\Room\RoomHost;
use TSF\Room\RoomManager;
use TSF\Room\RoomService;
use TSF\Room\ThreeStage;
use TSF\Service\Scheduler;

/**
 * pkg/room 回归。
 *
 * ## 与 v0.7 之前版本的差异（写在这里，免得下次又照旧 API 写一遍）
 *
 * `Room` 自 `b0f6b5f` 起**不认识任何状态语义**：状态机由业务注入（`Machine`），
 * `Room` 只保存一颗不解释含义的状态值。因此：
 *
 *   - `WAITING/PLAYING/SETTLED/CLOSED` 四个常量搬到 `ThreeStage`，不在 `Room` 上；
 *   - `Room::start()/finish()` 没有替代品，业务自己 `fsm()->fire("start")`；
 *   - `Room::close()` 只置标志 + 触发 `close` 边，**不清成员**；
 *   - "结算后拒收" 不再是 `Room` 的行为——它不判断状态，业务要自己看 `state()`。
 *
 * 这份用例就是按上面四条写的；旧断言里凡是被改掉的，都在原处标了原因。
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

    public static function eq(int|string|float|bool $got, int|string|float|bool $want, string $name): void
    {
        self::ok($got === $want, $name, "got=" . var_export($got, true) . " want=" . var_export($want, true));
    }
}

/** 把一条消息投给房间并立刻处理 */
function sendTo(Scheduler $s, int $addr, int $msgId, string $data = ""): void
{
    $s->deliver($addr, $msgId, $data);
    $s->tick();
}

/**
 * 触发房间状态机的约定事件。
 *
 * 测试里刻意保留这层小包装：`Room` 没有 start/finish，而每个用例都要写
 * `$room->fsm()` 判空——包一次比每处判一次更容易看出"这里在推进状态"。
 */
function fire(Room $room, string $event): bool
{
    $fsm = $room->fsm();
    if ($fsm === null) {
        return false;
    }
    return $fsm->fire($event);
}

function caseLifecycle(): void
{
    echo "[1] 房间生命周期与状态机" . PHP_EOL;
    Scheduler::reset();
    $s = Scheduler::instance();
    $mgr = new RoomManager();
    $rid = $mgr->create(2);
    $addr = $mgr->addrOf($rid);
    $svc = $s->serviceOf($addr);
    if (!($svc instanceof RoomService)) {
        T::ok(false, "创建房间服务");
        return;
    }
    $room = $svc->room();

    T::ok($mgr->count() === 1, "管理器里有 1 个房间");
    T::ok($room->state() === ThreeStage::WAITING, "初始为 WAITING");
    T::ok($room->fsm() !== null, "RoomService 默认注入三段式状态机");
    T::ok($s->timers()->size() === 1, "帧定时器已注册", "timers=" . $s->timers()->size());

    sendTo($s, $addr, RoomService::MSG_JOIN, "1");
    T::ok($room->playerCount() === 1, "加入 1 人");
    sendTo($s, $addr, RoomService::MSG_JOIN, "1");
    T::ok($room->playerCount() === 1, "重复加入被拒");
    sendTo($s, $addr, RoomService::MSG_JOIN, "2");
    sendTo($s, $addr, RoomService::MSG_JOIN, "3");
    T::ok($room->playerCount() === 2, "第 3 人被拒（满员）", "count=" . $room->playerCount());

    sendTo($s, $addr, RoomService::MSG_START);
    T::ok($room->state() === ThreeStage::PLAYING, "开始后为 PLAYING");
    sendTo($s, $addr, RoomService::MSG_START);
    T::ok($room->state() === ThreeStage::PLAYING, "重复 start 被拒");

    sendTo($s, $addr, RoomService::MSG_FINISH);
    T::ok($room->state() === ThreeStage::SETTLED, "结束后为 SETTLED");

    // 全员离开 → 自动回收
    sendTo($s, $addr, RoomService::MSG_LEAVE, "1");
    sendTo($s, $addr, RoomService::MSG_LEAVE, "2");
    T::ok(!$s->has($addr), "房间空了之后服务被销毁");
    T::ok($mgr->count() === 0, "管理器里已清空", "count=" . $mgr->count());
    T::ok($s->timers()->size() === 0, "帧定时器被撤销", "timers=" . $s->timers()->size());
}

function caseClosedRejects(): void
{
    echo PHP_EOL . "[2] 关闭后拒绝一切加入/开始" . PHP_EOL;
    Scheduler::reset();
    $s = Scheduler::instance();
    $mgr = new RoomManager();
    $rid = $mgr->create(4);
    $addr = $mgr->addrOf($rid);
    $svc = $s->serviceOf($addr);
    if (!($svc instanceof RoomService)) {
        return;
    }
    $room = $svc->room();
    sendTo($s, $addr, RoomService::MSG_JOIN, "1");
    sendTo($s, $addr, RoomService::MSG_CLOSE);

    T::ok($room->state() === ThreeStage::CLOSED, "状态为 CLOSED");
    T::ok(!$room->add(9), "CLOSED 后 add 返回 false");
    T::ok(!fire($room, "start"), "CLOSED 后 fire(start) 返回 false");
    // 旧用例断言的是"关闭时清空玩家"。现在 close() 只置标志——
    // 结算与快照还要按 uid 遍历，清空会把数据一起丢掉。
    T::ok($room->playerCount() === 1, "close 不清成员（只置标志）", "count=" . $room->playerCount());
    T::ok($room->tick(50) === false, "CLOSED 后 tick 不推进");
}

/** 成员身份：观战不占席位、席位回收、状态语义归业务 */
function caseMemberRoles(): void
{
    echo PHP_EOL . "[2.1] 成员身份与席位" . PHP_EOL;

    // 成员表相关用例不需要状态机也能跑，但统一用三段式，
    // 免得"有的房间带 fsm、有的不带"把后面的断言搅乱
    $room = ThreeStage::room(1, 4);
    T::eq($room->maxPlayers(), 4, "席位上限 4");

    for ($i = 1; $i <= 4; $i++) {
        T::ok($room->add($i, "p" . $i), "玩家 " . $i . " 入座");
    }
    T::eq($room->playerCount(), 4, "4 个席位坐满");
    T::ok($room->members()->isFull(), "isFull 为真");

    // 满员后玩家进不来
    T::ok(!$room->add(5), "满员后第 5 个玩家被拒");

    // 但观战可以，且不占席位
    $sp = $room->addAs(100, Member::SPECTATOR, "观众甲");
    T::ok($sp !== null, "满员时观战仍能进");
    if ($sp !== null) {
        T::eq($sp->seat, -1, "观战没有席位号");
        T::eq($sp->displayName(), "观众甲", "显示名");
        T::ok($sp->isSpectator(), "isSpectator");
        T::ok(!$sp->occupiesSeat(), "观战不占席位");
    }
    T::eq($room->playerCount(), 4, "观战不改变占席位数");
    T::eq($room->memberCount(), 5, "总人数 5");

    // 席位号稳定
    T::eq($room->members()->seatOf(1), 0, "1 号坐 0");
    T::eq($room->members()->seatOf(4), 3, "4 号坐 3");
    T::eq($room->members()->seatOf(100), -1, "观战 seatOf 返回 -1");

    // 走一个，空出的座位给下一个人
    T::ok($room->remove(2), "2 号离开");
    T::eq($room->playerCount(), 3, "剩 3 席");
    $m6 = $room->addAs(6, Member::PLAYER, "补位");
    T::ok($m6 !== null, "空位能补人");
    if ($m6 !== null) {
        T::eq($m6->seat, 1, "补到 2 号空出的 1 号位");
    }

    // 身份过滤。`seated()` 在 MemberTable 上，Room 只透出 uids()——
    // 需要"按席位排"这类成员表语义，就显式走 members()
    T::eq(count($room->members()->seated()), 4, "占席位 4 人");
    T::eq(count($room->members()->spectators()), 1, "观战 1 人");
    T::eq(count($room->uids()), 5, "全部 5 人（含观战）");

    // 席位顺序
    $seated = $room->members()->seated();
    T::eq($seated[0], 1, "席位 0 是 1 号");
    T::eq($seated[1], 6, "席位 1 是补位的 6 号");

    // 结算后 Room **不**自动拒人：它不解释状态（见 Room 类注释）。
    // 旧用例断言"SETTLED 后拒收"，那是状态机还焊在 Room 里时的行为。
    // 业务要拒绝，得自己看 state()——这里把这条契约固定下来。
    fire($room, "start");
    fire($room, "finish");
    T::eq($room->state(), ThreeStage::SETTLED, "已结算");

    // "状态不参与准入"必须用**空房**验：当前这间 4 个席位已坐满，
    // add 失败会来自满员而不是状态，断言会假绿。
    $room3 = ThreeStage::room(3, 4);
    fire($room3, "start");
    fire($room3, "finish");
    T::eq($room3->state(), ThreeStage::SETTLED, "新房间结算");
    T::ok($room3->add(7), "SETTLED 后 add 仍成功（Room 不解释状态）");
    T::ok($room3->addAs(101, Member::SPECTATOR) !== null, "SETTLED 后观战也收");

    // close()：置标志 + 走 close 边，成员保留
    $before = $room->memberCount();
    $room->close();
    T::ok($room->isClosed(), "close 后 isClosed 为真");
    T::eq($room->memberCount(), $before, "close 不清成员");
    T::ok(!$room->add(8), "关闭后不再收人");
    T::eq($room->state(), ThreeStage::CLOSED, "状态机走到 CLOSED");
    T::ok(!fire($room, "start"), "CLOSED 后无法再开战");

    // 非法身份
    $room2 = ThreeStage::room(2, 2);
    T::ok($room2->addAs(1, 99) === null, "未知身份被拒");
    T::ok($room2->addAs(0, Member::PLAYER) === null, "uid=0 被拒");
    T::ok($room2->addAs(-5, Member::PLAYER) === null, "负 uid 被拒");
}

/** 成员表可以脱离 Room 单独用（棋牌常见：一桌就是一张表） */
function caseMemberTableAlone(): void
{
    echo PHP_EOL . "[2.2] MemberTable 独立使用" . PHP_EOL;

    $t = new MemberTable(2);
    T::eq($t->capacity(), 2, "容量 2");

    $a = $t->add(1, Member::PLAYER, "A");
    T::ok($a !== null, "加入 A");
    if ($a !== null) {
        T::eq($a->seat, 0, "A 坐 0");
    }
    T::ok($t->add(2) !== null, "加入 B");

    // 满了：玩家拒、观战收
    T::ok($t->add(3) === null, "席位满，玩家进不来");
    T::ok($t->add(9, Member::SPECTATOR) !== null, "观战照收");
    T::eq($t->occupants(), 2, "占席位 2");
    T::eq($t->count(), 3, "总人数 3");

    // 重复 uid
    T::ok($t->add(1) === null, "重复 uid 被拒");

    // NPC 也占席位
    $t2 = new MemberTable(3);
    $npc = $t2->add(50, Member::NPC, "巡逻兵");
    T::ok($npc !== null, "NPC 能加入");
    if ($npc !== null) {
        T::ok(!$npc->isPlayer(), "NPC 不是玩家");
        T::ok($npc->occupiesSeat(), "NPC 占席位");
    }
    T::eq($t2->occupants(), 1, "NPC 计入席位");

    // 清空
    $t->clear();
    T::eq($t->count(), 0, "clear 后为空");
    T::eq($t->occupants(), 0, "席位也清空");
    T::ok($t->add(1) !== null, "清空后能重新加同 uid");
}

function caseFrameLoop(): void
{
    echo PHP_EOL . "[3] 帧循环：只推进时钟，不碰坐标" . PHP_EOL;
    Scheduler::reset();
    $s = Scheduler::instance();
    $mgr = new RoomManager();
    $rid = $mgr->create(2);
    $addr = $mgr->addrOf($rid);
    $svc = $s->serviceOf($addr);
    if (!($svc instanceof RoomService)) {
        return;
    }
    $room = $svc->room();
    sendTo($s, $addr, RoomService::MSG_JOIN, "1");
    sendTo($s, $addr, RoomService::MSG_START);

    // 真实等待 60ms，让 onFrame 算出的 delta ≈ 60
    usleep(60000);
    sendTo($s, $addr, RoomService::MSG_TICK);

    // 50ms 的帧定时器在这 60ms 里也可能自己触发一次，所以断言"至少 1 帧"
    T::ok($room->frame() >= 1, "帧数有推进", "frame=" . $room->frame());
    T::ok($room->elapsedMs() >= 50, "累计时间按 delta 增长", "elapsed=" . $room->elapsedMs() . " ms");

    // 静止状态（WAITING）不推进
    Scheduler::reset();
    $s2 = Scheduler::instance();
    $mgr2 = new RoomManager();
    $rid2 = $mgr2->create(2);
    $a2 = $mgr2->addrOf($rid2);
    $svc2 = $s2->serviceOf($a2);
    if ($svc2 instanceof RoomService) {
        sendTo($s2, $a2, RoomService::MSG_JOIN, "1");
        usleep(30000);
        sendTo($s2, $a2, RoomService::MSG_TICK);
        T::ok($svc2->room()->frame() === 0, "WAITING 时不推进帧");
    }
}

function caseNoLeak(): void
{
    echo PHP_EOL . "[4] 批量创建/销毁不泄漏" . PHP_EOL;
    Scheduler::reset();
    $s = Scheduler::instance();
    $mgr = new RoomManager();
    $baseline = $s->serviceCount();

    $n = 100;
    for ($i = 0; $i < $n; $i++) {
        $rid = $mgr->create(4);
        $addr = $mgr->addrOf($rid);
        sendTo($s, $addr, RoomService::MSG_JOIN, "1");
        sendTo($s, $addr, RoomService::MSG_LEAVE, "1");
    }
    $s->tick();

    T::ok($mgr->count() === 0, "房间全部回收", "count=" . $mgr->count());
    T::ok($s->serviceCount() === $baseline, "服务数回到基线",
        "now=" . $s->serviceCount() . " baseline=" . $baseline);
    T::ok($s->timers()->size() === 0, "定时器全部撤销", "timers=" . $s->timers()->size());
    T::ok($mgr->stats()["closed"] === $n, "closed 计数正确", "closed=" . $mgr->stats()["closed"]);

    // 主动销毁也要能收敛
    $rid = $mgr->create(4);
    $addr = $mgr->addrOf($rid);
    sendTo($s, $addr, RoomService::MSG_JOIN, "1");
    T::ok($mgr->destroy(1 + $n), "主动销毁返回 true");
    $s->tick();
    T::ok($mgr->count() === 0 && $s->timers()->size() === 0, "主动销毁后同样收敛");
}

function caseTickPerf(): void
{
    echo PHP_EOL . "[5] 帧耗时：200 成员（不含坐标推进）" . PHP_EOL;
    Scheduler::reset();
    $s = Scheduler::instance();
    $mgr = new RoomManager();
    $rid = $mgr->create(200);
    $addr = $mgr->addrOf($rid);
    $svc = $s->serviceOf($addr);
    if (!($svc instanceof RoomService)) {
        return;
    }
    for ($uid = 1; $uid <= 200; $uid++) {
        $s->deliver($addr, RoomService::MSG_JOIN, (string) $uid);
    }
    $s->tick();
    sendTo($s, $addr, RoomService::MSG_START);

    T::ok($svc->room()->playerCount() === 200, "200 个成员就位",
        "count=" . $svc->room()->playerCount());

    // 跑 200 帧，每帧间隔约 1ms（delta 小，但遍历成本与实际一致）
    for ($i = 0; $i < 200; $i++) {
        usleep(1000);
        $s->deliver($addr, RoomService::MSG_TICK);
        $s->tick();
    }
    $st = $svc->stats();
    $maxUs = $st["max_tick_us"];
    // 这 200ms 里 50ms 的帧定时器也会自己触发几次，所以是"至少 200 帧"
    T::ok($st["ticks"] >= 200, "跑了至少 200 帧", "ticks=" . $st["ticks"]);
    T::ok($maxUs <= 5000, "单帧耗时 ≤5ms", "max=" . $maxUs . " µs");
    echo "  单帧耗时        max=" . $maxUs . " µs，最后一帧=" . $st["last_tick_us"] . " µs" . PHP_EOL;
}

function caseShared(): void
{
    echo PHP_EOL . "[6] 共享宿主：多房一 tick（T3-03）" . PHP_EOL;

    // ---- 基线：独占模式 ----
    Scheduler::reset();
    $s1 = Scheduler::instance();
    $mgr1 = new RoomManager();
    for ($i = 0; $i < 20; $i++) {
        $mgr1->create(4);
    }
    $s1->tick();
    $st1 = $s1->stats();
    $c1 = (int) $st1["services"];
    $t1 = (int) $st1["timers"];
    echo "        独占 20 房: services=" . $c1 . " timers=" . $t1 . PHP_EOL;
    T::ok($c1 === 20, "独占：20 个房间 = 20 个 Service", "services=" . $c1);

    // ---- 共享模式 ----
    Scheduler::reset();
    $s2 = Scheduler::instance();
    $mgr2 = new RoomManager(RoomManager::MODE_SHARED, 50, 100);
    for ($i = 0; $i < 20; $i++) {
        $mgr2->create(4);
    }
    $s2->tick();
    $st2 = $s2->stats();
    $c2 = (int) $st2["services"];
    $t2 = (int) $st2["timers"];
    echo "        共享 20 房: services=" . $c2 . " timers=" . $t2 . PHP_EOL;
    T::ok($c2 === 1, "共享：20 个房间只占 1 个 Service", "services=" . $c2);
    T::ok($t2 === 1, "只有 1 个定时器", "timers=" . $t2);

    // ---- 功能等价：加入 / 开战 / 推进帧 ----
    $rid = $mgr2->create(4);
    $s2->tick();
    $hostAddr = $mgr2->addrOf($rid);
    $host = $s2->serviceOf($hostAddr);
    if (!($host instanceof RoomHost)) {
        T::ok(false, "拿到 RoomHost");
        return;
    }
    T::ok($host->roomCount() === 21, "宿主里挂了 21 个房间", "count=" . $host->roomCount());

    $mgr2->send($rid, RoomManager::OP_JOIN, "501");
    $mgr2->send($rid, RoomManager::OP_START);
    $s2->tick();

    $room = $host->room($rid);
    if ($room === null) {
        T::ok(false, "房间存在于宿主");
        return;
    }
    T::ok($room->playerCount() === 1, "玩家加入成功", "players=" . $room->playerCount());
    T::ok($room->state() === ThreeStage::PLAYING, "已开战", "state=" . $room->stateName());

    // 宿主统一驱动：投一帧，房间里的实体应该推进
    usleep(60000);
    $s2->deliver($hostAddr, RoomHost::MSG_TICK);
    $s2->tick();
    T::ok($room->frame() >= 1, "宿主推进了帧", "frame=" . $room->frame());

    // ---- 回收：空房间从宿主摘掉、管理器同步 ----
    $mgr2->send($rid, RoomManager::OP_LEAVE, "501");
    $s2->tick();
    T::ok($host->roomCount() === 20, "空房间被宿主回收", "count=" . $host->roomCount());
    T::ok($mgr2->has($rid) === false, "管理器同步摘表");
    $st2b = $mgr2->stats();
    T::ok((int) $st2b["live"] === 20, "存活计数正确", "live=" . (int) $st2b["live"]);

    // ---- 满员自动开新宿主 ----
    Scheduler::reset();
    $s3 = Scheduler::instance();
    $mgr3 = new RoomManager(RoomManager::MODE_SHARED, 50, 5);
    for ($i = 0; $i < 12; $i++) {
        $mgr3->create(2);
    }
    $s3->tick();
    $st3 = $mgr3->stats();
    $h3 = (int) $st3["hosts"];
    $m3 = (int) $st3["max_host_load"];
    T::ok($h3 === 3, "每宿主 5 间，12 间分到 3 个宿主", "hosts=" . $h3);
    T::ok($m3 === 5, "单个宿主最多 5 间", "max=" . $m3);
}

function caseSharedScale(): void
{
    echo PHP_EOL . "[7] 规模：1000 个房间的承载开销" . PHP_EOL;

    Scheduler::reset();
    $s1 = Scheduler::instance();
    $mgr1 = new RoomManager();
    $t0 = hrtime(true);
    for ($i = 0; $i < 1000; $i++) {
        $mgr1->create(4);
    }
    $s1->tick();
    $us1 = intdiv(hrtime(true) - $t0, 1000);
    $st1 = $s1->stats();
    $c1 = (int) $st1["services"];
    $tm1 = (int) $st1["timers"];
    echo "        独占: services=" . $c1 . " timers=" . $tm1 . " 建模耗时=" . $us1 . " µs" . PHP_EOL;

    Scheduler::reset();
    $s2 = Scheduler::instance();
    $mgr2 = new RoomManager(RoomManager::MODE_SHARED, 50, 200);
    $t1 = hrtime(true);
    for ($i = 0; $i < 1000; $i++) {
        $mgr2->create(4);
    }
    $s2->tick();
    $us2 = intdiv(hrtime(true) - $t1, 1000);
    $st2 = $s2->stats();
    $c2 = (int) $st2["services"];
    $tm2 = (int) $st2["timers"];
    echo "        共享: services=" . $c2 . " timers=" . $tm2 . " 建模耗时=" . $us2 . " µs" . PHP_EOL;

    T::ok($c2 === 5, "1000 间 / 每宿主 200 = 5 个宿主", "services=" . $c2);
    T::ok($tm2 === 5, "5 个定时器（独占是 1000 个）", "timers=" . $tm2);
    T::ok($us2 < $us1, "共享模式建模更快", "共享 " . $us2 . " vs 独占 " . $us1 . " µs");
}


/**
 * 空闲宿主回收。
 *
 * `closeIdleHosts()` 之前是**零调用 + 零测试**：注释里写着"交给巡检定期调用更安全"，
 * 但仓库里没有任何地方调它，demo 也没有。等于"提供了工具但没人知道要用"。
 *
 * 不回收的后果不是泄漏（`ensureHost()` 会复用 load 未满的宿主，上限是
 * 峰值房间数 / maxRoomsPerHost），而是**峰值过后不缩容**——空宿主还在转定时器。
 * 要不要回收取决于业务对这点开销的态度，但至少得有一条测试证明它真的能用。
 */
function caseReapIdle(): void
{
    echo PHP_EOL . "== 空闲宿主回收 ==" . PHP_EOL;
    Scheduler::reset();
    $s = Scheduler::instance();

    // 先断言前置条件：reset 之后必须是 0 个服务。
    // 不写这条的话，前面用例残留的 Service 会让后面的计数断言莫名失败，
    // 而失败信息只会说"期望 2 实际 3"，看不出是 reset 没清干净还是分配算错了。
    $svc0 = (int) $s->stats()["services"];
    T::ok($svc0 === 0, "reset 后 services 归零（前置条件）", "svc=" . $svc0);

    $mgr = new RoomManager(RoomManager::MODE_SHARED, 50, 2);

    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $ids[] = $mgr->create(4);
    }
    $s->tick();
    // 用增量而不是绝对值：即使前置条件不满足，也能看出这一步自己加了几个人
    $svc = (int) $s->stats()["services"] - $svc0;
    T::ok($svc === 2, "3 间 / 每宿主 2 = 2 个宿主", "新增=" . $svc);

    foreach ($ids as $rid) {
        $mgr->destroy($rid);
    }
    $s->tick();

    $reaped = $mgr->closeIdleHosts();
    T::ok($reaped === 2, "回收了 2 个空宿主", "n=" . $reaped);
    $svcAfter = (int) $s->stats()["services"];
    T::ok($svcAfter === $svc0, "回收后服务数回到初始值", "now=" . $svcAfter . " base=" . $svc0);
    T::ok($mgr->count() === 0, "管理器里已无房间记录", "count=" . $mgr->count());

    // 回收之后还能正常开房：hostAddr 必须被清 0，否则会把新房间投给已死的宿主
    $id2 = $mgr->create(4);
    $s->tick();
    T::ok($mgr->has($id2), "回收后再开房仍然可用");
    $svc2 = (int) $s->stats()["services"];
    T::ok($svc2 === 1, "只重建了 1 个宿主", "services=" . $svc2);
}

function main(): void
{
    echo "== Room 生命周期与帧循环测试 ==" . PHP_EOL;
    caseLifecycle();
    caseClosedRejects();
    caseMemberRoles();
    caseMemberTableAlone();
    caseFrameLoop();
    caseNoLeak();
    caseTickPerf();
    caseShared();
    caseSharedScale();
    caseReapIdle();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
