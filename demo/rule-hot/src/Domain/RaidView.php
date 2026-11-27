<?php

namespace Game\Domain;

/**
 * 给脚本看的只读视图（AOT 侧，编译进产物）。
 *
 * ## 为什么是视图而不是直接把玩家/结算对象递过去
 *
 * 递对象 = 脚本能读写所有 public 字段，等于没有边界。视图只暴露方法：
 * 脚本能读状态、能问"A 这件事成不成"，但**写不了任何东西**。
 *
 * 实测依据（`bench/script-cost`）：方法调用约 45 ns，白名单字段访问约 410 ns。
 * 视图不只是更安全，它快 9 倍。
 *
 * ## 为什么随机数在 AOT 而不是脚本里 mt_rand
 *
 * 三个理由，按重要性排：
 *   1. **可回放**。同一 uid + 同一击杀数，重放得到同一份掉落——客诉、对账、
 *      回归都能对齐。脚本自己取随机数就永远对不上。
 *   2. **可测**。规则改成"稀有物掉率 10%"，测试可以断言"1000 次里 80~120 件"，
 *      而不是"大概能掉"。
 *   3. **边界清楚**。脚本负责"判定规则"（什么条件、多少概率），
 *      AOT 负责"不确定性的来源"。规则可以天天改，随机源不该跟着改。
 *
 * `chance()` 是**消耗式**的：每调一次 `seq` 加一，所以同一次结算里连续两次
 * 相同参数会得到不同结果，这是对的（两只怪不该同生同死）。顺序一旦变了，
 * 结果就变——脚本里不要写"先判定再回退重判"这种逻辑。
 */
final class RaidView
{
    private int $uid;

    private int $kills;

    private int $level;

    private int $luck;

    /** 本次结算内的第几次判定，参与散列，保证连续判定不相关 */
    private int $seq = 0;

    /**
     * 结算序号基线：由宿主传入"这是全服的第几次判定"。
     *
     * 为什么需要它：如果每次请求都从 0 开始，同一玩家的每一次结算会得到
     * **完全相同的判定序列**——现象是"他要么每次都掉、要么永远不掉"。
     * 第一版就是这样，200 次请求掉了 200 件，看着像掉率 100%。
     *
     * 基线必须来自 AOT 侧的持久计数（全服击杀数 / 玩家计数器 / 帧号），
     * 不能是脚本里的随机数——那样就没法回放和对账了。
     */
    private int $seqBase = 0;

    public function __construct(int $uid, int $kills, int $level, int $luck, int $seqBase = 0)
    {
        $this->uid = $uid;
        $this->kills = $kills;
        $this->level = $level;
        $this->luck = $luck;
        $this->seqBase = $seqBase;
    }

    public function uid(): int
    {
        return $this->uid;
    }

    /** 本次要结算多少只怪 */
    public function kills(): int
    {
        return $this->kills;
    }

    public function level(): int
    {
        return $this->level;
    }

    /** 幸运值：0 起步，每点加 1% 稀有掉率（示例规则，可被脚本改） */
    public function luck(): int
    {
        return $this->luck;
    }

    /**
     * 这次判定是否命中，`$pct` 是百分比（0–100）。
     *
     * AOT 下不要直接拿属性喂算术再喂内置函数，先取局部变量：
     * 属性访问在编译后是字段读取，混在表达式里容易触发类型推断问题。
     */
    public function chance(int $salt, int $pct): bool
    {
        $mod = $this->bucket($salt);
        return $mod < $pct;
    }

    /** 从 0..$count-1 里挑一个（用于"掉哪一件"这类选择） */
    public function pick(int $salt, int $count): int
    {
        if ($count <= 0) {
            return 0;
        }
        $mod = $this->bucket($salt);
        return $mod % $count;
    }

    /**
     * 0..99 的确定性散列。
     *
     * 用乘法散列而不是 `% 100`：`(uid + seq) % 100` 会让相邻 uid 的结果
     * 成段分布（1001–1100 落在同一段），灰度、掉率都会出现"按批次"的假象。
     * 常数取自 Knuth 的乘法散列，相邻输入会散到远处。
     */
    private function bucket(int $salt): int
    {
        $this->seq = $this->seq + 1;

        $seq = $this->seqBase + $this->seq;
        $uid = $this->uid;
        $kills = $this->kills;
        $luck = $this->luck;

        $h = $uid * 2654435761 + $kills * 40503 + $seq * 2246822519 + $salt * 374761393 + $luck * 97;
        $h = $h & 0x7fffffff;

        return $h % 100;
    }
}
