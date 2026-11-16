<?php

namespace Duel;

use TSF\Db\Repo;
use TSF\Kernel\Logger;
use TSF\Service\Scheduler;
use TSF\Room\RoomIds;
use TSF\Snapshot\Restorer;
use TSF\Snapshot\Snapshot;
use TSF\Snapshot\Store;

/**
 * 对战房间管理器：建房 + uid → 房间的转发表。
 *
 * 为什么要这张表：客户端每帧都可能发 MOVE/ATTACK。如果让客户端带房间号，
 * 等于把路由权交给客户端——改个包就能打进别人的房间。所以服务端按 **uid** 查，
 * 客户端根本不知道房间地址。
 */
final class DuelManager
{
    /** @var array<int, int> uid => 房间服务地址 */
    private array $addrOf = [];

    private int $nextRoomId = 1;

    private int $created = 0;

    /** @var \Closure|null (int $uid, int $msgId, array $payload): void */
    private ?\Closure $publish;

    /** 对局记录仓储，向下传给每个房间 */
    private ?Repo $records;

    /** 快照存储，向下传给每个房间 */
    private ?Store $store;

    /** 金币结算，向下传给每个房间（**同一个实例**，去重表才有一致的视图） */
    private ?Settle $settle;

    /** 本进程的节点号：房间号按节点分段编址（见 `TSF\Room\RoomIds`） */
    private int $nodeId = 0;

    /**
     * 下面这三个（`Repo` / `Store` / `Settle`）是**进程内设施**，直接传进每个房间服务的
     * 构造函数。单进程单线程下完全安全——调度器保证同一个服务不会并发执行。
     *
     * 但这意味着这些服务**搬不到别的节点**：`Repo` 握着 PDO、`Settle` 握着内存余额表和
     * 去重表，它们不是可以通过消息传的东西。真要把房间拆出去，这三样得改成
     * 「远程调用」或「每节点一份」，而不是照抄这段构造。
     *
     * `Settle` 必须**共享同一个实例**：每个房间各 new 一个的话，去重表就分裂了，
     * 同一局在两处都判"没结过账"。
     */
    public function __construct(
        ?\Closure $publish = null,
        ?Repo $records = null,
        ?Store $store = null,
        ?Settle $settle = null,
        int $nodeId = 0
    ) {
        $this->publish = $publish;
        $this->records = $records;
        $this->store = $store;
        $this->settle = $settle;
        $this->nodeId = $nodeId;

        // 房间号从"本节点号段内、已结算的最大号 + 1"开始。
        //
        // 房间号不持久化（每次启动从 1 数起），但结算的幂等键是 `duel:<房间号>`。
        // 如果号复用，重启后的第一局会被 `Settle` 判定"这个 id 已经结过账"而直接跳过——
        // 玩家赢了却没拿到钱，而且日志里什么都不会多一行，极难发现。
        // 快照恢复只挡住"还没结算的房间"，已结算的那些快照已被删除，只能靠事件日志回推。
        //
        // 号是**分段**的（`RoomIds`）：只在本节点段内续，别的节点的号不参与，
        // 否则节点 1 会从节点 0 的号后面接着数，白扔掉自己整段空间。
        // nodeId=0 时 `compose(0, n) === n`，行为和以前完全一样。
        if ($settle !== null) {
            $this->nextRoomId = RoomIds::compose(
                $this->nodeId,
                RoomIds::nextSeq($this->nodeId, [$settle->maxRoomIn($this->nodeId)])
            );
        }
    }

    /**
     * 为一对玩家建房并立即开战，返回房间号；失败返回 0。
     *
     * 重复建房会被拒：玩家已经在某局里时不该再被配对（匹配器那边也做了去重，
     * 这里是第二道闸——两处都挡，是因为漏一个就会出现"同一个人在两局里"的脏状态）。
     */
    public function create(int $uidA, int $uidB): int
    {
        if ($uidA === $uidB || $uidA <= 0 || $uidB <= 0) {
            return 0;
        }
        if (isset($this->addrOf[$uidA]) || isset($this->addrOf[$uidB])) {
            return 0;
        }

        $roomId = $this->nextRoomId;
        $this->nextRoomId++;

        $pub = $this->publish;
        $mgr = $this;
        $rec = $this->records;
        $st = $this->store;
        $se = $this->settle;
        $addr = Scheduler::instance()->spawnOf(function () use ($roomId, $pub, $mgr, $rec, $st, $se) {
            return new DuelService($roomId, $pub, $mgr, $rec, $st, null, $se);
        });

        $svc = Scheduler::instance()->serviceOf($addr);
        if (!($svc instanceof DuelService)) {
            Scheduler::instance()->kill($addr);
            return 0;
        }

        $svc->duel()->join($uidA);
        $svc->duel()->join($uidB);
        $svc->duel()->ready();

        $this->addrOf[$uidA] = $addr;
        $this->addrOf[$uidB] = $addr;
        $this->created++;
        return $roomId;
    }

    public function inDuel(int $uid): bool
    {
        return isset($this->addrOf[$uid]);
    }

    /**
     * 从快照恢复所有未结束的对局。
     *
     * **只重建服务，不等玩家**：玩家的 TCP 连接在进程崩溃时就断了，
     * 重连是接入层（M5）的事。这里保证的是"房间本身活着"——
     * 玩家重连上来时，uid → 地址的转发表已经在了，能直接回到原来的对局。
     *
     * 分批与坏数据清理交给 `Restorer`：它不认识 Room，只负责"枚举 → 读取 → 回调"，
     * 重建逻辑留在这里。实测 60 间 1.68 ms、500 间 14.8 ms，所以不再需要人为限流。
     *
     * @return array{done: int, skipped: int, ms: int, slow: bool}
     */
    public function restoreAll(Store $store): array
    {
        $pub = $this->publish;
        $mgr = $this;
        $rec = $this->records;
        $st = $this->store;
        $se = $this->settle;

        // 闭包里不碰 $this：它的绑定行为在 AOT 下没验证过，
        // 收集到本地数组再统一登记更稳
        $spawned = [];

        $restorer = new Restorer(50, 200);
        $stat = $restorer->restore(
            $store,
            "duel",
            function (Snapshot $snap) use ($pub, $mgr, $rec, $st, $se, &$spawned): bool {
                $duel = DuelRoom::fromArray($snap->get("duel"));
                if ($duel === null) {
                    return false;
                }

                $rid = $duel->room()->id();
                $addr = Scheduler::instance()->spawnOf(function () use ($rid, $duel, $pub, $mgr, $rec, $st, $se) {
                    return new DuelService($rid, $pub, $mgr, $rec, $st, $duel, $se);
                });

                $svc = Scheduler::instance()->serviceOf($addr);
                if (!($svc instanceof DuelService)) {
                    Scheduler::instance()->kill($addr);
                    return false;
                }

                $spawned[] = ["room" => $rid, "addr" => $addr, "uids" => $duel->room()->uids()];
                return true;
            }
        );

        foreach ($spawned as $s) {
            $addr = (int) $s["addr"];
            $rid = (int) $s["room"];
            $uids = $s["uids"];
            if (!is_array($uids)) {
                continue;
            }
            // 恢复 uid → 地址，玩家重连后能直接找回自己的局
            foreach ($uids as $uid) {
                $this->addrOf[(int) $uid] = $addr;
            }
            // 新房号必须避开已恢复的，否则会撞号覆盖。
            // 只在本节点段内推进：别的节点的号更大，不代表本节点该接着用
            if (RoomIds::node($rid) === $this->nodeId && $rid >= $this->nextRoomId) {
                $this->nextRoomId = RoomIds::compose($this->nodeId, RoomIds::seq($rid) + 1);
            }
        }

        if ($stat["skipped"] > 0) {
            Logger::warn("some snapshots were dropped", [
                "skipped" => $stat["skipped"],
                "done" => $stat["done"],
            ]);
        }
        if ($stat["slow"]) {
            Logger::warn("restore slow", ["ms" => $stat["ms"], "done" => $stat["done"]]);
        }
        return $stat;
    }

    /** 把客户端操作转给该玩家所在的房间 */
    public function forward(int $uid, int $msgId, array $payload): bool
    {
        $addr = isset($this->addrOf[$uid]) ? $this->addrOf[$uid] : 0;
        if ($addr === 0) {
            return false;
        }
        return Scheduler::instance()->deliver($addr, $msgId, $payload);
    }

    /** 玩家掉线 → 他所在的房间判他负 */
    public function forfeit(int $uid): bool
    {
        $addr = isset($this->addrOf[$uid]) ? $this->addrOf[$uid] : 0;
        if ($addr === 0) {
            return false;
        }
        return Scheduler::instance()->deliver($addr, DuelService::MSG_FORFEIT, ["uid" => $uid]);
    }

    /** 房间销毁时回调，摘掉该玩家的转发项 */
    public function clear(int $uid): void
    {
        unset($this->addrOf[$uid]);
    }

    /** 当前进行中的对局数 */
    public function count(): int
    {
        return intdiv(count($this->addrOf), 2);
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return [
            "duels" => intdiv(count($this->addrOf), 2),
            "created" => $this->created,
        ];
    }
}
