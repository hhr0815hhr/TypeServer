<?php

namespace Ddz;

/**
 * 牌型判定。
 *
 * 输入一组牌 id，判断它们是不是合法牌型、主牌 rank 是多少、连的长度。
 * 纯函数、无状态——用不上 Service，也就不该塞进 Service。
 *
 * **当前不支持飞机带牌**（`飞机带单`、`飞机带对`）：纯飞机已支持。
 * 带牌判定要处理"带的牌里又出现三张"这类歧义，留到后面单独做并配用例，
 * 现在先保证核心牌型的正确性。
 */
final class Pattern
{
    public const INVALID = 0;
    public const SINGLE = 1;
    public const PAIR = 2;
    public const TRIPLE = 3;
    public const TRIPLE_ONE = 4;
    public const TRIPLE_PAIR = 5;
    public const STRAIGHT = 6;
    public const STRAIGHT_PAIR = 7;
    public const PLANE = 8;
    public const BOMB = 9;
    public const ROCKET = 10;

    public int $type = self::INVALID;
    public int $rank = -1;
    public int $len = 1;
    public int $count = 0;

    public function name(): string
    {
        return match ($this->type) {
            self::SINGLE => "单张",
            self::PAIR => "对子",
            self::TRIPLE => "三张",
            self::TRIPLE_ONE => "三带一",
            self::TRIPLE_PAIR => "三带二",
            self::STRAIGHT => "顺子",
            self::STRAIGHT_PAIR => "连对",
            self::PLANE => "飞机",
            self::BOMB => "炸弹",
            self::ROCKET => "王炸",
            default => "非法",
        };
    }

    /** 能不能压过 $other（同类型同长度比 rank；炸弹压一切非炸弹；王炸最大） */
    public function beats(Pattern $other): bool
    {
        if ($this->type === self::INVALID) {
            return false;
        }
        if ($other->type === self::INVALID) {
            return true;   // 自由出牌，任何合法牌型都行
        }
        if ($this->type === self::ROCKET) {
            return true;
        }
        if ($other->type === self::ROCKET) {
            return false;
        }
        if ($this->type === self::BOMB && $other->type !== self::BOMB) {
            return true;
        }
        if ($other->type === self::BOMB && $this->type !== self::BOMB) {
            return false;
        }
        // 同类型才比：长度必须一致，rank 更大
        if ($this->type !== $other->type || $this->len !== $other->len) {
            return false;
        }
        return $this->rank > $other->rank;
    }

    /**
     * 解析一组牌。返回 null 表示不是合法牌型。
     *
     * 内部用 54 个槽的定长数组统计张数（索引即 rank），不用 `[rank => n]` 的哈希：
     * 后者的 key 在 AOT 里是 `php::Var`，赋给 int 变量会类型冲突；定长数组索引是明确的
     * int，也省掉哈希查找。
     *
     * @param array<int, int> $ids
     */
    public static function parse(array $ids): ?self
    {
        $count = count($ids);
        if ($count === 0) {
            return null;
        }

        $cnt = array_fill(0, Card::COUNT, 0);
        foreach ($ids as $id) {
            $r = Card::rankOf($id);
            $cnt[$r] = $cnt[$r] + 1;
        }

        $distinct = 0;
        $maxRank = -1;
        $i = 0;
        while ($i < Card::COUNT) {
            if ($cnt[$i] > 0) {
                $distinct++;
                $maxRank = $i;
            }
            $i++;
        }

        $p = new self();
        $p->count = $count;

        // 王炸
        if ($count === 2 && $cnt[Card::SMALL_JOKER] > 0 && $cnt[Card::BIG_JOKER] > 0) {
            $p->type = self::ROCKET;
            $p->rank = Card::BIG_JOKER;
            return $p;
        }

        // 同 rank：单 / 对 / 三 / 炸弹
        if ($distinct === 1) {
            $n = $cnt[$maxRank];
            if ($n === 1 || $n === 2 || $n === 3 || $n === 4) {
                $p->rank = $maxRank;
                if ($n === 1) {
                    $p->type = self::SINGLE;
                } elseif ($n === 2) {
                    $p->type = self::PAIR;
                } elseif ($n === 3) {
                    $p->type = self::TRIPLE;
                } else {
                    $p->type = self::BOMB;
                }
                return $p;
            }
            return null;
        }

        // 三带一 / 三带二
        //
        // 注意这里**不能 return null**：`333444`（飞机）在 distinct==2 时也会进这个分支，
        // 但它不是三带一/二。第一次写时在这里 return null，飞机就被吞掉了——
        // 分支的判断顺序本身就是这类 bug 的来源，所以只 return 命中，不命中的留给后面的分支。
        if ($distinct === 2) {
            $i = 0;
            while ($i < Card::COUNT) {
                if ($cnt[$i] === 3) {
                    $rest = $count - 3;
                    if ($rest === 1) {
                        $p->type = self::TRIPLE_ONE;
                        $p->rank = $i;
                        return $p;
                    }
                    if ($rest === 2) {
                        $p->type = self::TRIPLE_PAIR;
                        $p->rank = $i;
                        return $p;
                    }
                    break;   // 是三张但不是带一/带二 → 可能是飞机，交给后面的分支
                }
                $i++;
            }
        }

        // 顺子：5 张以上、全单张、连续、不到 2
        if ($distinct === $count && $count >= 5) {
            if (self::consecutive($cnt)) {
                $p->type = self::STRAIGHT;
                $p->rank = $maxRank;
                $p->len = $count;
                return $p;
            }
            return null;
        }

        // 连对：3 对以上、每对正好 2 张、连续、不到 2
        if ($distinct * 2 === $count && $distinct >= 3) {
            $ok = true;
            $i = 0;
            while ($i < Card::COUNT) {
                if ($cnt[$i] !== 0 && $cnt[$i] !== 2) {
                    $ok = false;
                    break;
                }
                $i++;
            }
            if ($ok && self::consecutive($cnt)) {
                $p->type = self::STRAIGHT_PAIR;
                $p->rank = $maxRank;
                $p->len = $distinct;
                return $p;
            }
            return null;
        }

        // 纯飞机：2 组以上连续的 3 张，且不带牌
        if ($distinct * 3 === $count && $distinct >= 2) {
            $ok = true;
            $i = 0;
            while ($i < Card::COUNT) {
                if ($cnt[$i] !== 0 && $cnt[$i] !== 3) {
                    $ok = false;
                    break;
                }
                $i++;
            }
            if ($ok && self::consecutive($cnt)) {
                $p->type = self::PLANE;
                $p->rank = $maxRank;
                $p->len = $distinct;
                return $p;
            }
            return null;
        }

        return null;
    }

    /** 出现过的 rank 必须连续，且都 <= A（12 是 2，不能进顺子/连对/飞机） */
    private static function consecutive(array $cnt): bool
    {
        $prev = -2;
        $i = 0;
        while ($i < Card::COUNT) {
            if ($cnt[$i] > 0) {
                if ($i > Card::MAX_SEQ_RANK) {
                    return false;
                }
                if ($prev !== -2 && $i !== $prev + 1) {
                    return false;
                }
                $prev = $i;
            }
            $i++;
        }
        return true;
    }
}
