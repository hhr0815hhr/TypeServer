<?php

namespace Game\Domain;

/**
 * 掉落规则 —— AOT 版（基准实现）。
 *
 * 这份代码和 `scripts/loot.php` 是**同一条规则的两个实现**，存在的意义是：
 *
 *   1. 对照。同一个 RaidView 喂进去，两边必须输出完全相同的字符串。
 *      不一致说明"热更后的规则"和"你测过的规则"不是一回事。
 *   2. 基准。`bench.php` 拿它和脚本版比耗时，得出"这条规则放脚本要付多少"。
 *   3. 回退。脚本出问题（语法错、逻辑错）时，宿主可以切回这条路径，
 *      不用回滚整个版本。
 *
 * ## 什么时候该写在这里（AOT）
 *
 *   - 每帧都跑的（战斗判定、移动、AOI）
 *   - 一局几十万次的（伤害链、寻路）
 *   - 不能出错的（发钱、扣钱、库存）
 *
 * ## 什么时候该写成脚本
 *
 *   - 运营每周要改的（掉率、活动倍率、条件）
 *   - 每个玩家每分钟一两次的（领奖判定、任务进度）
 *
 * 判定线不是"重不重要"，是 **调用次数 × 单次成本**。见本工程 README 的对照表。
 */
final class LootAot
{
    /**
     * 结算一次掉落。
     *
     * 返回 `"itemId:count;itemId:count"`，空串表示什么都没掉。
     * 为什么用字符串而不是数组：脚本边界上数组的元素类型在 AOT 里要额外声明，
     * 而这里只有"几个 id + 几个数量"，字符串解析成本可以忽略（每局一次）。
     */
    public static function roll(RaidView $v): string
    {
        $kills = $v->kills();
        $luck = $v->luck();

        /** @var array<int, int> $counts */
        $counts = [];

        for ($i = 0; $i < $kills; $i++) {
            // 顺序即契约：脚本版必须按同样的顺序调 chance，否则随机序列错位，
            // 两边就"不是同一条规则"了。
            if ($v->chance(1, 25)) {
                $counts[3001] = ($counts[3001] ?? 0) + 1;
            }
            if ($v->chance(2, 50)) {
                $counts[3002] = ($counts[3002] ?? 0) + 1;
            }
            // 幸运值直接加在稀有掉率上，封顶 20%
            $rare = 2 + $luck;
            if ($rare > 20) {
                $rare = 20;
            }
            if ($v->chance(3, $rare)) {
                $counts[3003] = ($counts[3003] ?? 0) + 1;
            }
        }

        $out = "";
        foreach ($counts as $id => $n) {
            if ($out !== "") {
                $out = $out . ";";
            }
            $out = $out . $id . ":" . $n;
        }
        return $out;
    }
}
