<?php

namespace Ddz\Domain;

/**
 * 牌型：识别与比大小。
 *
 * 纯函数，不依赖任何东西。一局对战的正确性几乎全压在这里，
 * 所以它必须能被 `tests/unit/PatternTest.php` 在没有网络、没有编译的情况下验证。
 *
 * ## 简化说明（写在前面，避免误解）
 *
 * "飞机带翅膀"只支持**翅膀是单张**或**翅膀全是对子**两种。真实规则里
 * 翅膀本身可以带三张（比如 333444 + 555 当作三带）—— 那种组合需要枚举拆解，
 * 实现复杂但实战极少。这里不做，遇到时判为"不是合法牌型"，不会误判成能出。
 */
final class Pattern
{
    public const SINGLE = 1;

    public const PAIR = 2;

    public const TRIPLE = 3;

    public const TRIPLE_ONE = 4;

    public const TRIPLE_PAIR = 5;

    public const STRAIGHT = 6;

    public const DOUBLE_STRAIGHT = 7;

    public const PLANE = 8;

    public const PLANE_ONE = 9;

    public const PLANE_PAIR = 10;

    public const FOUR_TWO = 11;

    public const FOUR_TWO_PAIR = 12;

    public const BOMB = 13;

    public const ROCKET = 14;

    /** 顺子/连对/飞机最大只能到 A(14)，不能含 2(15) 和王 */
    private const MAX_STRAIGHT_RANK = 14;

    public int $type = 0;

    /** 比大小用的主点数：顺子取最大那张，三带取三张的点数 */
    public int $mainRank = 0;

    /** 顺子/连对/飞机的"节数"；其他牌型恒为 1 */
    public int $length = 1;

    public static function of(int $type, int $mainRank, int $length): Pattern
    {
        $p = new Pattern();
        $p->type = $type;
        $p->mainRank = $mainRank;
        $p->length = $length;
        return $p;
    }

    /**
     * 识别一手牌。不合法返回 null。
     *
     * @param int[] $cards
     */
    public static function analyze(array $cards): ?Pattern
    {
        $n = count($cards);
        if ($n === 0) {
            return null;
        }

        $g = Card::groupByRank($cards);
        $distinct = count($g);

        /** @var int[] $c1 单张的点数 */
        $c1 = [];
        /** @var int[] $c2 对子的点数 */
        $c2 = [];
        /** @var int[] $c3 三张的点数 */
        $c3 = [];
        /** @var int[] $c4 四张的点数 */
        $c4 = [];
        foreach ($g as $r => $list) {
            $k = count($list);
            if ($k === 1) {
                $c1[] = $r;
            } elseif ($k === 2) {
                $c2[] = $r;
            } elseif ($k === 3) {
                $c3[] = $r;
            } else {
                $c4[] = $r;
            }
        }

        // 王炸：两张王
        if ($n === 2 && isset($g[16]) && isset($g[17])) {
            return self::of(self::ROCKET, 17, 1);
        }

        // 炸弹：四张同点
        if ($n === 4 && count($c4) === 1) {
            return self::of(self::BOMB, $c4[0], 1);
        }

        // 同点数：单 / 对 / 三
        if ($distinct === 1) {
            if ($n === 1) {
                return self::of(self::SINGLE, $c1[0], 1);
            }
            if ($n === 2) {
                return self::of(self::PAIR, $c2[0], 1);
            }
            if ($n === 3) {
                return self::of(self::TRIPLE, $c3[0], 1);
            }
            return null;
        }

        // 三带一 / 三带二
        if (count($c3) === 1 && count($c1) === 1 && $n === 4) {
            return self::of(self::TRIPLE_ONE, $c3[0], 1);
        }
        if (count($c3) === 1 && count($c2) === 1 && $n === 5) {
            return self::of(self::TRIPLE_PAIR, $c3[0], 1);
        }

        // 四带二（四张 + 两单 / 两对）
        if (count($c4) === 1 && $n === 6 && count($c1) === 2) {
            return self::of(self::FOUR_TWO, $c4[0], 1);
        }
        if (count($c4) === 1 && $n === 8 && count($c2) === 2) {
            return self::of(self::FOUR_TWO_PAIR, $c4[0], 1);
        }

        // 顺子：全单张、连续、至少 5 张
        if ($distinct === $n && $n >= 5 && self::consecutive($c1)) {
            return self::of(self::STRAIGHT, $c1[count($c1) - 1], $n);
        }

        // 连对：全对子、连续、至少 3 对
        if (count($c2) === $distinct && $distinct * 2 === $n && $distinct >= 3 && self::consecutive($c2)) {
            return self::of(self::DOUBLE_STRAIGHT, $c2[count($c2) - 1], $distinct);
        }

        // 飞机（不带）：全三张、连续、至少 2 组
        $t = count($c3);
        if ($t === $distinct && $t * 3 === $n && $t >= 2 && self::consecutive($c3)) {
            return self::of(self::PLANE, $c3[$t - 1], $t);
        }

        // 飞机带单：t 组连续三张 + t 张**单牌**。
        //
        // 必须要求 c1 恰好有 t 个：只数"剩余张数够不够"会误判——333444 + 55
        // 总牌数 8、t=2、剩余 2 张，数量上凑得出"两个单张"，但 55 是对子，
        // 按规则不能拆成翅膀。这个误判会让"飞机带对"被当成"飞机带单"打出去
        // （大小一样但牌型不同，后续比对全乱）。
        if ($t >= 2 && $n === $t * 4 && count($c1) === $t && self::consecutive($c3)) {
            return self::of(self::PLANE_ONE, $c3[$t - 1], $t);
        }

        // 飞机带对：t 组连续三张 + t 个对子
        if ($t >= 2 && $n === $t * 5 && count($c2) === $t && count($c1) === 0 && self::consecutive($c3)) {
            return self::of(self::PLANE_PAIR, $c3[$t - 1], $t);
        }

        return null;
    }

    /**
     * 点数连续且不含 2 / 王。
     *
     * @param int[] $ranks 已按点数升序
     */
    private static function consecutive(array $ranks): bool
    {
        $n = count($ranks);
        if ($n < 2) {
            return false;
        }
        if ($ranks[$n - 1] > self::MAX_STRAIGHT_RANK) {
            return false;
        }
        for ($i = 1; $i < $n; $i++) {
            if ($ranks[$i] !== $ranks[$i - 1] + 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * $a 能不能压过 $b。
     *
     * 规则：王炸 > 炸弹 > 同型同长比主点数。不同型不同长一律不能压
     * （除了炸弹压普通牌，这是唯一允许"跨型"的情况）。
     */
    public static function beats(Pattern $a, Pattern $b): bool
    {
        if ($a->type === self::ROCKET) {
            return true;
        }
        if ($b->type === self::ROCKET) {
            return false;
        }
        if ($a->type === self::BOMB && $b->type !== self::BOMB) {
            return true;
        }
        if ($b->type === self::BOMB && $a->type !== self::BOMB) {
            return false;
        }
        if ($a->type !== $b->type || $a->length !== $b->length) {
            return false;
        }
        return $a->mainRank > $b->mainRank;
    }

    /** 中文名，日志和测试断言用 */
    public static function typeName(int $type): string
    {
        if ($type === self::SINGLE) {
            return "单张";
        }
        if ($type === self::PAIR) {
            return "对子";
        }
        if ($type === self::TRIPLE) {
            return "三张";
        }
        if ($type === self::TRIPLE_ONE) {
            return "三带一";
        }
        if ($type === self::TRIPLE_PAIR) {
            return "三带二";
        }
        if ($type === self::STRAIGHT) {
            return "顺子";
        }
        if ($type === self::DOUBLE_STRAIGHT) {
            return "连对";
        }
        if ($type === self::PLANE) {
            return "飞机";
        }
        if ($type === self::PLANE_ONE) {
            return "飞机带单";
        }
        if ($type === self::PLANE_PAIR) {
            return "飞机带对";
        }
        if ($type === self::FOUR_TWO) {
            return "四带二";
        }
        if ($type === self::FOUR_TWO_PAIR) {
            return "四带两对";
        }
        if ($type === self::BOMB) {
            return "炸弹";
        }
        if ($type === self::ROCKET) {
            return "王炸";
        }
        return "未知";
    }
}
