<?php

namespace Game\App;

use TSF\Kernel\Logger;

/**
 * 玩家钱包（AOT 侧，脚本碰不到）。
 *
 * 存在的理由不是"演示用"，而是把**资损边界**摆出来：
 *
 *   脚本说"应该掉 2 把武器" → 走 LootAot 的解析 → Wallet::addItem
 *   脚本说"再奖 10 金币"     → 走 Context 的 reward 能力 → Wallet::addGold
 *
 * 两条路都收敛到这一个类。脚本能给的最多是一个"申请"，能不能加、加多少、
 * 记什么审计，全在这里。要加额度上限、频控、幂等键，也只改这里。
 *
 * 真实项目里这个类会连数据库/事件日志；demo 放内存，够验证链路。
 */
final class Wallet
{
    /** @var array<int, int> itemId => 数量 */
    private array $items = [];

    private int $gold = 0;

    private int $rewardCalls = 0;

    /** @var array<string, int> reason => 次数（审计用，看钱是谁批的） */
    private array $reasons = [];

    /**
     * 加金币。`reason` 必填——线上出现"金币对不上"时，第一个问题永远是
     * "这笔是谁批的"。能力层强制带原因，比事后翻日志便宜。
     */
    public function addGold(int $amount, string $reason): void
    {
        if ($amount <= 0) {
            return;
        }
        $this->gold = $this->gold + $amount;
        $this->rewardCalls = $this->rewardCalls + 1;
        $this->reasons[$reason] = ($this->reasons[$reason] ?? 0) + 1;
    }

    /**
     * 按 `"3001:2;3002:1"` 加物品。
     *
     * 解析放在 AOT 而不是脚本里返回数组：脚本返回什么形状都要在这里收窄一次，
     * 一个地方收窄比每个调用点各自判断便宜，也不会漏。
     */
    public function addSpec(string $spec): void
    {
        if ($spec === "") {
            return;
        }
        $parts = explode(";", $spec);
        foreach ($parts as $one) {
            if ($one === "") {
                continue;
            }
            $kv = explode(":", $one);
            if (count($kv) !== 2) {
                Logger::warn("wallet: 非法的掉落串", ["spec" => $one]);
                continue;
            }
            $id = (int) $kv[0];
            $n = (int) $kv[1];
            if ($id <= 0 || $n <= 0) {
                continue;
            }
            $this->items[$id] = ($this->items[$id] ?? 0) + $n;
        }
    }

    public function gold(): int
    {
        return $this->gold;
    }

    /** 物品总数（不改动内部状态，供回包与断言使用） */
    public function itemCount(): int
    {
        $n = 0;
        foreach ($this->items as $id => $c) {
            $n = $n + $c;
        }
        return $n;
    }

    public function itemOf(int $id): int
    {
        return $this->items[$id] ?? 0;
    }

    /** 一行摘要：`3001=12 3002=48 3003=3 gold=2100` */
    public function summary(): string
    {
        $out = "";
        foreach ($this->items as $id => $c) {
            if ($out !== "") {
                $out = $out . " ";
            }
            $out = $out . $id . "=" . $c;
        }
        if ($out === "") {
            $out = "no-item";
        }
        return $out . " gold=" . $this->gold;
    }

    /** 审计：每笔钱的原因与次数 */
    public function reasons(): string
    {
        $out = "";
        foreach ($this->reasons as $r => $c) {
            if ($out !== "") {
                $out = $out . ",";
            }
            $out = $out . $r . ":" . $c;
        }
        return $out;
    }

    public function rewardCalls(): int
    {
        return $this->rewardCalls;
    }
}
