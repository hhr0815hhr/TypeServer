<?php

/**
 * 牌型的规则测试。
 *
 * 这里没有 `use TSF\...`：Domain 不依赖框架，测试也就不需要框架。
 * 跑 `php85 tests/run.php` 就能验证，不用编译、不用起服务。
 */

use Ddz\Domain\Card;
use Ddz\Domain\Pattern;

require_once __DIR__ . "/../../src/Domain/Card.php";
require_once __DIR__ . "/../../src/Domain/Pattern.php";

/**
 * 按点数造一张牌。点数 3..15 是 3..2，16 小王，17 大王。
 * $i 是同点数里的第几张（0..3）。
 */
function c(int $rank, int $i = 0): int
{
    if ($rank === 16) {
        return Card::JOKER_SMALL;
    }
    if ($rank === 17) {
        return Card::JOKER_BIG;
    }
    return ($rank - 3) * 4 + $i;
}

$failed = 0;
$passed = 0;

// 多个测试文件共用 run.php 时，helper 会重复定义——用 function_exists 挡住
if (!function_exists("check")) {
    function check(bool $cond, string $name, string $extra = ""): void
    {
        global $failed, $passed;
        if ($cond) {
            $passed++;
            return;
        }
        $failed++;
        echo "  FAIL  " . $name . ($extra !== "" ? "  (" . $extra . ")" : "") . PHP_EOL;
    }
}

// ---------- 同点数 ----------
$p = Pattern::analyze([c(3)]);
check($p !== null && $p->type === Pattern::SINGLE && $p->mainRank === 3, "单张 3");

$p = Pattern::analyze([c(5, 0), c(5, 1)]);
check($p !== null && $p->type === Pattern::PAIR && $p->mainRank === 5, "对子 5");

$p = Pattern::analyze([c(7, 0), c(7, 1), c(7, 2)]);
check($p !== null && $p->type === Pattern::TRIPLE && $p->mainRank === 7, "三张 7");

// ---------- 带牌 ----------
$p = Pattern::analyze([c(9, 0), c(9, 1), c(9, 2), c(3)]);
check($p !== null && $p->type === Pattern::TRIPLE_ONE && $p->mainRank === 9, "三带一");

$p = Pattern::analyze([c(9, 0), c(9, 1), c(9, 2), c(3, 0), c(3, 1)]);
check($p !== null && $p->type === Pattern::TRIPLE_PAIR && $p->mainRank === 9, "三带二");

// ---------- 顺子 ----------
$p = Pattern::analyze([c(3), c(4), c(5), c(6), c(7)]);
check($p !== null && $p->type === Pattern::STRAIGHT && $p->mainRank === 7 && $p->length === 5, "顺子 3-7");

// 含 2 不能成顺（10 J Q K A 可以，J Q K A 2 不行）
$p = Pattern::analyze([c(11), c(12), c(13), c(14)]);
check($p === null, "四张不成顺子（长度不足）");

$p = Pattern::analyze([c(11), c(12), c(13), c(14), c(15)]);
check($p === null, "顺子不能含 2");

$p = Pattern::analyze([c(10), c(11), c(12), c(13), c(14)]);
check($p !== null && $p->type === Pattern::STRAIGHT && $p->mainRank === 14, "顺子到 A 是合法的");

// ---------- 连对 ----------
$p = Pattern::analyze([c(3, 0), c(3, 1), c(4, 0), c(4, 1), c(5, 0), c(5, 1)]);
check($p !== null && $p->type === Pattern::DOUBLE_STRAIGHT && $p->length === 3, "连对 334455");

// ---------- 飞机 ----------
$p = Pattern::analyze([c(3, 0), c(3, 1), c(3, 2), c(4, 0), c(4, 1), c(4, 2)]);
check($p !== null && $p->type === Pattern::PLANE && $p->length === 2, "飞机 333444");

$p = Pattern::analyze([
    c(3, 0), c(3, 1), c(3, 2),
    c(4, 0), c(4, 1), c(4, 2),
    c(9), c(10),
]);
check($p !== null && $p->type === Pattern::PLANE_ONE && $p->length === 2, "飞机带两单");

// 飞机带单：翅膀必须是单张，不能拿对子凑数
$p = Pattern::analyze([
    c(3, 0), c(3, 1), c(3, 2),
    c(4, 0), c(4, 1), c(4, 2),
    c(9, 0), c(9, 1),
]);
check($p === null, "333444+99 不是飞机带单（翅膀是对子）");

// 飞机带一对：t=2 需要两对
$p = Pattern::analyze([
    c(3, 0), c(3, 1), c(3, 2),
    c(4, 0), c(4, 1), c(4, 2),
    c(9, 0), c(9, 1), c(10, 0), c(10, 1),
]);
check($p !== null && $p->type === Pattern::PLANE_PAIR && $p->length === 2, "飞机带两对");

// ---------- 炸弹 / 王炸 ----------
$p = Pattern::analyze([c(6, 0), c(6, 1), c(6, 2), c(6, 3)]);
check($p !== null && $p->type === Pattern::BOMB && $p->mainRank === 6, "炸弹 6666");

$p = Pattern::analyze([c(16), c(17)]);
check($p !== null && $p->type === Pattern::ROCKET, "王炸");

// ---------- 四带二 ----------
$p = Pattern::analyze([c(8, 0), c(8, 1), c(8, 2), c(8, 3), c(3), c(4)]);
check($p !== null && $p->type === Pattern::FOUR_TWO && $p->mainRank === 8, "四带二");

// ---------- 比大小 ----------
$single5 = Pattern::analyze([c(5)]);
$single4 = Pattern::analyze([c(4)]);
check($single5 !== null && $single4 !== null && Pattern::beats($single5, $single4), "单 5 压单 4");
check(!Pattern::beats($single4, $single5), "单 4 压不了单 5");

$pair5 = Pattern::analyze([c(5, 0), c(5, 1)]);
check(!Pattern::beats($pair5, $single4), "对子不能压单张（跨型）");

$bomb = Pattern::analyze([c(3, 0), c(3, 1), c(3, 2), c(3, 3)]);
check($bomb !== null && Pattern::beats($bomb, $single5), "炸弹能压单张");

$rocket = Pattern::analyze([c(16), c(17)]);
check($rocket !== null && Pattern::beats($rocket, $bomb), "王炸压炸弹");
check(!Pattern::beats($bomb, $rocket), "炸弹压不了王炸");

$s5 = Pattern::analyze([c(3), c(4), c(5), c(6), c(7)]);
$s6 = Pattern::analyze([c(4), c(5), c(6), c(7), c(8), c(9)]);
check($s5 !== null && $s6 !== null && !Pattern::beats($s6, $s5), "6 张顺子压不了 5 张顺子");

// ---------- 洗牌与点数 ----------
$deck = Card::deck();
check(count($deck) === 54, "一副 54 张");
check(Card::rank(Card::JOKER_BIG) === 17, "大王点数 17");
check(Card::rank(0) === 3, "id 0 是 3 点");
check(Card::rank(48) === 15, "id 48 是 2 点");

if ($failed === 0) {
    echo "  PASS  " . $passed . " 项" . PHP_EOL;
}

return $failed === 0;
