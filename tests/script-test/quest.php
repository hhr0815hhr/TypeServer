<?php
// 热更脚本。main 用来验证"改数值+热更"；daily 用来验证"脚本能不能真的做事"。
return [
    "main" => function ($ctx) {
        $ctx->addInt("gold", 100);
        return $ctx->getInt("gold");
    },
    // 活动玩法的最小闭环：读状态 → 判断 → 发奖 → 记日志。
    // 这些**全部**不需要重启进程，改脚本 reload 就生效。
    "daily" => function ($ctx) {
        $gold = $ctx->getInt("gold");
        $ctx->log("daily start gold=" . (string) $gold);
        if ($gold < 500) {
            $ctx->reward(350, "daily_first_win");
        }
        return $gold;
    },
];
