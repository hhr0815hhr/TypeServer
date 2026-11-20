<?php

namespace Duel;

use TSF\EventLog\Checkpoint;
use TSF\EventLog\EventLog;
use TSF\EventLog\MergedLog;
use TSF\Room\RoomIds;
use TSF\Kernel\Logger;

/**
 * 金币账本 + 对局结算。
 *
 * 名字叫 Settle 是因为它从"只发奖"长起来的；**现在它管的是整个金币账本**
 * （发奖、扣款、充值、对账）。改名会牵动 DuelManager / DuelService / 三个 bench，
 * 收益只是好看，所以留着，在这里说清楚。
 *
 * ## 为什么钱要进事件日志，而不是数据库
 *
 * db 存"当前状态"（可 UPDATE、可覆盖），日志存"发生过的事"（只追加）。
 * 对局记录丢一条可以再打，金币多发一次就是数据事故——所以钱走日志。
 *
 * ## 幂等的锚点是业务主键，不是序号
 *
 * 每条事件的 `id` 用业务主键：`duel:<房间号>`、`debit:<订单号>`、`credit:<来源>`。
 * 重放时同一个 id 只折叠一次，所以"重放任意多次"和"崩溃后重放"都不会重复记账。
 * 用事件序号做幂等是错的：崩溃时序号可能有洞（见 `EventLog::append()` 的注释）。
 *
 * ## 顺序：日志先行
 *
 * ```
 * append()  ← 先把"发生过的事"钉住（内部 fflush，扛得住 kill -9）
 * credit()  ← 再改派生状态
 * ```
 *
 * 反过来会在崩溃后留下"钱加了、账上没有"。日志先行则最坏只是"账上有、内存里还没加"，
 * 重启重放一次就追平。
 *
 * ## 扣款的三条规矩
 *
 * 1. **余额不足不写日志。** 拒绝的操作不是"发生过的事"，写进去会让对账永远不平。
 * 2. **同一笔不重复扣。** 幂等键是调用方给的 `$ref`（订单号/流水号），不是"扣了多少"。
 * 3. **扣成负数一律拒绝。** 负数余额在游戏里没有意义，出现就说明上游算错了。
 *
 * ## 不要在这里调 commit()
 *
 * `MODE_FLUSH` 下 `append()` 已经 `fflush` 过了；再调 `commit()` 会触发 **fsync**
 * （约 4 ms），等于把结算变成串行磁盘。真要抗"机器掉电"就用 `MODE_SYNC`，
 * 那时 `append()` 内部自己会 fsync。
 */
final class Settle
{
    /** 赢一局的金币 */
    public const REWARD = 100;

    /** 对局结算（历史事件：语义就是给 winner 加钱） */
    public const KIND = "duel.settled";

    /** 通用入账（充值、补偿、活动发放） */
    public const KIND_CREDIT = "wallet.credit";

    /** 出账（消费、扣费） */
    public const KIND_DEBIT = "wallet.debit";

    private EventLog $log;

    /**
     * 读侧日志：默认只有自己的那一份；多节点时传入全部节点的文件（见 `MergedLog`）。
     * 写永远只写 `$log`——房间归属保证同一个房间只由一个节点写，所以不需要多写者协调。
     *
     * @var array<EventLog>
     */
    private array $readers = [];

    private Wallet $wallet;

    /** @var array<string, true> 已经折叠过的业务 id */
    private array $seen = [];

    /** @var int 重放折叠了多少条事件（诊断用） */
    private int $replayed = 0;

    /** @var int 日志里折叠出的结算笔数 */
    private int $settled = 0;

    /** @var int 本次进程里的扣款笔数 */
    private int $debited = 0;

    /** @var int 最近一次写入的事件序号 */
    private int $lastSeq = 0;

    /** 检查点；null 表示不启用（每次都从头重放） */
    private ?Checkpoint $ckpt = null;

    /** 每折叠多少条压一次检查点 */
    private int $ckptEvery = 10000;

    private int $sinceCkpt = 0;

    /**
     * 检查点是否真的可用。
     *
     * 多节点（`MergedLog`）时为 false：检查点里的 seq 只是**自己那份日志**的坐标，
     * 拿它当全局水位会把别的节点还没折的事件跳过去——静默丢数据，比慢一点糟得多。
     */
    private bool $ckptActive = false;

    /** @var int 日志里出现过的最大房间号（进程重启后用来避开已经结过账的号） */
    private int $maxRoom = 0;

    /** @var array<int, true> 日志里出现过的房间号，按节点分段续号时要用 */
    private array $rooms = [];

    /**
     * @param array<EventLog> $readers 读侧日志（多节点时传全部节点的文件）。空 = 只读自己那份
     */
    public function __construct(
        EventLog $log,
        ?Wallet $wallet = null,
        array $readers = [],
        ?Checkpoint $ckpt = null,
        int $ckptEvery = 10000
    ) {
        $this->log = $log;
        $this->readers = $readers === [] ? [$log] : $readers;
        $this->wallet = $wallet !== null ? $wallet : new Wallet();
        $this->ckpt = $ckpt;
        $this->ckptEvery = $ckptEvery > 0 ? $ckptEvery : 10000;
        $this->ckptActive = $ckpt !== null && count($this->readers) === 1;

        if ($this->ckptActive && $ckpt !== null && $ckpt->load()) {
            // 有检查点：先恢复折叠结果，再只重放它之后的尾巴。
            // 注意不能 reset —— state 本身就是 reset 之后的起点
            $this->restoreState($ckpt->state());
            $this->rebuildFrom($ckpt->seq() + 1, false);
        } else {
            $this->rebuildFrom(1, true);
        }
    }

    /**
     * 读出待折叠的事件：单文件直接用，多文件走归并（顺序确定，见 `MergedLog`）。
     *
     * @return array<array{s: int, id: string, k: string, t: int, d: array}>
     */
    private function readEvents(int $fromSeq): array
    {
        if (count($this->readers) === 1) {
            return $this->readers[0]->replay($fromSeq);
        }
        return MergedLog::replay($this->readers, $fromSeq);
    }

    /**
     * 从日志重建余额与去重表。构造时自动调一次；这里公开是为了让测试能反复验证
     * "重放是幂等的"——连调两次余额不该翻倍。
     */
    public function rebuildFrom(int $fromSeq = 1, bool $reset = true): int
    {
        if ($reset) {
            $this->wallet->reset();
            $this->seen = [];
            $this->replayed = 0;
            $this->settled = 0;
            $this->debited = 0;
            $this->maxRoom = 0;
            $this->rooms = [];
        }
        foreach ($this->readEvents($fromSeq) as $ev) {
            $this->apply($ev);
        }
        return $this->replayed;
    }

    /**
     * 把当前折叠结果摊平成检查点能存的形状（`Checkpoint` 只收标量）。
     *
     * 前缀是约定不是装饰：`w:` 余额、`s:` 已折叠的业务 id、`r:` 出现过的房间号。
     * 塞进同一个扁平 map 是为了让检查点只有**一次** rename —— 分多段写就要保证
     * 多段同时生效，而原子性正是检查点最要紧的东西。
     *
     * @return array<string, int|string|float|bool>
     */
    public function exportState(): array
    {
        $out = [];
        $out["m"] = $this->maxRoom;
        $out["n"] = $this->settled;
        foreach ($this->wallet->all() as $uid => $bal) {
            $out["w:" . $uid] = $bal;
        }
        foreach ($this->seen as $id => $yes) {
            $out["s:" . $id] = 1;
        }
        foreach ($this->rooms as $room => $yes) {
            $out["r:" . $room] = 1;
        }
        return $out;
    }

    /** @param array<string, int|string|float|bool> $state */
    private function restoreState(array $state): void
    {
        $this->wallet->reset();
        $this->seen = [];
        $this->rooms = [];
        $this->maxRoom = 0;
        $this->settled = 0;
        $this->replayed = 0;
        $this->debited = 0;

        foreach ($state as $k => $v) {
            $key = (string) $k;
            if ($key === "m") {
                $this->maxRoom = is_int($v) ? $v : 0;
                continue;
            }
            if ($key === "n") {
                $this->settled = is_int($v) ? $v : 0;
                continue;
            }
            if (strpos($key, "w:") === 0) {
                $uid = (int) substr($key, 2);
                $bal = is_int($v) ? $v : 0;
                if ($uid > 0 && $bal !== 0) {
                    $this->wallet->credit($uid, $bal);
                }
                continue;
            }
            if (strpos($key, "s:") === 0) {
                $this->seen[substr($key, 2)] = true;
                continue;
            }
            if (strpos($key, "r:") === 0) {
                $this->rooms[(int) substr($key, 2)] = true;
            }
        }
    }

    /**
     * 够数就压一次检查点，顺手把老日志截掉。
     *
     * 截断的风险全在顺序上，而顺序由 `Checkpoint::saveAndPrune()` 固化（先写后截）。
     * 这里只管"什么时候压"：每 `ckptEvery` 条一次。压太勤会把 `seen` 表反复全量
     * 序列化变成写放大，压太懒则重启要重放很多——一万条是个折中。
     */
    private function maybeCheckpoint(): void
    {
        if (!$this->ckptActive || $this->ckpt === null) {
            return;
        }
        $this->sinceCkpt++;
        if ($this->sinceCkpt < $this->ckptEvery) {
            return;
        }
        $this->sinceCkpt = 0;
        $seq = $this->log->lastSeq();
        if ($seq <= 0) {
            return;
        }
        $kept = $this->ckpt->saveAndPrune($this->log, $seq, $this->exportState());
        if ($kept < 0) {
            // 检查点没写成就绝不截断（saveAndPrune 内部已保证），下次再试
            Logger::warn("settle: 检查点写入失败，跳过截断", ["seq" => $seq]);
        }
    }

    /** @param array{s: int, id: string, k: string, t: int, d: array} $ev */
    private function apply(array $ev): void
    {
        $id = (string) $ev["id"];
        if (isset($this->seen[$id])) {
            // 日志里同一个 id 出现两次：只可能是人工重放或故障注入。
            // 按"只算一次"折叠，但必须留痕——重复本身说明上游有问题
            Logger::warn("settle: 重复事件已忽略", [
                "id" => $id,
                "seq" => $ev["s"],
            ]);
            return;
        }
        $this->seen[$id] = true;
        $this->replayed++;

        $d = is_array($ev["d"]) ? $ev["d"] : [];

        if ($ev["k"] === self::KIND) {
            $this->settled++;
            $room = isset($d["room"]) ? (int) $d["room"] : 0;
            if ($room > $this->maxRoom) {
                $this->maxRoom = $room;
            }
            if ($room > 0) {
                $this->rooms[$room] = true;
            }
            $this->wallet->credit(
                isset($d["winner"]) ? (int) $d["winner"] : 0,
                isset($d["amount"]) ? (int) $d["amount"] : 0
            );
            return;
        }

        if ($ev["k"] === self::KIND_CREDIT) {
            $this->wallet->credit(
                isset($d["uid"]) ? (int) $d["uid"] : 0,
                isset($d["amount"]) ? (int) $d["amount"] : 0
            );
            return;
        }

        if ($ev["k"] === self::KIND_DEBIT) {
            $uid = isset($d["uid"]) ? (int) $d["uid"] : 0;
            $amount = isset($d["amount"]) ? (int) $d["amount"] : 0;
            if (!$this->wallet->debit($uid, $amount)) {
                // 日志里有一笔扣款，但按顺序重放时余额不够——说明日志被改过、
                // 或者扣款时没做余额检查。两种都是真问题，必须喊出来
                Logger::error("settle: 重放时扣款余额不足（日志可能被篡改）", [
                    "id" => $id,
                    "uid" => $uid,
                    "amount" => $amount,
                    "balance" => $this->wallet->balance($uid),
                ]);
            }
            return;
        }

        // 日志里将来会有别的事件类型，认不出来的跳过而不是报错
    }

    /**
     * 结算一局。返回是否**本次**真的发了奖。
     *
     * 重复调用、平局（`$winner <= 0`）、非法金额都返回 false 且不写日志。
     */
    public function settle(int $roomId, int $winner, int $amount = self::REWARD): bool
    {
        if ($roomId <= 0 || $winner <= 0 || $amount <= 0) {
            return false;
        }
        $id = "duel:" . $roomId;
        if (isset($this->seen[$id])) {
            return false;
        }

        $seq = $this->log->append($id, self::KIND, [
            "room" => $roomId,
            "winner" => $winner,
            "amount" => $amount,
        ]);

        // 先记在去重表里，再改余额：万一 credit 之后有人重入，
        // 也只会看到"已结算"，不会变成两条
        $this->seen[$id] = true;
        $this->settled++;
        if ($roomId > $this->maxRoom) {
            $this->maxRoom = $roomId;
        }
        $this->rooms[$roomId] = true;
        $this->wallet->credit($winner, $amount);
        $this->lastSeq = $seq;
        $this->maybeCheckpoint();
        return true;
    }

    /**
     * 扣款。返回是否**本次**真的扣了。
     *
     * 三种情况返回 false 且**不写日志**：参数非法、这笔 `$ref` 已经扣过、余额不足。
     * 日志写不进去会抛（`EventLog::append` 的异常直接往上冒）——那是环境问题，不是业务结果。
     *
     * `$ref` 是幂等键，必须是业务上全局唯一的东西（订单号、流水号、`shop:<订单>`），
     * **不要用 `uniqid()` 之类的随机值**：那样每次调用都是新键，重放时挡不住重复扣。
     */
    public function debit(int $uid, int $amount, string $ref): bool
    {
        if ($uid <= 0 || $amount <= 0 || $ref === "") {
            return false;
        }
        $id = "debit:" . $ref;
        if (isset($this->seen[$id])) {
            return false;
        }
        if (!$this->wallet->has($uid, $amount)) {
            return false;   // 余额不足：拒绝，且不落日志
        }

        $seq = $this->log->append($id, self::KIND_DEBIT, [
            "uid" => $uid,
            "amount" => $amount,
            "ref" => $ref,
        ]);

        $this->seen[$id] = true;
        $this->debited++;
        $this->lastSeq = $seq;
        if (!$this->wallet->debit($uid, $amount)) {
            // 上面刚 has() 过，这里失败只能是并发写坏了 wallet——真出问题要能被看见
            Logger::error("settle: 扣款在检查之后失败（状态不一致）", [
                "uid" => $uid,
                "amount" => $amount,
                "ref" => $ref,
            ]);
        }
        $this->maybeCheckpoint();
        return true;
    }

    /**
     * 通用入账（充值 / 补偿 / 活动发放）。幂等键同样是调用方给的 `$ref`。
     */
    public function credit(int $uid, int $amount, string $ref): bool
    {
        if ($uid <= 0 || $amount <= 0 || $ref === "") {
            return false;
        }
        $id = "credit:" . $ref;
        if (isset($this->seen[$id])) {
            return false;
        }

        $seq = $this->log->append($id, self::KIND_CREDIT, [
            "uid" => $uid,
            "amount" => $amount,
            "ref" => $ref,
        ]);

        $this->seen[$id] = true;
        $this->lastSeq = $seq;
        $this->wallet->credit($uid, $amount);
        $this->maybeCheckpoint();
        return true;
    }

    /**
     * 对账：**独立**把日志重放成一份新账本，和当前内存账本逐人比对。
     *
     * 关键在于"独立"——不调用 `rebuildFrom()`，因为那会顺手把当前账本**修正**掉，
     * 而我们要的是"差了多少"，不是"把它修好"。
     *
     * 能查出四类问题：
     * - 内存余额与日志不符（谁、内存里多少、日志里多少）
     * - 余额为负（`debit()` 挡住了，出现即说明有人绕过它改了 wallet）
     * - 日志里同一个业务 id 出现多次（重放会多算，虽然 fold 只算一次）
     * - 日志里有一笔扣款重放时余额不够（日志被改过 / 扣款时没查余额）
     *
     * @return array{ok: bool, events: int, players: int, diff: array,
     *               negative: array, dup_ids: array, bad_debits: array,
     *               total_mem: int, total_log: int}
     */
    public function reconcile(): array
    {
        $fresh = new Wallet();
        $seen = [];
        $dups = [];
        $badDebits = [];
        $events = 0;

        foreach ($this->readEvents(1) as $ev) {
            $events++;
            $id = (string) $ev["id"];
            if (isset($seen[$id])) {
                $dups[] = $id;
                continue;
            }
            $seen[$id] = true;
            $d = is_array($ev["d"]) ? $ev["d"] : [];

            if ($ev["k"] === self::KIND) {
                $fresh->credit(
                    isset($d["winner"]) ? (int) $d["winner"] : 0,
                    isset($d["amount"]) ? (int) $d["amount"] : 0
                );
            } elseif ($ev["k"] === self::KIND_CREDIT) {
                $fresh->credit(
                    isset($d["uid"]) ? (int) $d["uid"] : 0,
                    isset($d["amount"]) ? (int) $d["amount"] : 0
                );
            } elseif ($ev["k"] === self::KIND_DEBIT) {
                $uid = isset($d["uid"]) ? (int) $d["uid"] : 0;
                $amount = isset($d["amount"]) ? (int) $d["amount"] : 0;
                if (!$fresh->debit($uid, $amount)) {
                    $badDebits[] = $id;
                }
            }
        }

        $mem = $this->wallet->all();
        $log = $fresh->all();

        // 并集：两边任何人都要比。
        // 不用 `$mem + $log`（AOT 会把它放宽成 Var），也不复用 `$uid` 这个变量名
        // ——它在上面的 fold 里已经被推导成 `php::Int`，这里再绑 Var 类型的键就编译不过。
        $keys = [];
        foreach ($mem as $k => $v) {
            $keys[$k] = true;
        }
        foreach ($log as $k => $v) {
            $keys[$k] = true;
        }

        $diff = [];
        foreach ($keys as $k => $v) {
            $m = $mem[$k] ?? 0;
            $l = $log[$k] ?? 0;
            if ($m !== $l) {
                $diff[] = ["uid" => (int) $k, "mem" => $m, "log" => $l];
            }
        }

        $negative = $this->wallet->negative();
        $ok = $diff === [] && $negative === [] && $dups === [] && $badDebits === [];

        return [
            "ok" => $ok,
            "events" => $events,
            "players" => count($log),
            "diff" => $diff,
            "negative" => $negative,
            "dup_ids" => $dups,
            "bad_debits" => $badDebits,
            "total_mem" => $this->wallet->total(),
            "total_log" => $fresh->total(),
        ];
    }

    public function lastSeq(): int
    {
        return $this->lastSeq;
    }

    public function wallet(): Wallet
    {
        return $this->wallet;
    }

    public function balanceOf(int $uid): int
    {
        return $this->wallet->balance($uid);
    }

    /**
     * 日志里出现过的最大房间号。
     *
     * 给 `DuelManager` 用：房间号本身不持久化（每次启动从 1 数起），而结算幂等键是
     * `duel:<房间号>`——号一复用，新的一局就会被当成"已经结过账"直接跳过，
     * 钱静默不发。拿到这个上界，新房号就从它后面开始，永不复用已结算的号。
     */
    public function maxSettledRoom(): int
    {
        return $this->maxRoom;
    }

    /**
     * 本节点号段里出现过的最大房间号（没有则 0）。
     *
     * 和 `maxSettledRoom()` 的区别：那个是**全局**最大，只适合单写者；
     * 多节点时号是分段的（`RoomIds`），各自只关心自己那一段，否则节点 1 会从
     * 节点 0 的号后面接着数，白扔掉自己整段空间。
     */
    public function maxRoomIn(int $node): int
    {
        $max = 0;
        foreach ($this->rooms as $rid => $one) {
            if (RoomIds::node($rid) !== $node) {
                continue;
            }
            if ($rid > $max) {
                $max = $rid;
            }
        }
        return $max;
    }

    public function isSettled(int $roomId): bool
    {
        return isset($this->seen["duel:" . $roomId]);
    }

    /** 这笔 `$ref` 是否已经扣过（运维排查"为什么没扣/扣了两次"时用） */
    public function isDebited(string $ref): bool
    {
        return isset($this->seen["debit:" . $ref]);
    }

    /** @return array{replayed: int, settled: int, debited: int, total: int, log: array} */
    public function stats(): array
    {
        return [
            "replayed" => $this->replayed,
            "settled" => $this->settled,
            "debited" => $this->debited,
            "total" => $this->wallet->total(),
            "log" => $this->log->stats(),
        ];
    }
}
