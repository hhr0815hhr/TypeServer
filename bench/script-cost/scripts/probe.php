<?php

// 被 AOT 宿主调用的脚本。必须 return 数组：PHP 不允许重复定义函数，
// 写成 `function xxx()` 第二次 reload 就 Cannot redeclare。

return [
    // 1. 空调用：隔离"跨边界本身"的成本
    "noop" => function ($obj) {
        return 1;
    },

    // 2. 三个字段读 + 一个写：白名单访问的典型形状
    "fields" => function ($ctx) {
        $a = $ctx->getInt("uid");
        $b = $ctx->getInt("lv");
        $c = $ctx->getInt("gold");
        $ctx->setInt("gold", $c + 1);
        return $a + $b + $c;
    },

    // 3. 十次方法调用：模拟类型化视图（P0-2 的目标形态）
    "view" => function ($o) {
        $s = 0;
        for ($i = 0; $i < 10; $i++) {
            $s += $o->level() + $o->gold() + $o->hpRatioPct() + $o->buffCount();
        }
        return $s;
    },

    // 4. 私有状态累加 100 次：热更不丢计数的那条路径
    "state" => function ($ctx) {
        $n = 0;
        for ($i = 0; $i < 100; $i++) {
            $n = $ctx->addStateInt("k", 1);
        }
        return $n;
    },
];
