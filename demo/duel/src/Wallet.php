<?php

namespace Duel;

/**
 * 金币余额——**派生状态，不是真相**。
 *
 * 真相是事件日志（`duel.settled` / `wallet.debit` / `wallet.credit`）。
 * 这个类只是"把日志折叠成一张表"的结果，丢了随时能从日志重建（`Settle::rebuildFrom()`）。
 *
 * 为什么不做成"先改余额再写日志"：那样崩溃后余额会领先于日志，
 * 重启时要么丢掉这笔钱（玩家投诉），要么把日志补一条（伪造历史）。
 * 反过来（日志先行）崩溃只会让余额落后，重放一次就追平，**没有第三种结局**。
 *
 * ## 扣款在这里只做"够不够"的判断，不做拒绝策略
 *
 * `debit()` 扣不动就返回 false，**不改账、不抛异常**。"余额不足"是业务结果，
 * 不是程序错误——把它做成异常会逼调用方用 try/catch 表达正常流程。
 * 真正该抛的是"日志写不进去"，那在 `Settle::debit()` 里。
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

    /**
     * 扣款。余额不足返回 false 且**什么都不改**。
     *
     * 不允许扣成负数：负数余额在游戏里没有意义，出现就说明上游算错了，
     * 与其让它悄悄变成"欠债"，不如当场拒绝。
     */
    public function debit(int $uid, int $amount): bool
    {
        if ($uid <= 0 || $amount <= 0) {
            return false;
        }
        $have = $this->balance[$uid] ?? 0;
        if ($have < $amount) {
            return false;
        }
        $this->balance[$uid] = $have - $amount;
        return true;
    }

    public function has(int $uid, int $amount): bool
    {
        return $amount > 0 && ($this->balance[$uid] ?? 0) >= $amount;
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

    /** @return array<int> 余额为负的 uid（正常情况下应当永远为空） */
    public function negative(): array
    {
        $out = [];
        foreach ($this->balance as $uid => $v) {
            if ($v < 0) {
                $out[] = $uid;
            }
        }
        return $out;
    }

    public function reset(): void
    {
        $this->balance = [];
    }
}
