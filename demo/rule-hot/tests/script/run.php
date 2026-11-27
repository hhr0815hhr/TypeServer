<?php

/**
 * 脚本层单测：**不需要 AOT 编译，也不需要起服务**。
 *
 *   php85 tests/script/run.php
 *
 * 为什么能这样跑：
 *   1. `src/Domain/` 下的类不依赖 TSF（骨架的第一条规矩），全是纯 PHP，
 *      原生 php85 直接 require 就能当脚本的 stub 视图；
 *   2. 脚本本身由 interp.so 加载，和线上是**同一份运行时**——
 *      不是"测试用另一套实现"，所以它验证的东西线上成立。
 *
 * 这填的是"脚本改完只能上线验"的洞。脚本是运营每周改的东西，
 * 没有秒级反馈的测试，等于每次改都在赌。
 *
 * 缺 interp 扩展时退出码 77（回归里算 SKIP，不算 FAIL）。
 */

use Game\Domain\LootAot;
use Game\Domain\RaidView;

if (!function_exists("interp_require_file")) {
    fwrite(STDERR, "SKIP 需要 ext/interp（PHP_INI_SCAN_DIR=<confdir> 或 php.ini 里 extension=interp.so）" . PHP_EOL);
    exit(77);
}

require __DIR__ . "/../../src/Domain/RaidView.php";
require __DIR__ . "/../../src/Domain/LootAot.php";

$pass = 0;
$fail = 0;

$ok = function (bool $cond, string $name, string $extra = "") use (&$pass, &$fail): void {
    if ($cond) {
        $pass++;
        echo "  PASS  " . $name . ($extra !== "" ? "  (" . $extra . ")" : "") . PHP_EOL;
    } else {
        $fail++;
        echo "  FAIL  " . $name . ($extra !== "" ? "  (" . $extra . ")" : "") . PHP_EOL;
    }
};

// ---- 1. 加载 ----
$path = __DIR__ . "/../../scripts/loot.php";
$e = interp_require_file($path);
$ok(is_array($e), "加载 loot.php");
if (!is_array($e)) {
    echo PHP_EOL . "passed=0 failed=1" . PHP_EOL;
    exit(1);
}
$ok(isset($e["roll"]), "导出表里有 roll 入口");

$roll = $e["roll"];

// ---- 2. 对拍：脚本和 AOT 必须逐字节一致 ----
// 同 uid、同 kills、同 seqBase，两边的判定序列完全对齐。
// 这条是整套热更的前提：不一致说明"热更后的规则"和"你测过的规则"不是一回事。
$mismatch = 0;
$sample = "";
for ($uid = 1001; $uid <= 1100; $uid++) {
    $base = $uid * 3;
    $a = LootAot::roll(new RaidView($uid, 10, 5, 0, $base));
    $b = $roll(new RaidView($uid, 10, 5, 0, $base));
    if (!is_string($b) || $a !== $b) {
        $mismatch++;
        if ($sample === "") {
            $sample = "uid=" . $uid . " aot=" . $a . " script=" . (is_string($b) ? $b : "(非字符串)");
        }
    }
}
$ok($mismatch === 0, "100 组 AOT vs 脚本逐字节一致", $sample === "" ? "100/100" : $sample);

// ---- 3. 掉率落在区间内 ----
// 规则：武器 25% / 材料 50% / 稀有 2%（+luck）。2000 只怪，
// 用区间而不是精确值——随机源的协议是"统计性质"，不是"每 4 只必掉 1"。
$kills = 2000;
$totalItems = 0;
$weapon = 0;
for ($i = 0; $i < $kills; $i++) {
    $spec = $roll(new RaidView(2000 + $i, 1, 5, 0, $i * 3));
    if (!is_string($spec) || $spec === "") {
        continue;
    }
    foreach (explode(";", $spec) as $one) {
        $kv = explode(":", $one);
        if (count($kv) !== 2) {
            continue;
        }
        $id = (int) $kv[0];
        $n = (int) $kv[1];
        $totalItems = $totalItems + $n;
        if ($id === 3001) {
            $weapon = $weapon + $n;
        }
    }
}

$weaponPct = intdiv($weapon * 1000, $kills);      // 千分数
$totalPerKill = intdiv($totalItems * 100, $kills); // 每百只的件数

$ok($weaponPct >= 200 && $weaponPct <= 300, "武器掉率在 20%–30%", "实际 " . intdiv($weaponPct, 10) . "." . ($weaponPct % 10) . "%");
$ok($totalPerKill >= 60 && $totalPerKill <= 95, "总掉落 0.60–0.95 件/只", "实际 " . intdiv($totalPerKill, 100) . "." . (intdiv($totalPerKill, 10) % 10) . intdiv($totalPerKill, 1) % 10);

// ---- 4. 边界 ----
$empty = $roll(new RaidView(1001, 0, 5, 0, 0));
$ok($empty === "", "kills=0 返回空串");

$wrote = "no";
$v = new RaidView(1001, 1, 5, 0, 0);
try {
    $v->kills = 99;
} catch (\Throwable $x) {
    $wrote = "blocked";
}
$ok($wrote === "blocked", "视图属性写不了（private + 无 setter）");

echo PHP_EOL . "passed=" . $pass . " failed=" . $fail . PHP_EOL;
exit($fail === 0 ? 0 : 1);
