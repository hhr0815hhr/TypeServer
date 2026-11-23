<?php
// 热更脚本。main 用来验证"改数值+热更"；daily 用来验证"脚本能不能真的做事"。
return [
    "main" => function ($ctx) {
        $ctx->addInt("gold", 100);
        return $ctx->getInt("gold");
    },
    // 活动玩法的最小闭环：读状态 → 判断 → 发奖 → 记日志。
    // 这些**全部**不需要重启进程，改脚本 reload 就生效。
    // 玩法型入口：读配置 → 挂号回调 → 通知别人。
    // 这三件事在没有能力绑定之前，脚本一样都做不到。
    // 跨 reload 的累计：状态存在宿主侧，脚本重新 require 也不会清零。
    // 活动"已发奖名单""本轮计数"就是这类东西。
    "counter" => function ($ctx) {
        return $ctx->addStateInt("hits", 1);
    },
    "activity" => function ($ctx) {
        if ($ctx->configInt("activity.enabled") === 0) {
            return 0;
        }
        $ctx->timer(30000, "onActivitySettle");
        $ctx->send("player:1001", 9001, "join");
        $ctx->log("activity joined, reward=" . $ctx->config("activity.reward"));
        return 1;
    },
    "daily" => function ($ctx) {
        $gold = $ctx->getInt("gold");
        $ctx->log("daily start gold=" . (string) $gold);
        if ($gold < 500) {
            $ctx->reward(350, "daily_first_win");
        }
        return $gold;
    },
];
