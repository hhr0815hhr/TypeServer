<?php

// 掉落规则 —— 脚本版（L2）。
//
// 与 src/Domain/LootAot.php 是同一条规则的两个实现。改这个文件 + GM reload
// 就能改变线上掉率，不用重编译、不用重启。
//
// ## 为什么这里能写数组和 foreach，而 AOT 侧要小心
//
// 脚本是内嵌 PHP 解释执行，不受 AOT 的类型推断限制；AOT 那边每个变量类型
// 在编译期定死。所以"表达力"确实是脚本强项——但代价是慢（见 README 的耗时表）。
//
// ## 契约（宿主依赖这三条，改动前先看 README）
//
//   1. 名字固定叫 roll，入参是 RaidView（只读视图）
//   2. 返回 "itemId:count;itemId:count"，空串 = 没掉落
//   3. 不要自己取随机数——随机源在 AOT 的 $v->chance()，才能回放与对账
//
// 违反第 3 条不会报错，但会让这个脚本和 AOT 版再也对不上，热更就失去意义。
return [
    "roll" => function ($v) {
        $kills = $v->kills();
        $luck = $v->luck();

        $counts = [];

        for ($i = 0; $i < $kills; $i++) {
            // 顺序即契约：必须和 LootAot::roll 一致
            if ($v->chance(1, 25)) {
                $counts[3001] = ($counts[3001] ?? 0) + 1;
            }
            if ($v->chance(2, 50)) {
                $counts[3002] = ($counts[3002] ?? 0) + 1;
            }
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
    },
];
