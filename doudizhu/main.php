<?php

// 阶段 1：只验证牌型判定的正确性，还没接网络。
// 牌型是斗地主里最容易写错的部分，先把它测通再接流程。

use Ddz\Card;
use Ddz\Pattern;

function ids(string $spec): array
{
    // "3 3 3 4" 这样的写法转成牌 id；同 rank 自动分配花色
    $out = [];
    $used = [];
    $parts = explode(" ", trim($spec));
    foreach ($parts as $tok) {
        $rank = rankByName($tok);
        $n = $used[$rank] ?? 0;
        if ($rank === Card::SMALL_JOKER || $rank === Card::BIG_JOKER) {
            $out[] = $rank;
        } else {
            $out[] = $rank * 4 + $n;
        }
        $used[$rank] = $n + 1;
    }
    return $out;
}

function rankByName(string $t): int
{
    if ($t === "SJ") { return Card::SMALL_JOKER; }
    if ($t === "BJ") { return Card::BIG_JOKER; }
    $m = ["3" => 0, "4" => 1, "5" => 2, "6" => 3, "7" => 4, "8" => 5, "9" => 6,
          "10" => 7, "J" => 8, "Q" => 9, "K" => 10, "A" => 11, "2" => 12];
    return $m[$t] ?? -1;
}

function check(string $spec, int $expectType, string $desc): void
{
    $p = Pattern::parse(ids($spec));
    if ($p === null) {
        echo ($expectType === Pattern::INVALID ? "  OK   " : "  FAIL ") . $desc . "（判定为非法）" . PHP_EOL;
        return;
    }
    $ok = $p->type === $expectType;
    echo ($ok ? "  OK   " : "  FAIL ") . $desc . " → " . $p->name()
        . " rank=" . $p->rank . " len=" . $p->len . PHP_EOL;
}

function main(): void
{
    echo "=== 牌型判定 ===" . PHP_EOL;
    check("5", Pattern::SINGLE, "单张 5");
    check("5 5", Pattern::PAIR, "对 5");
    check("5 5 5", Pattern::TRIPLE, "三张 5");
    check("5 5 5 7", Pattern::TRIPLE_ONE, "三带一");
    check("5 5 5 7 7", Pattern::TRIPLE_PAIR, "三带二");
    check("3 4 5 6 7", Pattern::STRAIGHT, "顺子 34567");
    check("10 J Q K A", Pattern::STRAIGHT, "顺子 10JQKA");
    check("3 3 4 4 5 5", Pattern::STRAIGHT_PAIR, "连对 334455");
    check("3 3 3 4 4 4", Pattern::PLANE, "飞机 333444");
    check("7 7 7 7", Pattern::BOMB, "炸弹");
    check("SJ BJ", Pattern::ROCKET, "王炸");

    echo PHP_EOL . "=== 非法牌型（必须拒绝）===" . PHP_EOL;
    check("3 4", Pattern::INVALID, "3 4 不成对");
    check("3 4 5 6", Pattern::INVALID, "4 张顺子（不够 5）");
    check("2 3 4 5 6", Pattern::INVALID, "含 2 的顺子");
    check("J Q K A 2", Pattern::INVALID, "JQKA2 顺子（2 不能连）");
    check("3 3 4 4", Pattern::INVALID, "两对（连对至少要 3 对）");
    check("SJ BJ 3", Pattern::INVALID, "王炸带牌");
    check("3 4 5 6 8", Pattern::INVALID, "断档顺子");

    echo PHP_EOL . "=== 比大小 ===" . PHP_EOL;
    cmp("6", "5", true, "单 6 > 单 5");
    cmp("5", "6", false, "单 5 不压 单 6");
    cmp("3 3 3 3", "2 2", true, "炸弹压对子");
    cmp("SJ BJ", "3 3 3 3", true, "王炸压炸弹");
    cmp("4 5 6 7 8", "3 4 5 6 7", true, "顺子比最大牌");
    cmp("3 4 5 6 7", "4 5 6 7 8", false, "小顺子压不了大顺子");
    cmp("3 3 4 4 5 5", "3 4 5 6 7", false, "连对压不了顺子（类型不同）");
}

function cmp(string $a, string $b, bool $expect, string $desc): void
{
    $pa = Pattern::parse(ids($a));
    $pb = Pattern::parse(ids($b));
    if ($pa === null || $pb === null) {
        echo "  FAIL " . $desc . "（有非法牌型）" . PHP_EOL;
        return;
    }
    $got = $pa->beats($pb);
    echo ($got === $expect ? "  OK   " : "  FAIL ") . $desc . PHP_EOL;
}
