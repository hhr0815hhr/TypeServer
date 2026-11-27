<?php

// 活动规则 —— 脚本版（有副作用）。
//
// 和 loot.php 的区别：loot 只"判定"，不碰任何状态；这个脚本要"做事"——
// 发钱、挂号延时、写审计日志。所以它拿到的是 Context（带宿主授予的能力），
// 而不是只读视图。
//
// ## 这条边界比"脚本能干什么"更重要
//
//   判定（掉几件、够不够条件）  → 脚本，纯函数，返回结果
//   执行（加钱、扣钱、落库、推送）→ AOT，脚本只能**申请**，不能直接改
//
// 好处：脚本写错了最多"申请多了"，宿主还能拦（额度、频控、幂等）；
// 如果脚本能直接改钱包，一次写错就是资损，而且没有地方可以兜。
//
// ## 能力必须由宿主显式授予
//
// 下面用到的 reward / timer 都由 RaidService 在 onStart 里 bind。
// 没绑就调 → 当场抛错，而不是静默失败。理由见 Context 类注释：
// 静默成功等于"脚本能越过宿主做决策"，静默失败等于"活动悄悄不生效"。
use TSF\Script\Context;

return [
    // 每次结算调一次：活动期倍率加成 + 每 10 次击杀挂一个延时奖励
    "onKill" => function (Context $ctx) {
        // 开关关闭时什么都不做。默认值 1 表示"没配就是开"，
        // 但要在这里显式判断——运营常见的"临时关活动"就是改这一个值。
        if ($ctx->configInt("activity_enabled", 1) === 0) {
            return 0;
        }

        // 跨 reload 存活的计数：reload 不会把它清零，这是 State 的语义
        $n = $ctx->addStateInt("kills", 1);

        $mult = $ctx->configInt("activity_multiplier", 1);
        if ($mult > 1) {
            $base = $ctx->getInt("baseGold");
            $ctx->reward($base * ($mult - 1), "activity_multiplier");
        }

        if ($n % 10 === 0) {
            $delay = $ctx->configInt("milestone_delay_ms", 1200);
            $ctx->timer($delay, "onMilestone");
        }

        return $n;
    },

    // 延时回调：证明脚本能写"跨时间"的玩法，而不只是"这一瞬间"
    "onMilestone" => function (Context $ctx) {
        $ctx->reward(50, "milestone_10");
        $uid = $ctx->getInt("uid");
        $ctx->log("milestone: uid=" . $uid);
        return 1;
    },
];
