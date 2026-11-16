<?php

/**
 * 统计工具。
 *
 * 只用中位数与 MAD（median absolute deviation），不用均值与标准差：
 * 性能采样里偶尔会出现一个被系统调度打断的离群点，均值会被它带偏，
 * 而 MAD 对离群值不敏感，适合这种分布。
 */

final class Stats
{
    /** @param array<int, float> $v */
    public static function median(array $v): float
    {
        $n = count($v);
        if ($n === 0) {
            return 0.0;
        }
        sort($v);
        $mid = (int) (($n - 1) / 2);
        if ($n % 2 === 1) {
            return (float) $v[$mid];
        }
        return ((float) $v[$mid] + (float) $v[$mid + 1]) / 2.0;
    }

    /** @param array<int, float> $v */
    public static function mad(array $v): float
    {
        $n = count($v);
        if ($n < 2) {
            return 0.0;
        }
        $m = self::median($v);
        $dev = [];
        foreach ($v as $x) {
            $dev[] = abs($x - $m);
        }
        return self::median($dev);
    }

    /**
     * 按 MAD 剔除离群值（|x - median| > k * 1.4826 * MAD）。
     * 1.4826 是把 MAD 折算成正态分布标准差的系数。
     *
     * @param array<int, float> $v
     * @return array{kept: array<int, float>, dropped: array<int, float>}
     */
    public static function rejectOutliers(array $v, float $k = 3.5): array
    {
        $n = count($v);
        if ($n < 4) {
            return ["kept" => $v, "dropped" => []];
        }
        $m = self::median($v);
        $sigma = 1.4826 * self::mad($v);
        if ($sigma <= 0.0) {
            return ["kept" => $v, "dropped" => []];
        }
        $kept = [];
        $dropped = [];
        foreach ($v as $x) {
            if (abs($x - $m) > $k * $sigma) {
                $dropped[] = $x;
            } else {
                $kept[] = $x;
            }
        }
        if (count($kept) === 0) {
            return ["kept" => $v, "dropped" => []];
        }
        return ["kept" => $kept, "dropped" => $dropped];
    }

    /**
     * 变异系数（离散度）：越大说明测量越不稳。
     * 用中位数与 MAD 折算：cv = 1.4826 * MAD / median
     *
     * @param array<int, float> $v
     */
    public static function cv(array $v): float
    {
        $m = self::median($v);
        if ($m <= 0.0) {
            return 0.0;
        }
        return 1.4826 * self::mad($v) / $m;
    }

    /** @param array<int, float> $v */
    public static function min(array $v): float
    {
        return count($v) === 0 ? 0.0 : (float) min($v);
    }

    /** @param array<int, float> $v */
    public static function max(array $v): float
    {
        return count($v) === 0 ? 0.0 : (float) max($v);
    }
}
