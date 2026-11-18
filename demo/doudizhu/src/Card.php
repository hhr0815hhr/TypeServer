<?php

namespace Ddz;

/**
 * 一副 54 张牌的编号约定。
 *
 * 0..51：普通牌。`id >> 2` 得到 rank（0=3, 1=4 … 12=2），`id & 3` 是花色。
 * 52：小王；53：大王。
 *
 * 这样编号的好处是**比大小不需要查表**：rank 直接用整数比，王（52/53）天然大于
 * 任何普通牌，也天然等于自己的 rank。
 */
final class Card
{
    public const SMALL_JOKER = 52;
    public const BIG_JOKER = 53;
    public const COUNT = 54;

    /** 0=3 … 12=2；王返回 52/53 */
    public static function rankOf(int $id): int
    {
        if ($id >= self::SMALL_JOKER) {
            return $id;
        }
        return $id >> 2;
    }

    /** 顺子/连对/飞机允许的最大 rank：11 = A。2（12）和王不能进顺子 */
    public const MAX_SEQ_RANK = 11;

    public static function label(int $id): string
    {
        if ($id === self::SMALL_JOKER) {
            return "SJ";
        }
        if ($id === self::BIG_JOKER) {
            return "BJ";
        }
        $r = $id >> 2;
        $names = ["3", "4", "5", "6", "7", "8", "9", "10", "J", "Q", "K", "A", "2"];
        return $names[$r];
    }

    /**
     * 洗好的一副牌。
     *
     * 随机源由调用方传入（`$rand(int $n): int` 返回 [0, $n)）——**不用 mt_rand**：
     * 测试要可复现，线上要能换成更好的随机源，把随机性留在边界上。
     *
     * @param callable $rand
     * @return array<int, int>
     */
    public static function shuffled(callable $rand): array
    {
        $deck = [];
        $i = 0;
        while ($i < self::COUNT) {
            $deck[] = $i;
            $i++;
        }
        // Fisher-Yates，从后往前
        $k = self::COUNT - 1;
        while ($k > 0) {
            $j = $rand($k + 1);
            $t = $deck[$k];
            $deck[$k] = $deck[$j];
            $deck[$j] = $t;
            $k--;
        }
        return $deck;
    }
}
