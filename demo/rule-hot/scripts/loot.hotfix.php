<?php

// 掉落规则 —— 待发布的修正版（灰度用）。
//
// 和 loot.php 的唯一差别是两条掉率：武器 25% → 55%，稀有 2% → 10%。
// 这正是运营最常见的需求："这周武器掉率太低，调一下"。
//
// 上线流程（run.sh 里有完整演示）：
//
//   1. registerAlt("loot", 2, "scripts/loot.hotfix.php")   ← 注册但不启用
//   2. GM: script.gray loot 5                              ← 5% 的 uid 走新版
//   3. 观察指标（掉落量、投诉、金币产出）
//   4. GM: script.gray loot 100                            ← 全量
//   5. 把 loot.hotfix.php 覆盖成 loot.php，reload，gray 归 0
//
// 第 1 步"注册不等于启用"是刻意的：配了灰度文件就自动生效，等于把
// "改文件"变成了"上线"，中间没有确认环节。
return [
    "roll" => function ($v) {
        $kills = $v->kills();
        $luck = $v->luck();

        $counts = [];

        for ($i = 0; $i < $kills; $i++) {
            if ($v->chance(1, 55)) {
                $counts[3001] = ($counts[3001] ?? 0) + 1;
            }
            if ($v->chance(2, 50)) {
                $counts[3002] = ($counts[3002] ?? 0) + 1;
            }
            $rare = 10 + $luck;
            if ($rare > 30) {
                $rare = 30;
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
