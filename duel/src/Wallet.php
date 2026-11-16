<?php

namespace Duel;

/**
 * 金币余额——**派生状态，不是真相**。
 *
 * 真相是事件日志里的 `duel.settled`。这个类只是"把日志折叠成一张表"的结果，
 * 丢了随时能从日志重建（`Settle::rebuildFrom()`）。
 *
 * 为什么不做成"先改余额再写日志"：那样崩溃后余额会领先于日志，
 * 重启时要么丢掉这笔钱（玩家投诉），要么把日志补一条（伪造历史）。
 * 反过来（日志先行）崩溃只会让余额落后，重放一次就追平，**没有第三种结局**。
 */
final class Wallet
{
    /** @var array<int, int> uid => 金币 */
    private array $balance = [];

    public function credit(int $uid, int $amount): void
    {
        if ($uid <= 0 || $amount <= 0) {
            return;
        }
        $this->balance[$uid] = ($this->balance[$uid] ?? 0) + $amount;
    }

    public function balance(int $uid): int
    {
        return $this->balance[$uid] ?? 0;
    }

    /** 所有有余额的玩家，按 uid 升序（对账/断言时顺序稳定才好比） */
    public function all(): array
    {
        $out = $this->balance;
        ksort($out);
        return $out;
    }

    public function total(): int
    {
        $sum = 0;
        foreach ($this->balance as $v) {
            $sum += $v;
        }
        return $sum;
    }

    public function reset(): void
    {
        $this->balance = [];
    }
}
