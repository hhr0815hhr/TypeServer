<?php

namespace Ddz\Domain;

/**
 * 一张牌用 0..53 的整数表示，不建对象。
 *
 * 为什么用 int 不用对象：一副牌 54 张，一局里频繁比较、排序、做 hash 计数。
 * int 的数组操作是 AOT 下最快的形式，而 `Card` 对象会带来 54 次分配 + 每张牌
 * 一个属性访问。牌是**值**，不是实体——它没有身份、没有生命周期。
 *
 * 编码：
 *   0..51  普通牌，`intdiv($id, 4)` 是点数序号（0=3点 … 12=2点），`$id % 4` 是花色
 *   52     小王
 *   53     大王
 *
 * 点数统一成 3..17：3..15 对应 3..2，16 小王，17 大王。这样"比大小"就是比 int，
 * 顺子里判断连续也只是 `$r + 1`。
 */
final class Card
{
    public const JOKER_SMALL = 52;

    public const JOKER_BIG = 53;

    /** 花色数：0 黑桃 1 红桃 2 梅花 3 方块（顺序无所谓，只用于显示） */
    public const SUITS = 4;

    /** 点数：3..15 是 3..2，16 小王，17 大王 */
    public static function rank(int $card): int
    {
        if ($card === self::JOKER_SMALL) {
            return 16;
        }
        if ($card === self::JOKER_BIG) {
            return 17;
        }
        return intdiv($card, self::SUITS) + 3;
    }

    /** 花色 0..3；王返回 -1 */
    public static function suit(int $card): int
    {
        if ($card >= self::JOKER_SMALL) {
            return -1;
        }
        return $card % self::SUITS;
    }

    /** 可读名，日志和测试用 */
    public static function name(int $card): string
    {
        if ($card === self::JOKER_SMALL) {
            return "小王";
        }
        if ($card === self::JOKER_BIG) {
            return "大王";
        }
        $names = ["3", "4", "5", "6", "7", "8", "9", "10", "J", "Q", "K", "A", "2"];
        return $names[self::rank($card) - 3];
    }

    /** 一整副（54 张，未洗） @return int[] */
    public static function deck(): array
    {
        $d = [];
        for ($i = 0; $i < 54; $i++) {
            $d[] = $i;
        }
        return $d;
    }

    /**
     * 洗牌。用 mt_srand 的全局随机——测试里 seed 固定就能复现。
     *
     * @param int[] $cards
     * @return int[]
     */
    public static function shuffle(array $cards): array
    {
        $n = count($cards);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $t = $cards[$i];
            $cards[$i] = $cards[$j];
            $cards[$j] = $t;
        }
        return $cards;
    }

    /**
     * 按点数分组：rank => [card, ...]，并按点数升序返回。
     *
     * 牌型识别每次都从这一步开始，所以单独提出来——
     * 分散在 Pattern 的每个分支里各数一遍，既慢又容易数错。
     *
     * @param int[] $cards
     * @return array<int, array<int, int>>
     */
    public static function groupByRank(array $cards): array
    {
        $g = [];
        foreach ($cards as $c) {
            $r = self::rank($c);
            if (!isset($g[$r])) {
                $g[$r] = [];
            }
            $g[$r][] = $c;
        }
        ksort($g);
        return $g;
    }

    /**
     * 按点数升序排好，同点数按花色。返回给客户端用。
     *
     * @param int[] $cards
     * @return int[]
     */
    public static function sort(array $cards): array
    {
        usort($cards, function (int $a, int $b): int {
            $ra = self::rank($a);
            $rb = self::rank($b);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return self::suit($a) <=> self::suit($b);
        });
        return $cards;
    }
}
