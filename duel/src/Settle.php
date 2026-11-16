<?php

namespace Duel;

use TSF\EventLog\EventLog;
use TSF\Kernel\Logger;

/**
 * 对局结算：一局只发一次奖，**不管中间崩多少次**。
 *
 * 这份代码示范的是「什么时候该用事件日志」：
 * 不是把对局记录搬过来（那是 db 的事——db 存"当前状态"，日志存"发生过的事"），
 * 而是**钱**。对局记录丢一条可以再打，金币多发一次就是数据事故。
 *
 * ## 幂等的锚点是业务主键，不是序号
 *
 * 每条事件的 `id` 用 `duel:<房间号>`。重放时同一个 id 只折叠一次，
 * 所以"重放任意多次"和"崩溃后重放"都不会多发钱。
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

    public const KIND = "duel.settled";

    private EventLog $log;

    private Wallet $wallet;

    /** @var array<string, true> 已经折叠过的业务 id */
    private array $seen = [];

    /** @var int 重放折叠了多少条事件（诊断用） */
    private int $replayed = 0;

    /** @var int 日志里折叠出的结算笔数 */
    private int $settled = 0;

    /** @var int 最近一次写入的事件序号 */
    private int $lastSeq = 0;

    /** @var int 日志里出现过的最大房间号（进程重启后用来避开已经结过账的号） */
    private int $maxRoom = 0;

    public function __construct(EventLog $log, ?Wallet $wallet = null)
    {
        $this->log = $log;
        $this->wallet = $wallet !== null ? $wallet : new Wallet();
        $this->rebuildFrom();
    }

    /**
     * 从日志重建余额与去重表。构造时自动调一次；这里公开是为了让测试能反复验证
     * "重放是幂等的"——连调两次余额不该翻倍。
     */
    public function rebuildFrom(int $fromSeq = 1): int
    {
        $this->wallet->reset();
        $this->seen = [];
        $this->replayed = 0;
        $this->settled = 0;
        $this->maxRoom = 0;
        foreach ($this->log->replay($fromSeq) as $ev) {
            $this->apply($ev);
        }
        return $this->replayed;
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

        if ($ev["k"] !== self::KIND) {
            return;   // 日志里将来会有别的事件类型，认不出来的跳过而不是报错
        }
        $this->settled++;
        $d = $ev["d"];
        $room = isset($d["room"]) ? (int) $d["room"] : 0;
        if ($room > $this->maxRoom) {
            $this->maxRoom = $room;
        }
        $winner = isset($d["winner"]) ? (int) $d["winner"] : 0;
        $amount = isset($d["amount"]) ? (int) $d["amount"] : 0;
        $this->wallet->credit($winner, $amount);
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
        $this->wallet->credit($winner, $amount);
        $this->lastSeq = $seq;
        return true;
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

    public function isSettled(int $roomId): bool
    {
        return isset($this->seen["duel:" . $roomId]);
    }

    /** @return array{replayed: int, settled: int, total: int, log: array} */
    public function stats(): array
    {
        return [
            "replayed" => $this->replayed,
            "settled" => $this->settled,
            "total" => $this->wallet->total(),
            "log" => $this->log->stats(),
        ];
    }
}
