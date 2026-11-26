<?php

// 类型化视图的脚本侧。只能调方法，写不了属性（PlayerView 的属性是 private）。
return [
    // 读四个方法，折叠成一个数字便于断言
    "describe" => function ($v) {
        $n = $v->level() * 1000 + $v->gold() + $v->hpRatioPct();
        if ($v->hasBuff(7)) {
            $n = $n + 1;
        }
        return $n;
    },

    // 能力方法：判定在 AOT，脚本只决定"什么时候用"
    "canJoin" => function ($v) {
        return $v->canJoin(20) ? "yes" : "no";
    },

    // 试图写：View 没有 setter，属性又是 private，必须被拦下
    "tryWrite" => function ($v) {
        try {
            $v->level = 99;
            return "wrote";
        } catch (\Throwable $e) {
            return "blocked";
        }
    },
];
