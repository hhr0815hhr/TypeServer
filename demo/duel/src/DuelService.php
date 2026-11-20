<?php

namespace Duel;

use TSF\Db\Repo;
use TSF\FrameLoop\FrameLoop;
use TSF\Kernel\Logger;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;
use TSF\Snapshot\Snapshot;
use TSF\Snapshot\Store;

/**
 * 把 DuelRoom 挂到 Service 上：帧驱动 + 消息分发 + 向客户端推送。
 *
 * 帧率和推送频率刻意解耦：
 *   判定跑 20 Hz（50 ms）—— 攻击距离、移动都靠它才准；
 *   状态推 5 Hz（每 4 帧）—— 客户端不需要 20 Hz 的完整快照，带宽是实打实的。
 */
final class DuelService extends Service
{
    public const MSG_TICK = 5401;

    public const MSG_MOVE = 5402;

    public const MSG_ATTACK = 5403;

    /** 结算后延迟关闭 */
    public const MSG_CLOSE = 5404;

    /** 掉线判负（由 DuelManager 转发） */
    public const MSG_FORFEIT = 5405;

    /**
     * 崩溃现场：这局打到哪了。
     *
     * 没有它，崩溃日志只有"哪个 msgId 出错"；有它才能回答"这局是不是快结算了、
     * 谁快死了、回放能不能对上"。字段只放排障真正会看的，别把整个对象序列化进去。
     */
    public function dumpState(): string
    {
        // 用公开方法而不是直接读字段：hp 是 private，且"当前打到哪"本来就该由
        // DuelRoom 自己回答（它的 snapshot() 就是给持久化用的那份数据）
        $snap = [
            "room" => $this->duel->room()->id(),
            "hp" => $this->duel->snapshot()["hp"] ?? [],
            "elapsedMs" => $this->duel->elapsedMs(),
            "winner" => $this->duel->winner(),
        ];
        $out = json_encode($snap, JSON_UNESCAPED_UNICODE);
        return is_string($out) ? $out : "";
    }

    private DuelRoom $duel;

    private FrameLoop $loop;

    /** @var \Closure|null (int $uid, int $msgId, array $payload): void */
    private ?\Closure $publish;

    private ?DuelManager $manager;

    private int $frame = 0;

    private bool $reported = false;

    /** 对局记录仓储；null 表示这个示例不落库（比如纯跑协议测试时） */
    private ?Repo $records;

    /** 金币结算；null 表示不启用（纯规则测试不需要钱） */
    private ?Settle $settle;

    /**
     * 本局最近一次**玩家操作**的链路 id。
     *
     * 结算是由定时器 tick 触发的，那条消息的 trace 是 0——如果直接用当前 trace，
     * 账上永远拿不到"这笔钱是哪次操作的结果"。所以房间自己记一条：
     * 谁最后动过，结算就归到那条链路上。
     */
    private int $lastTrace = 0;

    /** 快照存储；null 表示不存快照 */
    private ?Store $store;

    /** 每多少帧存一次快照（20 Hz × 20 帧 = 1 秒） */
    private const SNAPSHOT_EVERY = 20;

    public function __construct(
        int $roomId,
        ?\Closure $publish = null,
        ?DuelManager $manager = null,
        ?Repo $records = null,
        ?Store $store = null,
        ?DuelRoom $duel = null,
        ?Settle $settle = null
    ) {
        // $duel 传入表示"从快照恢复"。恢复时必须用现成的 Room，
        // 重新 new 一个会丢掉成员顺序（冷哈希就对不上了）
        $this->duel = $duel !== null ? $duel : new DuelRoom($roomId);
        $this->publish = $publish;
        $this->manager = $manager;
        $this->records = $records;
        $this->store = $store;
        $this->settle = $settle;
        $this->loop = new FrameLoop($this, self::MSG_TICK, 50, 200);
    }

    public function duel(): DuelRoom
    {
        return $this->duel;
    }

    public function onStart(): void
    {
        $this->loop->start();
    }

    public function onStop(): void
    {
        // 正常收摊（kill 由房间自己调）→ 快照没用了。注意：进程被 kill -9 时
        // 这个回调不会执行，快照会留下来，正是重启恢复需要的
        $this->dropSnapshot();

        // 先取 uid 列表再 close()。`Room::close()` 现在**不清成员表**（只置标志并走
        // close 边），所以顺序其实无所谓；但保持"先取快照再关"仍然是更稳的写法——
        // 它不依赖 close 的实现细节，将来真要清成员也不会漏掉这张转发表。
        // （漏掉的后果：uid → 地址的转发项永远留着，那人从此匹配不上。）
        $uids = $this->duel->room()->uids();
        $this->duel->room()->close();
        if ($this->manager === null) {
            return;
        }
        foreach ($uids as $uid) {
            $this->manager->clear($uid);
        }
    }

    public function onMessage(Message $m): void
    {
        // 记下"这局最近一次玩家操作"的链路，结算时用它（见 $lastTrace 注释）
        if ($m->traceId > 0) {
            $this->lastTrace = $m->traceId;
        }
        // 先取局部变量再判断：直接对 $m->msgId 做 switch 会踩 AOT 的 Variant 转换坑
        $msgId = $m->msgId;

        if ($msgId === self::MSG_TICK) {
            $this->onTick();
            return;
        }

        if ($msgId === self::MSG_CLOSE) {
            Scheduler::instance()->kill($this->addr());
            return;
        }

        if ($msgId === self::MSG_FORFEIT) {
            $d = $m->data;
            if (!is_array($d)) {
                return;
            }
            $uid = isset($d["uid"]) ? (int) $d["uid"] : 0;
            $this->duel->forfeit($uid);
            return;
        }

        if ($msgId === self::MSG_MOVE) {
            $d = $m->data;
            if (!is_array($d)) {
                return;
            }
            $uid = isset($d["uid"]) ? (int) $d["uid"] : 0;
            $dir = isset($d["dir"]) ? (int) $d["dir"] : 0;
            $this->duel->setDir($uid, $dir);
            return;
        }

        if ($msgId === self::MSG_ATTACK) {
            $d = $m->data;
            if (!is_array($d)) {
                return;
            }
            $uid = isset($d["uid"]) ? (int) $d["uid"] : 0;
            $this->duel->attack($uid, Scheduler::nowMs());
        }
    }

    private function onTick(): void
    {
        $delta = $this->loop->beginFrame();
        $this->duel->tick($delta);
        $this->frame++;

        if ($this->frame % 4 === 0) {
            $this->broadcast(Msg::STATE, $this->duel->snapshot());
        }

        if ($this->duel->isSettled() && !$this->reported) {
            $this->reported = true;
            $this->broadcast(Msg::RESULT, [
                "winner" => $this->duel->winner(),
                "room" => $this->duel->room()->id(),
            ]);
            // 发奖放在落库之前：钱是"丢了就是事故"的那一类，先把它钉住。
            // 一局只发一次由 Settle 按 `duel:<房间号>` 保证——重放、崩溃重启都算数
            $this->settleMatch();
            $this->recordMatch();
            // 结算后立刻存一次快照：万一在下面那 3 秒里崩了，重启时能恢复成
            // "已结算"而不是"打到一半"，玩家不会看到一局诈尸的对战
            $this->saveSnapshot();
            // 留 3 秒让客户端看到结算，然后收摊
            $this->timeout(3000, self::MSG_CLOSE);
        }

        if ($this->store !== null && $this->frame % self::SNAPSHOT_EVERY === 0) {
            $this->saveSnapshot();
        }

        $this->loop->endFrame();
    }

    /**
     * 存一份快照。
     *
     * 失败只记日志：快照是"下次崩了能救回来"的保险，不是这一帧的正确性前提。
     * 和落库失败的处理态度一致——非核心路径的持久化不该影响正在进行的对局。
     */
    private function saveSnapshot(): void
    {
        $store = $this->store;
        if ($store === null) {
            return;
        }
        $roomId = $this->duel->room()->id();
        try {
            $snap = new Snapshot("duel", $roomId);
            $snap->put("duel", $this->duel->toArray());
            $store->put($snap);
        } catch (\Throwable $e) {
            Logger::warn("snapshot failed", ["room" => $roomId, "err" => $e->getMessage()]);
        }
    }

    /** 房间正常收摊时删掉快照——否则重启会把一个已经结束的对局又拉起来 */
    private function dropSnapshot(): void
    {
        $store = $this->store;
        if ($store === null) {
            return;
        }
        $roomId = $this->duel->room()->id();
        try {
            $store->delete("duel", $roomId);
        } catch (\Throwable $e) {
            Logger::warn("snapshot delete failed", ["room" => $roomId, "err" => $e->getMessage()]);
        }
    }

    /**
     * 落一条对局记录。
     *
     * 关键点：**落库失败绝不能让对局崩**。数据库抖一下、连接断了、表被误删，
     * 最坏情况是丢一条战绩，而不是让正在结算的玩家掉线。所以这里吞掉异常留日志。
     * 游戏服里所有「非核心路径」的持久化都该是这个态度。
     */
    private function recordMatch(): void
    {
        $repo = $this->records;
        if ($repo === null) {
            return;
        }

        $duel = $this->duel;
        $winner = $duel->winner();

        try {
            $rec = new DuelRecord();
            $rec->room = $duel->room()->id();
            $rec->winner = $winner;
            $rec->loser = 0;
            $rec->winnerHp = 0;
            if ($winner > 0) {
                $rec->loser = $duel->foeOf($winner);
                $rec->winnerHp = $duel->hpOf($winner);
            }
            $rec->frames = $this->frame;
            $rec->endedAt = time();
            $repo->insert($rec);
        } catch (\Throwable $e) {
            Logger::warn("duel record failed", [
                "room" => $duel->room()->id(),
                "err" => $e->getMessage(),
            ]);
        }
    }

    /**
     * 金币结算。
     *
     * 和 `recordMatch()` 的态度**刻意相反**：落库失败可以吞（丢一条战绩而已），
     * 结算失败必须抛——钱的事不能静默降级。
     *
     * **抛出去会发生什么**（写在这里，因为这个后果不直观）：
     * `Scheduler` 对服务抛异常的处理是「记 error 日志 → `kill($addr)`」
     * （见 `Service/Scheduler.php` 里 `catch` 那段）。所以事件日志写失败
     * （磁盘满、句柄失效、目录只读）会导致：
     *
     *   1. 本房间服务被销毁，玩家直接掉线（不会走 `MSG_CLOSE` 的正常收摊）；
     *   2. 这一局的战绩也不会写——`recordMatch()` 排在 `settleMatch()` 之后；
     *   3. 已经播出去的 `RESULT` 里那个赢家拿不到钱。
     *
     * 这个组合是"钱优先"的必然结果，不是 bug；但它是**有代价的选择**：
     * 一次磁盘抖动会换掉一局玩家的体验。如果要更温和，就在调用处 catch，
     * 记 error、广播一条失败、再正常收摊——那属于产品决策，不该由工具悄悄替业务定。
     *
     * 幂等由 `Settle` 保证：`duel:<房间号>` 是业务主键，一局只有一条事件，
     * 所以"这一帧重复进到这里"（比如快照恢复后 `$reported` 被重置）不会多发钱。
     */
    private function settleMatch(): void
    {
        $settle = $this->settle;
        if ($settle === null) {
            return;
        }
        $duel = $this->duel;
        $winner = $duel->winner();
        // 切到"本局最后一次玩家操作"的链路，让发出去的钱能对回那次操作
        $sched = Scheduler::instance();
        $prev = $sched->currentTrace();
        if ($this->lastTrace > 0) {
            $sched->setTrace($this->lastTrace);
        }
        $settle->settle($duel->room()->id(), $winner, Settle::REWARD);
        $sched->setTrace($prev);
    }

    private function broadcast(int $msgId, array $payload): void
    {
        $cb = $this->publish;
        if ($cb === null) {
            return;
        }
        // 推给成员，不需要坐标——所以走 members() 而不是 EntitySet
        foreach ($this->duel->room()->uids() as $uid) {
            $cb($uid, $msgId, $payload);
        }
    }
}
